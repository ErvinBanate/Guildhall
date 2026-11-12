<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\GuildServices;
use App\Services\EnergyLog;
use App\Services\EnergyImport;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class OcPermissions
{
    private const DEPOSIT_REQUIREMENT = 300;

    private function character(Request $request): string
    {
        $user = $request->session()->get('discord_user');
        abort_unless(in_array($user['role'], ['officer', 'admin'], true), 403);
        $character = DB::table('verifications')->where('discord_user_id', $user['id'])
            ->where('status', 'approved')->value('player_name');
        abort_unless($character !== null, 403, 'An officer-approved character is required.');

        return $character;
    }

    public function index(Request $request, GuildServices $services)
    {
        $character = $this->character($request);

        return Inertia::render($request->routeIs('oc.exemptions') ? 'OcExemptions' : 'OcPermissions', [
            'username' => $request->session()->get('discord_user.name'),
            'character' => $character,
            'requirement' => self::DEPOSIT_REQUIREMENT,
            'data' => Inertia::defer(function () use ($services) {
                $rosterAvailable = true;
                try {
                    $roster = $services->roster();
                } catch (\Illuminate\Http\Client\HttpClientException | \Symfony\Component\HttpKernel\Exception\HttpException) {
                    $roster = [];
                    $rosterAvailable = false;
                }
                $accounts = DB::table('energy_accounts')->where('guild_id', config('guildhall.albion_guild'))
                    ->orderBy('player_name')->get();
                $members = collect($roster)->keyBy('id')->map(fn ($member) => $member + ['account' => null]);
                foreach ($accounts as $account) {
                    $key = $account->player_id ?? 'account:'.$account->id;
                    $members[$key] = [
                        'id' => $key,
                        'unconfirmed' => $account->player_id === null || !isset($members[$key]),
                        'name' => $members[$key]['name'] ?? $account->player_name,
                        'account' => [
                            'id' => $account->id,
                            'balance' => (int) $account->balance,
                            'deposits' => (int) $account->deposits,
                            'qualified' => (bool) $account->qualified_before || $account->deposits >= self::DEPOSIT_REQUIREMENT,
                            'permission' => $account->permission_granted === null ? null : (bool) $account->permission_granted,
                            'revision' => $account->revision,
                        ],
                    ];
                }
                $exemptions = DB::table('energy_exemptions')->where('guild_id', config('guildhall.albion_guild'))->get();
                foreach ($exemptions as $exemption) {
                    $memberKey = str_starts_with($exemption->subject_key, 'player:')
                        ? substr($exemption->subject_key, 7) : $exemption->subject_key;
                    $entry = $members[$memberKey] ?? [
                        'id' => $memberKey, 'name' => $exemption->player_name, 'unconfirmed' => true, 'account' => null,
                    ];
                    $entry['exemption'] = [
                        'role' => $exemption->role, 'active' => (bool) $exemption->active,
                        'revision' => (int) $exemption->revision, 'note' => $exemption->note,
                    ];
                    $members[$memberKey] = $entry;
                }
                $exemptionHistory = DB::table('energy_exemption_events as e')
                    ->join('energy_exemptions as x', 'x.id', '=', 'e.exemption_id')
                    ->where('x.guild_id', config('guildhall.albion_guild'))
                    ->orderByDesc('e.created_at')->orderByDesc('e.id')->limit(30)
                    ->get(['e.id', 'x.player_name as player', 'e.role', 'e.active', 'e.note', 'e.officer_id', 'e.created_at']);
                $transactions = DB::table('energy_transactions as t')->join('energy_accounts as a', 'a.id', '=', 't.account_id')
                    ->where('a.guild_id', config('guildhall.albion_guild'))->orderByDesc('t.created_at')
                    ->orderByDesc('t.id')->limit(50)->get(['t.id', 'a.player_name as player', 't.kind', 't.amount', 't.note', 't.created_at', 't.occurred_at']);

                return ['members' => $members->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values(),
                    'rosterAvailable' => $rosterAvailable, 'transactions' => $transactions, 'exemptionHistory' => $exemptionHistory];
            }),
        ]);
    }

    public function setExemption(Request $request, GuildServices $services)
    {
        $this->character($request);
        $input = $request->validate([
            'id' => ['required', 'uuid'],
            'memberId' => ['required', 'string', 'max:64'],
            'role' => ['required', Rule::in(['mentor', 'officer', 'guild_master', 'none'])],
            'revision' => ['required', 'integer', 'min:0', 'max:4294967294'],
            'note' => ['required', 'string', 'max:300'],
        ]);
        $guild = config('guildhall.albion_guild');
        $subject = $input['memberId'];
        if (str_starts_with($subject, 'account:')) {
            $account = DB::table('energy_accounts')->where('guild_id', $guild)->where('id', substr($subject, 8))->first();
            abort_unless($account, 404);
            $subject = $account->player_id !== null ? 'player:'.$account->player_id : 'account:'.$account->id;
            $name = $account->player_name;
        } else {
            abort_unless(preg_match('/^[A-Za-z0-9_-]{22}$/D', $subject), 422);
            $previous = DB::table('energy_exemptions')->where('guild_id', $guild)->where('subject_key', 'player:'.$subject)->first();
            if ($previous) {
                $name = $previous->player_name;
            } else {
                $member = collect($services->roster())->firstWhere('id', $subject);
                abort_unless($member, 422, 'Select a current roster character or an existing ledger account.');
                $name = $member['name'];
            }
            $subject = 'player:'.$subject;
        }
        $officer = $request->session()->get('discord_user.id');
        try {
            DB::transaction(function () use ($guild, $subject, $name, $officer, $input) {
                $existing = DB::table('energy_exemptions')->where('guild_id', $guild)
                    ->where('subject_key', $subject)->lockForUpdate()->first();
                $event = DB::table('energy_exemption_events')->where('id', $input['id'])->first();
                if ($event) {
                    abort_unless($existing && $event->exemption_id === $existing->id
                        && $event->officer_id === $officer && $event->role === $input['role']
                        && $event->note === trim($input['note']), 409);
                    return;
                }
                abort_unless(($existing ? (int) $existing->revision : 0) === (int) $input['revision'], 409,
                    'This exemption changed. Refresh before recording another decision.');
                abort_if(!$existing && $input['role'] === 'none', 422, 'This character has no exemption to remove.');
                $id = $existing?->id ?? (string) Str::uuid();
                $values = [
                    'role' => $input['role'], 'active' => $input['role'] !== 'none',
                    'revision' => (int) $input['revision'] + 1, 'note' => trim($input['note']),
                    'updated_at' => now(),
                ];
                if ($existing) DB::table('energy_exemptions')->where('id', $id)->update($values);
                else DB::table('energy_exemptions')->insert($values + [
                    'id' => $id, 'guild_id' => $guild, 'subject_key' => $subject,
                    'player_name' => $name, 'created_at' => now(),
                ]);
                DB::table('energy_exemption_events')->insert([
                    'id' => $input['id'], 'exemption_id' => $id, 'officer_id' => $officer,
                    'role' => $input['role'], 'active' => $input['role'] !== 'none',
                    'note' => trim($input['note']), 'created_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Another officer changed this exemption. Refresh and try again.');
        }

        return response()->json(['saved' => true]);
    }

    public function importLog(Request $request, GuildServices $services, EnergyLog $parser, EnergyImport $importer)
    {
        $this->character($request);
        abort_if(strlen($request->getContent()) > 3_000_000, 413);
        $input = $request->validate([
            'text' => ['required', 'string', 'max:2000000'],
            'dateOrder' => ['required', Rule::in(['dmy', 'mdy'])],
            'cutoff' => ['required', 'string', 'max:40'],
            'initialize' => ['required', 'boolean'],
            'commit' => ['required', 'boolean'],
            'token' => ['required_if:commit,true', 'nullable', 'string', 'size:64'],
            'acknowledge' => ['required_if:commit,true', 'boolean'],
        ]);
        $cutoff = $parser->timestamp($input['cutoff'], $input['dateOrder']);
        abort_unless($cutoff !== null, 422, 'Enter a valid UTC balance cutoff.');
        $lines = preg_split('/\r\n|\n|\r/', $input['text']);
        abort_if(count($lines) > 5001, 422, 'Import at most 5,000 log rows at once.');
        $parsed = $parser->parse($input['text'], $input['dateOrder'], $cutoff);
        try {
            $roster = $services->roster();
        } catch (\Illuminate\Http\Client\HttpClientException) {
            abort(503, 'Albion roster verification is unavailable. Try the import again later.');
        }
        $guild = config('guildhall.albion_guild');
        $officer = $request->session()->get('discord_user.id');
        $payloadHash = hash('sha256', json_encode([$input['text'], $input['dateOrder'], $cutoff, (bool) $input['initialize']], JSON_THROW_ON_ERROR));
        $query = fn () => DB::table('energy_accounts')->where('guild_id', $guild)->orderBy('player_id');
        if (!$input['commit']) {
            $plan = $importer->plan($parsed, $roster, $query()->get(), (bool) $input['initialize']);

            return response()->json([
                'token' => $importer->token($plan, $payloadHash, $officer),
                'added' => $plan['added'], 'duplicates' => $plan['duplicates'],
                'beforeCutoff' => $plan['beforeCutoff'], 'net' => $plan['net'], 'errors' => $plan['errors'],
                'accounts' => array_values(array_map(fn ($change) => [
                    'name' => $change['name'], 'before' => $change['before'], 'after' => $change['balance'],
                    'newAccount' => $change['accountId'] === null,
                    'unconfirmed' => $change['playerId'] === null,
                ], $plan['changes'])),
            ]);
        }
        abort_unless($input['acknowledge'] ?? false, 422, 'Confirm the cutoff and opening balances before importing.');
        try {
            $result = DB::transaction(function () use ($parsed, $roster, $query, $input, $importer, $payloadHash, $officer, $guild, $cutoff) {
                $plan = $importer->plan($parsed, $roster, $query()->lockForUpdate()->get(), (bool) $input['initialize']);
                abort_if(count($plan['errors']) > 0, 422, 'Fix the rejected rows before importing.');
                // A retry after a successful atomic import has only duplicate or excluded rows.
                if ($plan['added'] === 0) return ['imported' => 0, 'duplicates' => $plan['duplicates']];
                abort_unless(hash_equals($importer->token($plan, $payloadHash, $officer), $input['token'] ?? ''), 409,
                    'The accounts or log changed. Preview again before importing.');
                foreach ($plan['changes'] as $change) {
                    $accountId = $change['accountId'] ?? (string) Str::uuid();
                    if ($change['accountId'] === null) {
                        DB::table('energy_accounts')->insert([
                            'id' => $accountId, 'guild_id' => $guild, 'player_id' => $change['playerId'],
                            'unlinked_name_key' => $change['unlinkedNameKey'],
                            'player_name' => $change['name'], 'balance' => $change['balance'],
                            'deposits' => $change['deposits'], 'revision' => 1, 'qualified_before' => false,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        DB::table('energy_transactions')->insert([
                            'id' => (string) Str::uuid(), 'account_id' => $accountId, 'kind' => 'opening', 'amount' => 0,
                            'note' => 'Officer confirmed zero baseline at '.$cutoff, 'officer_id' => $officer, 'created_at' => now(),
                        ]);
                    } else {
                        DB::table('energy_accounts')->where('id', $accountId)->update([
                            'balance' => $change['balance'], 'deposits' => $change['deposits'],
                            'revision' => $change['revision'] + 1, 'updated_at' => now(),
                        ]);
                    }
                    foreach ($change['rows'] as $row) {
                        DB::table('energy_transactions')->insert([
                            'id' => (string) Str::uuid(), 'account_id' => $accountId,
                            'kind' => $row['kind'], 'amount' => $row['amount'],
                            'source_key' => $row['sourceKey'], 'occurred_at' => $row['at'],
                            'note' => 'Imported game energy log at '.$row['at'],
                            'officer_id' => $officer, 'created_at' => now(),
                        ]);
                    }
                }
                return ['imported' => $plan['added'], 'duplicates' => $plan['duplicates']];
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Another officer imported overlapping records. Preview again.');
        }

        return response()->json($result);
    }

    public function record(Request $request, GuildServices $services)
    {
        $this->character($request);
        $input = $request->validate([
            'id' => ['required', 'uuid'],
            'playerId' => ['required_without:accountId', 'nullable', 'regex:/^[A-Za-z0-9_-]{22}$/D'],
            'accountId' => ['sometimes', 'nullable', 'uuid'],
            'kind' => ['required', Rule::in(['opening', 'deposit', 'withdrawal', 'permission_granted', 'permission_revoked'])],
            'amount' => ['required', 'integer', 'between:-1000000000,1000000000'],
            'revision' => ['required', 'integer', 'min:0'],
            'qualifiedBefore' => ['required', 'boolean'],
            'note' => ['required', 'string', 'max:300'],
        ]);
        $amount = (int) $input['amount'];
        $permission = in_array($input['kind'], ['permission_granted', 'permission_revoked'], true);
        abort_if($permission && $amount !== 0, 422, 'Permission records must have a zero amount.');
        abort_if(in_array($input['kind'], ['deposit', 'withdrawal'], true) && $amount <= 0, 422, 'Enter a positive transaction amount.');
        abort_if($input['kind'] !== 'opening' && $input['qualifiedBefore'], 422, 'Prior qualification belongs on the opening record.');
        $guildId = config('guildhall.albion_guild');
        $officer = $request->session()->get('discord_user.id');
        $accountIdInput = $input['accountId'] ?? null;
        $playerIdInput = $input['playerId'] ?? null;
        // Only an opening record requires the live roster; existing accounts remain auditable after a member leaves.
        $member = null;
        if ($input['kind'] === 'opening') {
            abort_if($accountIdInput !== null, 422, 'This account already has an opening balance.');
            $member = collect($services->roster())->firstWhere('id', $playerIdInput);
            abort_unless($member, 422, 'Select a current Conscripted character.');
        }
        try {
            DB::transaction(function () use ($input, $amount, $permission, $guildId, $officer, $member, $accountIdInput, $playerIdInput) {
                $previous = DB::table('energy_transactions')->where('id', $input['id'])->first();
                if ($previous) {
                    $owner = DB::table('energy_accounts')->where('id', $previous->account_id)->first();
                    $sameAccount = $accountIdInput !== null ? $owner?->id === $accountIdInput : $owner?->player_id === $playerIdInput;
                    abort_unless($owner && $owner->guild_id === $guildId && $sameAccount
                        && $previous->officer_id === $officer && $previous->kind === $input['kind']
                        && (int) $previous->amount === $amount && $previous->note === trim($input['note'])
                        && (bool) $previous->qualified_before === (bool) $input['qualifiedBefore'], 409);
                    return;
                }
                $query = DB::table('energy_accounts')->where('guild_id', $guildId);
                $query = $accountIdInput !== null ? $query->where('id', $accountIdInput) : $query->where('player_id', $playerIdInput);
                $account = $query->lockForUpdate()->first();
                if ($input['kind'] === 'opening') {
                    abort_if($account || (int) $input['revision'] !== 0, 409, 'An opening balance already exists.');
                    $accountId = (string) Str::uuid();
                    DB::table('energy_accounts')->insert([
                        'id' => $accountId, 'guild_id' => $guildId, 'player_id' => $playerIdInput,
                        'player_name' => $member['name'], 'balance' => $amount,
                        'deposits' => 0, 'qualified_before' => (bool) $input['qualifiedBefore'],
                        'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                } else {
                    abort_unless($account, 422, 'Record a verified opening balance first.');
                    abort_unless((int) $account->revision === (int) $input['revision'], 409, 'This account changed. Refresh before recording another action.');
                    $accountId = $account->id;
                    $changes = ['revision' => $account->revision + 1, 'updated_at' => now()];
                    if ($permission) {
                        $subject = $account->player_id !== null ? 'player:'.$account->player_id : 'account:'.$account->id;
                        $exempt = DB::table('energy_exemptions')->where('guild_id', $guildId)
                            ->where('subject_key', $subject)->lockForUpdate()->value('active');
                        abort_if($input['kind'] === 'permission_granted'
                            && !$exempt && !$account->qualified_before && $account->deposits < self::DEPOSIT_REQUIREMENT,
                            422, 'The one-time 300-energy deposit requirement has not been verified.');
                        $changes['permission_granted'] = $input['kind'] === 'permission_granted';
                    } else {
                        $changes['balance'] = $account->balance + ($input['kind'] === 'deposit' ? $amount : -$amount);
                        abort_if(abs($changes['balance']) > 9007199254740991, 422, 'Balance exceeds the supported range.');
                        if ($input['kind'] === 'deposit') {
                            $changes['deposits'] = $account->deposits + $amount;
                            abort_if($changes['deposits'] > 9007199254740991, 422);
                        }
                    }
                    DB::table('energy_accounts')->where('id', $accountId)->update($changes);
                }
                DB::table('energy_transactions')->insert([
                    'id' => $input['id'], 'account_id' => $accountId, 'kind' => $input['kind'],
                    'amount' => $amount, 'qualified_before' => (bool) $input['qualifiedBefore'],
                    'note' => trim($input['note']), 'officer_id' => $officer, 'created_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Another officer recorded this account. Refresh and check the recent activity.');
        }

        return response()->json(['saved' => true]);
    }
}
