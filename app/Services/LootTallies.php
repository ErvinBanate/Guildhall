<?php

declare(strict_types=1);

namespace App\Services;

final class LootTallies
{
    public function guild(): array
    {
        return $this->summarize(\Illuminate\Support\Facades\DB::table('loot_logs')
            ->where('guild_id', config('guildhall.albion_guild'))->whereNotNull('confirmed_by')
            ->orderBy('id')->cursor(['entries', 'per_item']));
    }

    /** Derive totals from saved snapshots, never increment on each save. */
    public function summarize(iterable $logs): array
    {
        $events = [];
        foreach ($logs as $log) {
            foreach (json_decode($log->entries, true, flags: JSON_THROW_ON_ERROR) as $entry) {
                $player = mb_strtolower(trim($entry['player']));
                // Preserve the logger's fractional-second precision.
                $time = new \DateTimeImmutable($entry['recordedAt']);
                preg_match('/\.(\d+)Z$/i', $entry['recordedAt'], $fraction);
                $timestamp = $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s').'.'.rtrim($fraction[1] ?? '', '0');
                $key = json_encode([$timestamp, $player, !empty($entry['itemId'])
                    ? ['id', strtoupper(trim($entry['itemId']))] : ['name', mb_strtolower(trim($entry['item']))],
                    $entry['quantity'], mb_strtolower(trim($entry['source']))], JSON_THROW_ON_ERROR);
                $events[$key] ??= ['player' => $player, 'name' => trim($entry['player']),
                    'quantity' => (string) $entry['quantity'], 'statuses' => [], 'values' => []];
                $status = $entry['status'] ?? 'unknown';
                if (!in_array($status, ['banked', 'lost'], true)) continue;
                $events[$key]['statuses'][$status] = true;
                $value = isset($entry['marketValue']) ? (float) $entry['marketValue'] * ($log->per_item ? $entry['quantity'] : 1) : null;
                if ($value !== null && is_finite($value)) $events[$key]['values'][(string) $value] = $value;
            }
        }
        $players = [];
        foreach ($events as $event) {
            if (!$event['statuses']) continue;
            $empty = ['quantity' => '0', 'value' => 0.0, 'unpriced' => 0, 'records' => 0];
            $players[$event['player']] ??= ['name' => $event['name'], 'banked' => $empty, 'lost' => $empty, 'conflicts' => 0];
            $player = &$players[$event['player']];
            if (count($event['statuses']) > 1) {
                $player['conflicts']++;
                unset($player);
                continue;
            }
            $bucket = &$player[array_key_first($event['statuses'])];
            $bucket['quantity'] = $this->add($bucket['quantity'], $event['quantity']);
            $bucket['records']++;
            if (count($event['values']) !== 1) $bucket['unpriced']++;
            else $bucket['value'] += array_values($event['values'])[0];
            unset($bucket, $player);
        }
        foreach ($players as &$player) {
            foreach (['banked', 'lost'] as $status) {
                $bucket = &$player[$status];
                if (!is_finite($bucket['value']) || ($bucket['records'] > 0 && $bucket['unpriced'] === $bucket['records'])) $bucket['value'] = null;
                unset($bucket);
            }
        }
        unset($player);
        uasort($players, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
        return array_values($players);
    }

    private function add(string $a, string $b): string
    {
        $result = ''; $carry = 0;
        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0 || $j >= 0 || $carry; $i--, $j--) {
            $sum = ($i >= 0 ? (int) $a[$i] : 0) + ($j >= 0 ? (int) $b[$j] : 0) + $carry;
            $result = ($sum % 10).$result; $carry = intdiv($sum, 10);
        }
        return $result;
    }
}
