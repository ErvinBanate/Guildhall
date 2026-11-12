<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\GuildServices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;

final class Verification
{
    public function index(Request $request)
    {
        $user = $request->session()->get('discord_user');
        $ticket = DB::table('verifications')->where('discord_user_id', $user['id'])->first();

        return Inertia::render('Guildhall', [
            'username' => $user['name'],
            'officer' => in_array($user['role'], ['officer', 'admin'], true),
            'ticket' => $ticket ? [
                'name' => $ticket->player_name,
                'status' => $ticket->status,
                'url' => 'https://discord.com/channels/'.config('guildhall.guild').'/'.$ticket->channel_id,
            ] : null,
        ]);
    }

    public function choose(Request $request, GuildServices $services)
    {
        $user = $request->session()->get('discord_user');

        return Inertia::render('Choose', [
            'roster' => $services->roster(),
            'username' => $user['name'],
            'character' => $this->approvedCharacter($user['id']),
            'officer' => in_array($user['role'], ['officer', 'admin'], true),
        ]);
    }

    private function approvedCharacter(string $discordId): ?string
    {
        return $this->approvedTicket($discordId)?->player_name;
    }

    private function approvedTicket(string $discordId): ?object
    {
        return DB::table('verifications')->where('discord_user_id', $discordId)
            ->where('status', 'approved')->first(['player_id', 'player_name']);
    }

    public function characters(Request $request, GuildServices $services)
    {
        $user = $request->session()->get('discord_user');
        $ticket = $this->approvedTicket($user['id']);
        if (!$ticket) return redirect()->route('guildhall');

        return Inertia::render('Characters', [
            'username' => $user['name'], 'character' => $ticket->player_name,
            'officer' => in_array($user['role'], ['officer', 'admin'], true),
            'roster' => Inertia::defer(fn () => $services->roster()),
        ]);
    }

    public function characterOverview(Request $request, GuildServices $services, string $player)
    {
        return $this->dashboard($request, $services, $player);
    }

    public function dashboard(Request $request, GuildServices $services, ?string $player = null)
    {
        $user = $request->session()->get('discord_user');
        $ticket = $this->approvedTicket($user['id']);
        if (!$ticket) {
            return redirect()->route('guildhall');
        }

        $profile = $player === null ? ['id' => $ticket->player_id, 'name' => $ticket->player_name]
            : collect($services->roster())->firstWhere('id', $player);
        abort_unless($profile, 404, 'This character is not in the current Conscripted roster.');

        return Inertia::render('Dashboard', [
            'viewedCharacter' => $player === null ? null : $profile,
            'lootTally' => collect(app(\App\Services\LootTallies::class)->guild())
                ->first(fn ($row) => mb_strtolower($row['name']) === mb_strtolower(trim($profile['name']))),
            'username' => $user['name'],
            'character' => $ticket->player_name,
            'officer' => in_array($user['role'], ['officer', 'admin'], true),
            'stats' => Inertia::defer(fn () => $services->characterStats($profile['id'])),
            'zvz' => Inertia::defer(fn () => $services->zvzReports($profile['id'], personal: true), 'zvz'),
        ]);
    }

