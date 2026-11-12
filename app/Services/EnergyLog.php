<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

final class EnergyLog
{
    public function timestamp(string $value, string $order): ?string
    {
        $value = trim($value);
        $fraction = '';
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})(?:\.(\d{1,7}))?Z?$/D', $value, $match)) {
            $raw = $match[1].' '.$match[2];
            $format = '!Y-m-d H:i:s';
            $fraction = rtrim($match[3] ?? '', '0');
        } elseif (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4} \d{2}:\d{2}:\d{2}$/D', $value)) {
            $raw = $value;
            $format = $order === 'mdy' ? '!m/d/Y H:i:s' : '!d/m/Y H:i:s';
        } else {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat($format, $raw, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            return null;
        }

        return $date->format('Y-m-d\TH:i:s').($fraction !== '' ? '.'.$fraction : '').'Z';
    }

    public function parse(string $text, string $order, string $cutoff): array
    {
        $lines = preg_split('/\r\n|\n|\r/', preg_replace('/^\x{FEFF}/u', '', $text));
        $rows = [];
        $errors = [];
        $beforeCutoff = 0;
        $occurrences = [];
        foreach ($lines as $index => $line) {
            if (trim($line) === '') continue;
            $delimiter = str_contains($line, "\t") ? "\t" : (str_contains($line, ';') ? ';' : ',');
            $cells = array_map('trim', str_getcsv($line, $delimiter, '"', ''));
            if (count($cells) === 4 && in_array(strtolower($cells[0]), ['date', 'date (utc)', 'timestamp_utc'], true)
                && strtolower($cells[1]) === 'player' && strtolower($cells[2]) === 'reason'
                && strtolower($cells[3]) === 'amount') {
                continue;
            }
            if (count($cells) !== 4) {
                $errors[] = ['line' => $index + 1, 'message' => 'Expected Date, Player, Reason, Amount in four columns.'];
                continue;
            }
            [$rawDate, $player, $reason, $rawAmount] = $cells;
            $at = $this->timestamp($rawDate, $order);
            $reason = strtolower($reason);
            $kind = match ($reason) { 'deposit' => 'deposit', 'withdraw', 'withdrawal' => 'withdrawal', default => null };
            if (!$at || $player === '' || mb_strlen($player) > 64 || !$kind
                || !preg_match('/^[+-]?(?:\d+|\d{1,3}(?:,\d{3})+)$/D', $rawAmount)) {
                $errors[] = ['line' => $index + 1, 'message' => 'Invalid date, player, reason, or integer amount. Only Deposit and Withdrawal are supported.'];
                continue;
            }
            $amount = (int) str_replace(',', '', $rawAmount);
            if ($amount === 0 || abs($amount) > 1000000000 || ($kind === 'deposit' && $amount < 0)) {
                $errors[] = ['line' => $index + 1, 'message' => 'Invalid amount or deposit sign.'];
                continue;
            }
            $amount = abs($amount);
            $timeKey = fn ($time) => substr($time, 0, 19).str_pad(
                preg_match('/\.(\d+)Z$/D', $time, $fraction) ? $fraction[1] : '', 7, '0');
            if (strcmp($timeKey($at), $timeKey($cutoff)) <= 0) {
                $beforeCutoff++;
                continue;
            }
            $key = json_encode([$at, mb_strtolower($player), $kind, $amount], JSON_THROW_ON_ERROR);
            $occurrence = ($occurrences[$key] ?? 0) + 1;
            $occurrences[$key] = $occurrence;
            $rows[] = ['line' => $index + 1, 'player' => $player, 'kind' => $kind, 'amount' => $amount,
                'at' => $at, 'fingerprint' => hash('sha256', $key.'#'.$occurrence)];
        }

        return ['rows' => $rows, 'errors' => $errors, 'beforeCutoff' => $beforeCutoff];
    }
}
