<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class EnergyImport
{
    public function plan(array $parsed, array $roster, iterable $accounts, bool $initialize): array
    {
        $guild = config('guildhall.albion_guild');
        $names = [];
        $accountNames = [];
        foreach ($roster as $member) $names[mb_strtolower($member['name'])][$member['id']] = $member['name'];
        foreach ($accounts as $account) {
            $accountNames[mb_strtolower($account->player_name)][] = $account;
        }
        $keys = array_map(fn ($row) => hash('sha256', $guild.':'.$row['fingerprint']), $parsed['rows']);
        $existing = DB::table('energy_transactions')->whereIn('source_key', $keys)->pluck('source_key')->flip();
        $errors = $parsed['errors'];
        $changes = [];
        $duplicates = 0;
        foreach ($parsed['rows'] as $index => $row) {
            $key = $keys[$index];
            if (isset($existing[$key])) {
                $duplicates++;
                continue;
            }
            $nameKey = mb_strtolower($row['player']);
            $matches = $names[$nameKey] ?? [];
            $accountMatches = $accountNames[$nameKey] ?? [];
            $account = $accountMatches[0] ?? null;
            if (count($matches) > 1 || count($accountMatches) > 1
                || ($account?->player_id !== null && $matches && !isset($matches[$account->player_id]))) {
                $errors[] = ['line' => $row['line'], 'message' => 'Player '.$row['player'].' cannot be matched uniquely to a roster member or existing account.'];
                continue;
            }
            $playerId = $account ? $account->player_id : array_key_first($matches);
            $id = $account ? 'account:'.$account->id : ($playerId ?? 'name:'.$nameKey);
            if (!$account && !$initialize) {
                $errors[] = ['line' => $row['line'], 'message' => 'Player '.$row['player'].' needs an opening balance, or explicit zero-baseline initialization.'];
                continue;
            }
            $changes[$id] ??= [
                'playerId' => $playerId, 'name' => $account?->player_name ?? ($matches[$playerId] ?? $row['player']),
                'unlinkedNameKey' => $playerId === null ? hash('sha256', $nameKey) : null,
                'accountId' => $account?->id,
                'revision' => $account ? (int) $account->revision : 0,
                'before' => $account ? (int) $account->balance : 0,
                'balance' => $account ? (int) $account->balance : 0,
                'deposits' => $account ? (int) $account->deposits : 0, 'rows' => [],
            ];
            $delta = $row['kind'] === 'deposit' ? $row['amount'] : -$row['amount'];
            $changes[$id]['balance'] += $delta;
            if ($row['kind'] === 'deposit') $changes[$id]['deposits'] += $row['amount'];
            $changes[$id]['rows'][] = $row + ['sourceKey' => $key];
        }
        ksort($changes);
        $added = 0;
        $net = 0;
        foreach ($changes as $change) {
            $added += count($change['rows']);
            $net += $change['balance'] - $change['before'];
            if (abs($change['balance']) > 9007199254740991 || $change['deposits'] > 9007199254740991) {
                $errors[] = ['line' => 0, 'message' => 'An account exceeds the supported balance range.'];
            }
        }

        return ['changes' => $changes, 'errors' => $errors, 'added' => $added,
            'duplicates' => $duplicates, 'beforeCutoff' => $parsed['beforeCutoff'], 'net' => $net];
    }

    public function token(array $plan, string $payloadHash, string $officer): string
    {
        return hash_hmac('sha256', $payloadHash.':'.$officer.':'.json_encode($plan, JSON_THROW_ON_ERROR), config('app.key'));
    }
}