    public function battles(Request $request, GuildServices $services)
    {
        $user = $request->session()->get('discord_user');
        $ticket = $this->approvedTicket($user['id']);
        if (!$ticket) {
            return redirect()->route('guildhall');
        }

        $page = filter_var($request->query('page', 1), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 4],
        ]);
        abort_if($page === false, 404);
        $batch = filter_var($request->query('batch', 1), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => intdiv(PHP_INT_MAX, 100)],
        ]);
        abort_if($batch === false, 404);
        $scope = $request->query('scope', 'guild');
        $range = $request->query('range', 'month');
        $minimum = filter_var($request->query('minimum', GuildServices::BOARD_MINIMUMS[0]), FILTER_VALIDATE_INT);
        abort_unless(in_array($scope, ['guild', 'alliance'], true)
            && in_array($range, ['month', 'lastMonth'], true)
            && in_array($minimum, GuildServices::BOARD_MINIMUMS, true), 404);

        return Inertia::render('Battles', [
            'username' => $user['name'],
            'character' => $ticket->player_name,
            'officer' => in_array($user['role'], ['officer', 'admin'], true),
            'page' => $page,
            'batch' => $batch,
            'scope' => $scope,
            'range' => $range,
            'minimum' => $minimum,
            'minimumOptions' => GuildServices::BOARD_MINIMUMS,
            'zvz' => Inertia::defer(fn () => $services->zvzReports($ticket->player_id, $page, $minimum, $scope === 'alliance', sourceOffset: ($batch - 1) * 100, range: $range)),
        ]);
    }

    public function guildStats(Request $request, GuildServices $services)
    {
        $user = $request->session()->get('discord_user');
        $ticket = $this->approvedTicket($user['id']);
        if (!$ticket) {
            return redirect()->route('guildhall');
        }

        return Inertia::render('GuildStats', [
            'username' => $user['name'],
            'character' => $ticket->player_name,
            'officer' => in_array($user['role'], ['officer', 'admin'], true),
            'stats' => Inertia::defer(fn () => $services->guildStats()),
            'attendance' => Inertia::defer(fn () => $services->guildAttendance($ticket->player_id), 'attendance'),
        ]);
    }

    public function battle(Request $request, GuildServices $services, int $battle)
    {
        $user = $request->session()->get('discord_user');
        $ticket = $this->approvedTicket($user['id']);
        if (!$ticket) {
            return redirect()->route('guildhall');
        }

        return Inertia::render('BattleDetails', [
            'username' => $user['name'],
            'character' => $ticket->player_name,
            'playerId' => $ticket->player_id,
            'officer' => in_array($user['role'], ['officer', 'admin'], true),
            'battle' => $services->battleDetails($battle),
        ]);
    }

    public function inbox(Request $request)
    {
        $user = $request->session()->get('discord_user');
        abort_unless(in_array($user['role'], ['officer', 'admin'], true), 403);
        $tickets = DB::table('verifications')->latest('updated_at')->paginate(25)->through(fn ($ticket) => [
            'id' => $ticket->discord_user_id,
            'code' => $ticket->code,
            'name' => $ticket->player_name,
            'status' => $ticket->status,
            'url' => 'https://discord.com/channels/'.config('guildhall.guild').'/'.$ticket->channel_id,
        ]);

        return Inertia::render('Inbox', [
            'tickets' => $tickets,
            'username' => $user['name'],
            'character' => $this->approvedCharacter($user['id']),
        ]);
    }

    public function create(Request $request, GuildServices $services)
    {
        $input = $request->validate(['player' => ['required', 'regex:/^[A-Za-z0-9_-]{22}$/D']]);
        $user = $request->session()->get('discord_user');
        $player = collect($services->roster())->firstWhere('id', $input['player']);
        abort_unless($player, 422, 'This character is not on the current guild roster.');
        $guild = config('guildhall.guild');

        $lock = Cache::lock('verification:'.$user['id'], 45);
        abort_unless($lock->get(), 409, 'A request is already being processed.');
        try {
            $existing = DB::table('verifications')->where('discord_user_id', $user['id'])->first();
            if ($existing && $existing->player_id === $player['id'] && in_array($existing->status, ['pending', 'approved'], true)) {
                return redirect()->route('guildhall');
            }

            $botUser = $services->bot()->get('/users/@me')->throw()->json('id');
            abort_unless(is_string($botUser) && preg_match('/^\d{17,20}$/D', $botUser), 502);
            $permissions = [
                ['id' => $guild, 'type' => 0, 'deny' => '1024', 'allow' => '0'],
                ['id' => $user['id'], 'type' => 1, 'allow' => '101376', 'deny' => '0'],
                ['id' => $botUser, 'type' => 1, 'allow' => '101376', 'deny' => '0'],
            ];
            foreach (['officer', 'admin'] as $level) {
                foreach (explode(',', config('guildhall.roles.'.$level) ?? '') as $role) {
                    $role = trim($role);
                    if (preg_match('/^\d{17,20}$/D', $role) && $role !== $guild) {
                        $permissions[$role] = ['id' => $role, 'type' => 0, 'allow' => '101376', 'deny' => '0'];
                    }
                }
            }
            abort_unless(count($permissions) > 3 && config('guildhall.public_key'), 503, 'Officer verification needs setup.');
            $code = bin2hex(random_bytes(5));
            $channel = $services->bot()->post("/guilds/{$guild}/channels", array_filter([
                'name' => 'verification-'.$code,
                'type' => 0,
                'parent_id' => config('guildhall.category'),
                'permission_overwrites' => array_values($permissions),
            ], fn ($value) => $value !== null && $value !== ''))->throw()->json('id');
            abort_unless(is_string($channel) && preg_match('/^\d{17,20}$/D', $channel), 502);

            try {
                $services->bot()->post("/channels/{$channel}/messages", [
                    'content' => 'Submit proof that you control '.$player['name'].'.',
                    'allowed_mentions' => ['parse' => []],
                ])->throw();

                DB::table('verifications')->upsert([[
                    'discord_user_id' => $user['id'],
                    'player_id' => $player['id'],
                    'player_name' => $player['name'],
                    'channel_id' => $channel,
                    'code' => $code,
                    'status' => 'pending',
                    'approved_player_id' => null,
                    'reviewer_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]], ['discord_user_id'], ['player_id', 'player_name', 'channel_id', 'code', 'status', 'approved_player_id', 'reviewer_id', 'updated_at']);
            } catch (\Throwable $error) {
                try {
                    $services->bot()->delete("/channels/{$channel}");
                } catch (\Illuminate\Http\Client\ConnectionException) {
                    // The channel may need manual cleanup.
                }
                throw $error;
            }
        } finally {
            $lock->release();
        }

        return redirect()->route('guildhall');
    }

    public function interaction(Request $request)
    {
        $body = $request->getContent();
        $timestamp = $request->header('X-Signature-Timestamp') ?? '';
        $signature = $request->header('X-Signature-Ed25519') ?? '';
        $key = config('guildhall.public_key') ?? '';
        abort_unless(strlen($body) <= 65536 && preg_match('/^\d{10}$/D', $timestamp)
            && abs(time() - (int) $timestamp) <= 300 && preg_match('/^[a-f0-9]{128}$/iD', $signature)
            && preg_match('/^[a-f0-9]{64}$/iD', $key)
            && sodium_crypto_sign_verify_detached(hex2bin($signature), $timestamp.$body, hex2bin($key)), 401);

        $input = json_decode($body, true);
        if (($input['type'] ?? null) === 1) {
            return response()->json(['type' => 1]);
        }

        $application = $input['application_id'] ?? '';
        $channel = $input['channel_id'] ?? '';
        $officer = $input['member']['user']['id'] ?? '';
        $token = $input['token'] ?? '';
        $command = $input['data']['options'][0] ?? [];
        $action = $command['name'] ?? '';
        $code = collect($command['options'] ?? [])->firstWhere('name', 'code')['value'] ?? '';
        abort_unless(($input['type'] ?? null) === 2 && ($input['data']['name'] ?? '') === 'guildhall'
            && $application === config('guildhall.client_id') && ($input['guild_id'] ?? '') === config('guildhall.guild')
            && preg_match('/^\d{17,20}$/D', $channel) && preg_match('/^\d{17,20}$/D', $officer)
            && preg_match('/^[A-Za-z0-9_.-]{1,512}$/D', $token)
            && preg_match('/^[a-f0-9]{10}$/D', $code)
            && in_array($action, ['approve', 'deny', 'revoke'], true), 400);

        $encryptedToken = Crypt::encryptString($token);
        dispatch(function () use ($application, $channel, $officer, $encryptedToken, $code, $action) {
            $services = app(GuildServices::class);
            $guild = config('guildhall.guild');
            $message = 'The decision could not be recorded. Try again.';
            try {
                $role = $services->role($services->bot()->get("/guilds/{$guild}/members/{$officer}")->throw()->json());
                $ticket = DB::table('verifications')->where('channel_id', $channel)->where('code', $code)->first();
                $required = $action === 'revoke' ? 'approved' : 'pending';
                if (!in_array($role, ['officer', 'admin'], true) || !$ticket || $ticket->status !== $required || $ticket->discord_user_id === $officer) {
                    $message = 'This ticket or officer is ineligible for that decision.';
                } elseif ($action === 'approve' && !collect($services->roster())->firstWhere('id', $ticket->player_id)) {
                    $message = 'The character is no longer on the current guild roster.';
                } else {
                    $status = ['approve' => 'approved', 'deny' => 'denied', 'revoke' => 'revoked'][$action];
                    $changed = DB::table('verifications')->where('channel_id', $channel)->where('code', $code)
                        ->where('status', $required)->update([
                            'status' => $status,
                            'approved_player_id' => $status === 'approved' ? $ticket->player_id : null,
                            'reviewer_id' => $officer,
                            'updated_at' => now(),
                        ]);
                    $message = $changed ? 'Verification '.$status.'.' : 'The ticket changed before review.';
                }
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                $message = 'That character is already approved for another member.';
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                logger()->warning('Discord verification decision failed', [
                    'status' => $error->getStatusCode(),
                    'reason' => $error->getMessage(),
                ]);
            } catch (\Throwable $error) {
                logger()->warning('Discord verification decision failed', ['type' => $error::class]);
            }
            $token = Crypt::decryptString($encryptedToken);
            Http::timeout(8)->withoutRedirecting()
                ->patch("https://discord.com/api/v10/webhooks/{$application}/{$token}/messages/@original", [
                    'content' => $message,
                    'allowed_mentions' => ['parse' => []],
                ]);
        })->onConnection('database');

        return response()->json(['type' => 5, 'data' => ['flags' => 64]]);
    }
}
