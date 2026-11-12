<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class GuildServices
{
    private const GAMEINFO = 'https://gameinfo-sgp.albiononline.com/api/gameinfo';
    public const BATTLE_PAGE_SIZE = 25;
    private const BATTLE_SOURCE_SIZE = 50;
    public const ZVZ_MINIMUM = 20;
    public const BOARD_MINIMUMS = [7, 25, 50, 100];

    public function characterStats(string $id): array
    {
        $profile = $this->gameinfoPayload($this->gameinfo('/players/'.rawurlencode($id)));
        if (($profile['Id'] ?? null) !== $id) {
            $profile = [];
        }

        return [
            'killFame' => $this->gameinfoNumber($profile, 'KillFame'),
            'deathFame' => $this->gameinfoNumber($profile, 'DeathFame'),
            'fameRatio' => $this->gameinfoNumber($profile, 'FameRatio'),
            'pveFame' => $this->gameinfoNumber($profile, 'LifetimeStatistics.PvE.Total'),
            'gatheringFame' => $this->gameinfoNumber($profile, 'LifetimeStatistics.Gathering.All.Total'),
            'craftingFame' => $this->gameinfoNumber($profile, 'LifetimeStatistics.Crafting.Total'),
            'fishingFame' => $this->gameinfoNumber($profile, 'LifetimeStatistics.FishingFame'),
            'farmingFame' => $this->gameinfoNumber($profile, 'LifetimeStatistics.FarmingFame'),
        ];
    }

    private function gameinfoPayload(mixed $response, bool $list = false): ?array
    {
        if (!$response instanceof Response || !$response->successful()) {
            return null;
        }

        if ($list && !str_starts_with(ltrim($response->body()), '[')) {
            return null;
        }

        $body = $response->json();

        return is_array($body) ? $body : null;
    }

    private function gameinfoNumber(array $payload, string $key): int|float|null
    {
        $value = data_get($payload, $key);

        return (is_int($value) || is_float($value)) && is_finite($value) && $value >= 0 ? $value : null;
    }

    public function zvzReports(string $playerId, int $page = 1, int $minimum = self::ZVZ_MINIMUM, bool $alliance = false, bool $personal = false, bool $attendance = false, int $sourceOffset = 0, ?string $range = null): ?array
    {
        $guildId = config('guildhall.albion_guild');
        $source = '/guilds/'.rawurlencode($guildId);
        $allianceId = null;
        if ($alliance) {
            $guild = $this->gameinfoPayload($this->gameinfo($source));
            $allianceId = $guild['AllianceId'] ?? null;
            if (($guild['Id'] ?? null) !== $guildId || !is_string($allianceId)) {
                return null;
            }
            if ($allianceId === '') {
                return ['reports' => [], 'scanned' => 0, 'skipped' => 0, 'hasMore' => false, 'hasOlder' => false, 'noAlliance' => true];
            }
            if (!preg_match('/^[A-Za-z0-9_-]{22}$/D', $allianceId)) {
                return null;
            }
            $source = '/alliances/'.rawurlencode($allianceId);
        }

        $payload = [];
        for ($offset = 0; $offset < 100; $offset += self::BATTLE_SOURCE_SIZE) {
            $batch = $this->gameinfoPayload($this->gameinfo($source.'/battles', [
                'limit' => self::BATTLE_SOURCE_SIZE,
                'offset' => $sourceOffset + $offset,
            ] + ($range === null ? [] : ['range' => $range])), list: true);
            if ($batch === null || !array_is_list($batch) || count($batch) > self::BATTLE_SOURCE_SIZE) {
                return null;
            }
            array_push($payload, ...$batch);
            if (count($batch) < self::BATTLE_SOURCE_SIZE) {
                break;
            }
        }

        $reports = [];
        $skipped = 0;
        foreach ($payload as $battle) {
            $id = $battle['id'] ?? null;
            $players = $battle['players'] ?? null;
            $start = $battle['startTime'] ?? null;
            if (!is_int($id) || $id <= 0 || !is_array($players) || !is_string($start)
                || !preg_match('/^\d{4}-\d{2}-\d{2}T/', $start) || strtotime($start) === false) {
                $skipped++;
                continue;
            }

            $members = [];
            $character = null;
            $allies = [];
            foreach ($players as $player) {
                $participantId = $player['id'] ?? null;
                if (!is_string($participantId) || !preg_match('/^[A-Za-z0-9_-]{22}$/D', $participantId)) {
                    continue;
                }
                if ($participantId === $playerId) {
                    $character = $player;
                }
                if (($player['guildId'] ?? null) === $guildId) {
                    $members[$participantId] = true;
                }
                if ($allianceId !== null && ($player['allianceId'] ?? null) === $allianceId) {
                    $allies[$participantId] = true;
                }
            }
            $matched = $alliance ? $members + $allies : $members;
            if (count($matched) < $minimum || ($personal && $character === null)) {
                continue;
            }

            $alliances = [];
            foreach (is_array($battle['alliances'] ?? null) ? $battle['alliances'] : [] as $allianceScore) {
                $name = $this->battleName($allianceScore['name'] ?? null);
                if ($name !== null) {
                    $alliances[strtolower($name)] = $name;
                }
            }
            natcasesort($alliances);

            $reports[$id] = [
                'id' => $id,
                'startedAt' => gmdate(DATE_ATOM, strtotime($start)),
                'guildMembers' => count($members),
                'matchedMembers' => count($matched),
                'alliances' => array_values($alliances),
                'characterRecorded' => $character !== null,
                'personalStats' => $personal && $character !== null ? [
                    'kills' => $this->gameinfoNumber($character, 'kills'),
                    'deaths' => $this->gameinfoNumber($character, 'deaths'),
                    'killFame' => $this->gameinfoNumber($character, 'killFame'),
                ] : null,
                'totalKills' => $this->gameinfoNumber($battle, 'totalKills'),
                'totalFame' => $this->gameinfoNumber($battle, 'totalFame'),
            ];
            if ($attendance) {
                $reports[$id]['memberIds'] = array_keys($members);
            }
        }
        usort($reports, fn ($a, $b) => strcmp($b['startedAt'], $a['startedAt']) ?: $b['id'] <=> $a['id']);
        $offset = ($page - 1) * self::BATTLE_PAGE_SIZE;

        return [
            'reports' => $attendance ? $reports : array_slice($reports, $offset, self::BATTLE_PAGE_SIZE),
            'scanned' => count($payload),
            'skipped' => $skipped,
            'hasMore' => count($reports) > $offset + self::BATTLE_PAGE_SIZE,
            'hasOlder' => count($payload) === 100,
            'noAlliance' => false,
        ];
    }

    public function guildStats(): ?array
    {
        $id = config('guildhall.albion_guild');
        $guild = $this->gameinfoPayload($this->gameinfo('/guilds/'.rawurlencode($id)));
        if (($guild['Id'] ?? null) !== $id) {
            return null;
        }

        return [
            'name' => $this->battleName($guild['Name'] ?? null),
            'alliance' => $this->battleName($guild['AllianceTag'] ?? null),
            'memberCount' => $this->gameinfoNumber($guild, 'MemberCount'),
            'killFame' => $this->gameinfoNumber($guild, 'killFame'),
            'deathFame' => $this->gameinfoNumber($guild, 'DeathFame'),
        ];
    }

    public function guildAttendance(string $playerId): ?array
    {
        $feed = $this->zvzReports($playerId, attendance: true);
        if ($feed === null) {
            return null;
        }

        try {
            $roster = $this->roster();
        } catch (\Illuminate\Http\Client\HttpClientException | \Symfony\Component\HttpKernel\Exception\HttpException) {
            return null;
        }

        $attendance = [];
        foreach ($feed['reports'] as $report) {
            foreach ($report['memberIds'] as $id) {
                $attendance[$id] ??= ['battles' => 0, 'lastRecorded' => $report['startedAt']];
                $attendance[$id]['battles']++;
            }
        }

        $members = array_map(fn ($member) => $member + ($attendance[$member['id']]
            ?? ['battles' => null, 'lastRecorded' => null]), $roster);
        usort($members, fn ($a, $b) => ($b['battles'] ?? 0) <=> ($a['battles'] ?? 0)
            ?: strcasecmp($a['name'], $b['name']));

        return [
            'members' => $members,
            'qualifying' => count($feed['reports']),
            'scanned' => $feed['scanned'],
            'skipped' => $feed['skipped'],
        ];
    }

    private function gameinfo(string $path, array $query = []): ?Response
    {
        try {
            return Http::acceptJson()->timeout(8)->withoutRedirecting()->get(self::GAMEINFO.$path, $query);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return null;
        }
    }

    public function battleDetails(int $id): array
    {
        $response = $this->gameinfo('/battles/'.$id);
        abort_if($response?->status() === 404, 404);
        $battle = $this->gameinfoPayload($response);
        $start = $battle['startTime'] ?? null;
        abort_unless(($battle['id'] ?? null) === $id && is_array($battle['players'] ?? null)
            && is_string($start) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $start)
            && strtotime($start) !== false, 503, 'Albion Gameinfo battle details are unavailable.');

        $guildId = config('guildhall.albion_guild');
        $players = [];
        $conscripted = 0;
        foreach ($battle['players'] as $entry) {
            $playerId = $entry['id'] ?? null;
            $name = $entry['name'] ?? null;
            if (!is_string($playerId) || !preg_match('/^[A-Za-z0-9_-]{22}$/D', $playerId)
                || !is_string($name) || trim($name) === '' || isset($players[$playerId])) {
                continue;
            }
            $memberGuild = is_string($entry['guildId'] ?? null) ? $entry['guildId'] : null;
            $conscripted += (int) ($memberGuild === $guildId);
            $players[$playerId] = [
                'id' => $playerId,
                'name' => $name,
                'guild' => $this->battleName($entry['guildName'] ?? null),
                'alliance' => $this->battleName($entry['allianceName'] ?? null),
                'guildId' => $memberGuild,
                'allianceId' => is_string($entry['allianceId'] ?? null) ? $entry['allianceId'] : null,
                'conscripted' => $memberGuild === $guildId,
                'kills' => $this->gameinfoNumber($entry, 'kills'),
                'deaths' => $this->gameinfoNumber($entry, 'deaths'),
                'killFame' => $this->gameinfoNumber($entry, 'killFame'),
            ];
        }

        if ($conscripted === 0) {
            $guild = $this->gameinfoPayload($this->gameinfo('/guilds/'.rawurlencode($guildId)));
            $allianceId = $guild['AllianceId'] ?? null;
            abort_unless(($guild['Id'] ?? null) === $guildId && is_string($allianceId), 503, 'Albion Gameinfo alliance data is unavailable.');
            abort_unless($allianceId !== '' && in_array($allianceId, array_column($players, 'allianceId'), true), 404);
        }

        $events = $this->battleEvents($id, $players);
        $guilds = $this->battleGroups($players, $battle['guilds'] ?? [], 'guildId', 'guild', $events['equipment']);
        $alliances = $this->battleGroups($players, $battle['alliances'] ?? [], 'allianceId', 'alliance', $events['equipment']);
        $weapons = [];
        foreach ($events['equipment'] as $equipment) {
            if ($equipment['weapon'] !== null) {
                $weapons[$equipment['weapon']] = ($weapons[$equipment['weapon']] ?? 0) + 1;
            }
        }
        arsort($weapons);
        usort($players, fn ($a, $b) => ($b['kills'] ?? -1) <=> ($a['kills'] ?? -1) ?: strcasecmp($a['name'], $b['name']));
        foreach ($players as &$player) {
            $player['itemPower'] = $events['equipment'][$player['id']]['itemPower'] ?? null;
            unset($player['allianceId'], $player['guildId']);
        }
        unset($player);
        $leader = $players[0] ?? null;
        $events['highlights']['kills'] = ($leader['kills'] ?? null) === null ? null : [
            'name' => $leader['name'], 'guild' => $leader['guild'], 'value' => $leader['kills'],
        ];
        unset($events['equipment']);

        return [
            'id' => $id,
            'startedAt' => gmdate(DATE_ATOM, strtotime($start)),
            'location' => $this->battleName($battle['clusterName'] ?? null),
            'totalKills' => $this->gameinfoNumber($battle, 'totalKills'),
            'totalFame' => $this->gameinfoNumber($battle, 'totalFame'),
            'conscripted' => $conscripted,
            'players' => $players,
            'guilds' => $guilds,
            'alliances' => $alliances,
            'composition' => array_map(fn ($weapon, $count) => ['weapon' => $weapon, 'count' => $count], array_keys($weapons), array_values($weapons)),
            'events' => $events,
        ];
    }

    private function battleGroups(array $players, mixed $scores, string $key, string $label, array $equipment): array
    {
        $groups = [];
        foreach ($players as $player) {
            $groupId = $player[$key];
            if ($groupId !== null && $groupId !== '') {
                $groups[$groupId][] = $player;
            }
        }
        $rows = [];
        foreach ($groups as $id => $members) {
            $score = is_array($scores) ? ($scores[$id] ?? null) : null;
            $score = is_array($score) && ($score['id'] ?? null) === $id ? $score : [];
            $power = array_values(array_filter(array_map(fn ($member) => $equipment[$member['id']]['itemPower'] ?? null, $members), fn ($value) => $value !== null));
            $rows[] = [
                'id' => $id,
                'name' => $this->battleName($score['name'] ?? null) ?? $members[0][$label],
                'alliance' => $members[0]['alliance'],
                'participants' => count($members),
                'itemPower' => $power ? array_sum($power) / count($power) : null,
                'kills' => $this->gameinfoNumber($score, 'kills'),
                'deaths' => $this->gameinfoNumber($score, 'deaths'),
                'killFame' => $this->gameinfoNumber($score, 'killFame'),
            ];
        }
        usort($rows, fn ($a, $b) => $b['participants'] <=> $a['participants']);

        return $rows;
    }

    private function battleEvents(int $id, array $players): array
    {
        $kills = [];
        $equipment = [];
        $highlights = ['healing' => null, 'damage' => null, 'deathFame' => null];
        $complete = false;
        $available = false;
        for ($offset = 0; $offset < 200; $offset += 50) {
            $page = $this->gameinfoPayload($this->gameinfo('/events/battle/'.$id, ['limit' => 50, 'offset' => $offset]), list: true);
            if ($page === null || !array_is_list($page) || count($page) > 50) {
                break;
            }
            $available = true;
            foreach ($page as $event) {
                $eventId = $event['EventId'] ?? null;
                $killer = $this->battleName($event['Killer']['Name'] ?? null);
                $victim = $this->battleName($event['Victim']['Name'] ?? null);
                if (($event['BattleId'] ?? null) !== $id || !is_int($eventId) || $eventId <= 0
                    || $killer === null || $victim === null || isset($kills[$eventId])) {
                    continue;
                }
                $fame = $this->gameinfoNumber($event, 'TotalVictimKillFame');
                $timestamp = $event['TimeStamp'] ?? null;
                $time = is_string($timestamp) ? strtotime($timestamp) : false;
                $kills[$eventId] = [
                    'id' => $eventId, 'killer' => $killer, 'victim' => $victim,
                    'fame' => $fame, 'at' => $time === false ? null : gmdate(DATE_ATOM, $time),
                ];
                $victimId = $event['Victim']['Id'] ?? null;
                if (is_string($victimId) && isset($players[$victimId])) {
                    $this->battleHighlight($highlights['deathFame'], $players[$victimId], $fame);
                }

                $participants = is_array($event['Participants'] ?? null) ? $event['Participants'] : [];
                foreach ([...$participants, $event['Killer'], $event['Victim']] as $snapshot) {
                    $playerId = $snapshot['Id'] ?? null;
                    if (!is_string($playerId) || !isset($players[$playerId])) {
                        continue;
                    }
                    $this->battleHighlight($highlights['damage'], $players[$playerId], $this->gameinfoNumber($snapshot, 'DamageDone'));
                    $this->battleHighlight($highlights['healing'], $players[$playerId], $this->gameinfoNumber($snapshot, 'SupportHealingDone'));
                    $weapon = $this->battleName(data_get($snapshot, 'Equipment.MainHand.Type'));
                    $power = $this->gameinfoNumber($snapshot, 'AverageItemPower');
                    $order = $time === false ? 0 : $time;
                    if (($weapon !== null || $power !== null) && (!isset($equipment[$playerId]) || $order > $equipment[$playerId]['order'])) {
                        $equipment[$playerId] = ['order' => $order, 'weapon' => $weapon, 'itemPower' => $power];
                    }
                }
            }
            if (count($page) < 50) {
                $complete = true;
                break;
            }
        }
        krsort($kills);

        return ['kills' => array_values($kills), 'highlights' => $highlights, 'equipment' => $equipment, 'available' => $available, 'complete' => $complete];
    }

    private function battleHighlight(?array &$highlight, array $player, int|float|null $value): void
    {
        if ($value !== null && ($highlight === null || $value > $highlight['value'])) {
            $highlight = ['name' => $player['name'], 'guild' => $player['guild'], 'value' => $value];
        }
    }

    private function battleName(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    public function discord(): PendingRequest
    {
        return Http::baseUrl('https://discord.com/api/v10')
            ->acceptJson()->timeout(8)->withoutRedirecting();
    }

    public function bot(): PendingRequest
    {
        abort_unless(config('guildhall.bot'), 503, 'The ticket bot needs setup.');
        return $this->discord()->withToken(config('guildhall.bot'), 'Bot');
    }

    public function role(mixed $member): ?string
    {
        abort_unless(is_array($member) && is_array($member['roles'] ?? null)
            && array_is_list($member['roles']) && is_bool($member['pending'] ?? false),
            503, 'Discord returned invalid role data.');
        foreach ($member['roles'] as $id) {
            abort_unless(is_string($id) && preg_match('/^\d{17,20}$/D', $id), 503, 'Discord returned invalid role data.');
        }
        if ($member['pending'] ?? false) {
            return null;
        }

        foreach (config('guildhall.roles') as $level => $roles) {
            $allowed = array_filter(array_map('trim', explode(',', $roles ?? '')));
            if (array_intersect($allowed, $member['roles'])) {
                return $level;
            }
        }

        return null;
    }

    public function roster(): array
    {
        $id = rawurlencode(config('guildhall.albion_guild'));
        $members = Http::acceptJson()->timeout(8)->withoutRedirecting()
            ->get(self::GAMEINFO."/guilds/{$id}/members")
            ->throw()->json();
        abort_unless(is_array($members) && array_is_list($members), 502, 'Invalid Albion roster.');
        $roster = [];

        foreach ($members as $member) {
            abort_unless(
                is_string($member['Id'] ?? null) &&
                preg_match('/^[A-Za-z0-9_-]{22}$/D', $member['Id']) &&
                is_string($member['Name'] ?? null) &&
                mb_strlen($member['Name']) >= 1 && mb_strlen($member['Name']) <= 64,
                502,
                'Invalid Albion roster member.'
            );
            $roster[$member['Id']] = ['id' => $member['Id'], 'name' => $member['Name']];
        }

        return array_values($roster);
    }
}
