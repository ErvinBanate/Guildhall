<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_the_dashboard(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/choose-character')->assertRedirect('/login');
        $this->get('/loot-logs')->assertRedirect('/login');
    }

    public function test_dashboard_reads_officer_decision_from_the_database(): void
    {
        Http::preventStrayRequests();
        $id = '123456789012345678';
        DB::table('verifications')->insert([
            'discord_user_id' => $id,
            'player_id' => '2up23a2ER3y_RUGEpAtNHQ',
            'player_name' => 'Test character',
            'code' => '0123456789',
            'status' => 'approved',
        ]);
        $this->withSession(['discord_user' => [
            'id' => $id, 'name' => 'Test member', 'role' => 'member', 'checked' => time(),
        ]])->get('/')->assertInertia(fn (Assert $page) => $page
            ->component('Guildhall')->where('ticket.status', 'approved')->where('officer', false));
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')->where('character', 'Test character'));
        $this->get('/loot-logs')->assertInertia(fn (Assert $page) => $page
            ->component('LootLogs')->where('character', 'Test character')->where('officer', false));
        DB::table('verifications')->where('discord_user_id', $id)->update(['status' => 'revoked']);
        $this->get('/dashboard')->assertRedirect('/');
        $this->get('/loot-logs')->assertRedirect('/');
        Http::assertNothingSent();
    }

    public function test_members_cannot_open_the_officer_inbox(): void
    {
        config(['guildhall.bot' => 'test', 'guildhall.guild' => '222222222222222222',
            'guildhall.roles.member' => '333333333333333333']);
        Http::fake(['discord.com/*' => Http::response(['roles' => ['333333333333333333']])]);
        $this->withSession(['discord_user' => [
            'id' => '123456789012345678', 'name' => 'Test member', 'role' => 'officer', 'checked' => time(),
        ]])->get('/officer/verification')->assertForbidden();
    }

    public function test_dashboard_stats_use_the_approved_player_id_and_preserve_missing_values(): void
    {
        $id = '123456789012345678';
        $player = '2up23a2ER3y_RUGEpAtNHQ';
        DB::table('verifications')->insert([
            'discord_user_id' => $id, 'player_id' => $player, 'player_name' => 'Test character',
            'code' => '0123456789', 'status' => 'approved',
        ]);
        $url = 'https://gameinfo-sgp.albiononline.com/api/gameinfo/players/'.$player;
        Http::preventStrayRequests();
        Http::fake([
            $url => Http::response(['Id' => $player, 'KillFame' => 1250, 'DeathFame' => 0, 'FameRatio' => 'invalid']),
        ]);
        $headers = [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(\App\Http\Middleware\InertiaRequests::class)
                ->version(\Illuminate\Http\Request::create('/dashboard')),
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data' => 'stats',
        ];
        $this->withSession(['discord_user' => [
            'id' => $id, 'name' => 'Test member', 'role' => 'member', 'checked' => time(),
        ]])->get('/dashboard', $headers)->assertOk()
            ->assertJsonPath('props.stats.killFame', 1250)
            ->assertJsonPath('props.stats.deathFame', 0)
            ->assertJsonPath('props.stats.fameRatio', null)
            ->assertJsonPath('props.stats.pveFame', null);
        Http::assertSentCount(1);

        DB::table('verifications')->where('discord_user_id', $id)->update(['status' => 'revoked']);
        $this->get('/dashboard', $headers)->assertRedirect('/');
        Http::assertSentCount(1);
    }

    public function test_zvz_reports_require_twenty_distinct_guild_characters_and_approved_access(): void
    {
        $id = '123456789012345678';
        $guild = config('guildhall.albion_guild');
        $alliance = 'vMw-S2EqRtav9lMiqK8CyQ';
        $players = array_map(fn ($i) => [
            'id' => str_pad((string) $i, 22, 'A'),
            'guildId' => $guild,
        ], range(1, 20));
        $report = ['startTime' => '2026-11-07T08:00:00Z', 'totalKills' => 42];
        Http::preventStrayRequests();
        Http::fake([
            'https://gameinfo-sgp.albiononline.com/api/gameinfo/guilds/'.$guild => Http::response([
                'Id' => $guild, 'AllianceId' => $alliance,
            ]),
            'gameinfo-sgp.albiononline.com/api/gameinfo/guilds/'.$guild.'/battles?*' => Http::response([
                ['id' => 1, 'players' => [...array_slice($players, 0, 19), $players[0], ['id' => $players[19]['id'], 'guildId' => 'other']]] + $report,
                ['id' => 2, 'players' => [$players[0] + ['kills' => 0, 'killFame' => 1200], ...array_slice($players, 1)]] + $report,
                ['id' => 4, 'players' => [['id' => str_repeat('Z', 22), 'guildId' => $guild], ...array_slice($players, 1)]] + $report,
            ]),
            'gameinfo-sgp.albiononline.com/api/gameinfo/alliances/'.$alliance.'/battles?*' => Http::response([
                ['id' => 3, 'players' => array_map(fn ($i) => [
                    'id' => str_pad((string) $i, 22, 'B'), 'guildId' => 'ally', 'allianceId' => $alliance,
                ], range(1, 25))] + $report,
            ]),
        ]);
        $this->get('/battles')->assertRedirect('/login');
        $this->withSession(['discord_user' => [
            'id' => $id, 'name' => 'Test member', 'role' => 'member', 'checked' => time(),
        ]])->get('/battles')->assertRedirect('/');
        Http::assertNothingSent();

        DB::table('verifications')->insert([
            'discord_user_id' => $id, 'player_id' => $players[0]['id'], 'player_name' => 'Test character',
            'code' => '0123456789', 'status' => 'approved',
        ]);
        foreach (['/battles?scope=alliance&minimum=25&batch=2&range=lastMonth' => 'Battles', '/dashboard' => 'Dashboard'] as $url => $component) {
            $this->get($url, [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => app(\App\Http\Middleware\InertiaRequests::class)
                    ->version(\Illuminate\Http\Request::create($url)),
                'X-Inertia-Partial-Component' => $component,
                'X-Inertia-Partial-Data' => 'zvz',
            ])->assertOk()->assertJsonCount(1, 'props.zvz.reports')
                ->assertJsonPath('props.zvz.reports.0.id', $component === 'Battles' ? 3 : 2)
                ->assertJsonPath('props.zvz.reports.0.guildMembers', $component === 'Battles' ? 0 : 20)
                ->assertJsonPath('props.zvz.reports.0.matchedMembers', $component === 'Battles' ? 25 : 20)
                ->assertJsonPath('props.zvz.reports.0.characterRecorded', $component === 'Dashboard')
                ->assertJsonPath('props.zvz.reports.0.totalFame', null)
                ->assertJsonPath('props.zvz.reports.0.personalStats', $component === 'Dashboard'
                    ? ['kills' => 0, 'deaths' => null, 'killFame' => 1200] : null);
        }
        Http::assertSent(fn ($request) => str_contains($request->url(), 'offset=100')
            && str_contains($request->url(), 'range=lastMonth'));
    }

    public function test_guild_attendance_matches_current_roster_by_id_and_deduplicates_reports(): void
    {
        $guild = config('guildhall.albion_guild');
        $url = 'https://gameinfo-sgp.albiononline.com/api/gameinfo/guilds/'.$guild;
        $players = array_map(fn ($i) => [
            'id' => str_pad((string) $i, 22, 'A'), 'guildId' => $guild,
        ], range(1, 20));
        $member = $players[0]['id'];
        $unmatched = str_repeat('Z', 22);
        $battle = ['id' => 51, 'startTime' => '2026-11-07T08:00:00Z', 'players' => [...$players, $players[0]]];
        Http::preventStrayRequests();
        Http::fake([
            $url.'/battles?*' => Http::response([$battle, $battle, [
                'id' => 52, 'startTime' => '2026-11-08T08:00:00Z', 'players' => array_slice($players, 0, 19),
            ]]),
            $url.'/members' => Http::response([
                ['Id' => $member, 'Name' => 'Current member'],
                ['Id' => $unmatched, 'Name' => 'Unmatched member'],
            ]),
        ]);
        $this->get('/guild')->assertRedirect('/login');
        $discordId = '123456789012345678';
        $this->withSession(['discord_user' => [
            'id' => $discordId, 'name' => 'Member', 'role' => 'member', 'checked' => time(),
        ]])->get('/guild')->assertRedirect('/');
        Http::assertNothingSent();
        DB::table('verifications')->insert([
            'discord_user_id' => $discordId, 'player_id' => $member, 'player_name' => 'Current member',
            'code' => '0123456789', 'status' => 'approved',
        ]);
        $this->get('/guild', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(\App\Http\Middleware\InertiaRequests::class)
                ->version(\Illuminate\Http\Request::create('/guild')),
            'X-Inertia-Partial-Component' => 'GuildStats',
            'X-Inertia-Partial-Data' => 'attendance',
        ])->assertOk()->assertJsonPath('props.attendance.qualifying', 1)
            ->assertJsonCount(2, 'props.attendance.members')
            ->assertJsonPath('props.attendance.members.0.id', $member)
            ->assertJsonPath('props.attendance.members.0.battles', 1)
            ->assertJsonPath('props.attendance.members.1.id', $unmatched)
            ->assertJsonPath('props.attendance.members.1.battles', null);
    }

    public function test_battle_feed_sorts_across_source_batches_before_pagination(): void
    {
        $guild = config('guildhall.albion_guild');
        $url = 'https://gameinfo-sgp.albiononline.com/api/gameinfo/guilds/'.$guild.'/battles';
        $players = array_map(fn ($i) => [
            'id' => str_pad((string) $i, 22, 'A'), 'guildId' => $guild,
        ], range(1, 7));
        $older = array_map(fn ($id) => [
            'id' => $id, 'startTime' => '2026-11-01T08:00:00Z', 'players' => $players,
        ], range(1, 50));
        Http::preventStrayRequests();
        Http::fake([
            $url.'?limit=50&offset=0' => Http::response($older),
            $url.'?limit=50&offset=50' => Http::response([[
                'id' => 51, 'startTime' => '2026-11-07T08:00:00Z', 'players' => $players,
            ]]),
        ]);

        $service = app(\App\Services\GuildServices::class);
        $first = $service->zvzReports($players[0]['id'], 1, 7);
        $second = $service->zvzReports($players[0]['id'], 2, 7);
        $this->assertSame(51, $first['reports'][0]['id']);
        $this->assertCount(25, $first['reports']);
        $this->assertSame(26, $second['reports'][0]['id']);
    }

    public function test_battle_details_require_approval_and_a_relevant_report(): void
    {
        $id = '123456789012345678';
        $player = '2up23a2ER3y_RUGEpAtNHQ';
        $guild = config('guildhall.albion_guild');
        $alliance = 'vMw-S2EqRtav9lMiqK8CyQ';
        $base = 'https://gameinfo-sgp.albiononline.com/api/gameinfo';
        $record = ['id' => $player, 'name' => 'Recorded player', 'kills' => 0];
        Http::preventStrayRequests();
        Http::fake([
            $base.'/battles/10' => Http::response(['id' => 10, 'startTime' => '2026-11-07T08:00:00Z',
                'players' => [$record + ['guildId' => $guild, 'allianceId' => $alliance]],
                'alliances' => [$alliance => ['id' => $alliance, 'name' => 'Alliance', 'kills' => 0]]]),
            $base.'/battles/11' => Http::response(['id' => 11, 'startTime' => '2026-11-07T08:00:00Z',
                'players' => [$record + ['allianceId' => $alliance]]]),
            $base.'/battles/12' => Http::response(['id' => 12, 'startTime' => '2026-11-07T08:00:00Z',
                'players' => [$record]]),
            $base.'/guilds/'.$guild => Http::response(['Id' => $guild, 'AllianceId' => $alliance]),
            $base.'/events/battle/10?*' => Http::response([[
                'EventId' => 100, 'BattleId' => 10, 'Killer' => ['Name' => 'Opponent'],
                'Victim' => ['Id' => $player, 'Name' => 'Recorded player', 'AverageItemPower' => 1500,
                    'Equipment' => ['MainHand' => ['Type' => 'T8_2H_LONGBOW']]],
                'TotalVictimKillFame' => 2000,
            ], [
                'EventId' => 101, 'BattleId' => 11, 'Killer' => ['Name' => 'Unrelated'],
                'Victim' => ['Name' => 'Unrelated'],
            ]]),
            $base.'/events/battle/11?*' => Http::response([], 503),
        ]);
        $this->get('/battles/10')->assertRedirect('/login');
        $this->withSession(['discord_user' => [
            'id' => $id, 'name' => 'Test member', 'role' => 'member', 'checked' => time(),
        ]])->get('/battles/10')->assertRedirect('/');
        Http::assertNothingSent();
        DB::table('verifications')->insert([
            'discord_user_id' => $id, 'player_id' => $player, 'player_name' => 'Test character',
            'code' => '0123456789', 'status' => 'approved',
        ]);
        $this->get('/battles/10')->assertInertia(fn (Assert $page) => $page->component('BattleDetails')
            ->where('battle.conscripted', 1)->where('battle.players.0.kills', 0)
            ->where('battle.players.0.deaths', null)
            ->where('battle.alliances.0.itemPower', 1500)
            ->where('battle.composition.0.weapon', 'T8_2H_LONGBOW')
            ->has('battle.events.kills', 1)
            ->where('battle.events.highlights.deathFame.value', 2000));
        $this->get('/battles/11')->assertOk();
        $this->get('/battles/12')->assertNotFound();
    }

    public function test_officer_inbox_workspace_uses_the_officers_own_approval(): void
    {
        $id = '123456789012345678';
        $role = '333333333333333333';
        config(['guildhall.bot' => 'test', 'guildhall.guild' => '222222222222222222',
            'guildhall.roles.officer' => $role]);
        Http::fake(['discord.com/*' => Http::response(['roles' => [$role]])]);
        DB::table('verifications')->insert([
            'discord_user_id' => $id,
            'player_id' => '2up23a2ER3y_RUGEpAtNHQ',
            'player_name' => 'Officer character',
            'code' => '0123456789',
            'status' => 'pending',
        ]);

        $this->withSession(['discord_user' => [
            'id' => $id, 'name' => 'Test officer', 'role' => 'officer', 'checked' => time(),
        ]])->get('/officer/verification')->assertInertia(fn (Assert $page) => $page
            ->component('Inbox')->where('character', null)->where('tickets.data.0.status', 'pending'));

        DB::table('verifications')->where('discord_user_id', $id)->update(['status' => 'approved']);
        $this->get('/officer/verification')->assertInertia(fn (Assert $page) => $page
            ->component('Inbox')->where('character', 'Officer character'));

        DB::table('verifications')->where('discord_user_id', $id)->update(['status' => 'revoked']);
        $this->get('/officer/verification')->assertInertia(fn (Assert $page) => $page
            ->component('Inbox')->where('character', null));
    }

    public function test_discord_interactions_require_valid_signature(): void
    {
        $key = sodium_crypto_sign_keypair();
        config(['guildhall.public_key' => bin2hex(sodium_crypto_sign_publickey($key))]);
        $body = '{"type":1}';
        $timestamp = (string) time();
        $signature = bin2hex(sodium_crypto_sign_detached($timestamp.$body, sodium_crypto_sign_secretkey($key)));
        $headers = ['HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp, 'HTTP_X_SIGNATURE_ED25519' => $signature];
        $this->call('POST', '/api/discord/interactions', [], [], [], $headers, $body)
            ->assertOk()->assertExactJson(['type' => 1]);
        $this->call('POST', '/api/discord/interactions', [], [], [], $headers, '{"type":2}')
            ->assertUnauthorized();
    }

    public function test_ticket_and_signed_officer_command_unlock_dashboard(): void
    {
        $member = '123456789012345678';
        $guild = '222222222222222222';
        $memberRole = '333333333333333333';
        $officerRole = '444444444444444444';
        $officer = '555555555555555555';
        $channel = '666666666666666666';
        $application = '777777777777777777';
        $player = '2up23a2ER3y_RUGEpAtNHQ';
        $key = sodium_crypto_sign_keypair();
        config([
            'guildhall.bot' => 'test',
            'guildhall.guild' => $guild,
            'guildhall.client_id' => $application,
            'guildhall.roles.member' => $memberRole,
            'guildhall.roles.officer' => $officerRole,
            'guildhall.public_key' => bin2hex(sodium_crypto_sign_publickey($key)),
        ]);
        Http::preventStrayRequests();
        Http::fake([
            "discord.com/api/v10/guilds/{$guild}/members/{$member}" => Http::response(['roles' => [$memberRole]]),
            "discord.com/api/v10/guilds/{$guild}/members/{$officer}" => Http::response(['roles' => [$officerRole]]),
            'discord.com/api/v10/users/@me' => Http::response(['id' => $application]),
            "discord.com/api/v10/guilds/{$guild}/channels" => Http::response(['id' => $channel]),
            "discord.com/api/v10/channels/{$channel}/messages" => Http::response([]),
            'discord.com/api/v10/webhooks/*' => Http::response([]),
            'gameinfo-sgp.albiononline.com/*' => Http::response([['Id' => $player, 'Name' => 'Test character']]),
        ]);

        $this->withSession(['discord_user' => [
            'id' => $member, 'name' => 'Test member', 'role' => 'member', 'checked' => time(),
        ]])->post('/verification', ['player' => $player], ['Origin' => 'https://elsewhere.example'])->assertForbidden();
        $this->assertDatabaseEmpty('verifications');
        $this->post('/verification', ['player' => $player], ['Origin' => config('app.url')])->assertRedirect('/');
        $ticket = DB::table('verifications')->where('discord_user_id', $member)->first();
        $this->assertSame('pending', $ticket->status);
        $this->get('/dashboard')->assertRedirect('/');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), "/guilds/{$guild}/channels")
            && $request['permission_overwrites'][0] === ['id' => $guild, 'type' => 0, 'deny' => '1024', 'allow' => '0']);

        $body = json_encode([
            'type' => 2, 'application_id' => $application, 'guild_id' => $guild, 'channel_id' => $channel,
            'token' => 'test-token', 'member' => ['user' => ['id' => $officer]],
            'data' => ['name' => 'guildhall', 'options' => [['name' => 'approve', 'options' => [
                ['name' => 'code', 'value' => $ticket->code],
            ]]]],
        ]);
        $timestamp = (string) time();
        $signature = bin2hex(sodium_crypto_sign_detached($timestamp.$body, sodium_crypto_sign_secretkey($key)));
        $this->call('POST', '/api/discord/interactions', [], [], [], [
            'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp, 'HTTP_X_SIGNATURE_ED25519' => $signature,
        ], $body)->assertOk()->assertJsonPath('type', 5);
        $this->assertDatabaseHas('jobs', ['queue' => 'default']);
        $this->artisan('queue:work database --once --tries=1')->assertExitCode(0);
        $this->assertDatabaseHas('verifications', [
            'discord_user_id' => $member, 'status' => 'approved', 'reviewer_id' => $officer,
        ]);
        $this->get('/dashboard')->assertOk();
    }
}
