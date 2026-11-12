<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CharactersTest extends TestCase
{
    use RefreshDatabase;

    private function member(): void
    {
        DB::table('verifications')->insert([
            'discord_user_id' => '123456789012345678', 'player_id' => str_repeat('A', 22),
            'player_name' => 'Viewer', 'code' => '0123456789', 'status' => 'approved',
        ]);
        $this->withSession(['discord_user' => [
            'id' => '123456789012345678', 'name' => 'ViewerDiscord', 'role' => 'member', 'checked' => time(),
        ]]);
        Http::preventStrayRequests();
        Http::fake([
            '*/guilds/*/members' => Http::response([
                ['Id' => str_repeat('B', 22), 'Name' => 'OtherMember'],
            ]),
            '*/players/'.str_repeat('B', 22) => Http::response(['Id' => str_repeat('B', 22), 'KillFame' => 1500]),
        ]);
    }

    private function partial(string $component, string $prop): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => app(\App\Http\Middleware\InertiaRequests::class)
            ->version(\Illuminate\Http\Request::create('/characters')),
            'X-Inertia-Partial-Component' => $component, 'X-Inertia-Partial-Data' => $prop];
    }

    public function test_directory_and_profiles_require_the_viewers_own_approval(): void
    {
        $this->get('/characters')->assertRedirect('/login');
        $this->get('/characters/'.str_repeat('B', 22))->assertRedirect('/login');
        $this->member();
        DB::table('verifications')->update(['status' => 'pending']);
        $this->get('/characters')->assertRedirect('/');
        $this->get('/characters/'.str_repeat('B', 22))->assertRedirect('/');
        Http::assertNothingSent();
    }

    public function test_directory_uses_roster_and_profile_reads_selected_id_without_rebinding_viewer(): void
    {
        $this->member();
        $this->get('/characters', $this->partial('Characters', 'roster'))->assertOk()
            ->assertJsonPath('props.roster.0.id', str_repeat('B', 22));
        $this->get('/characters/'.str_repeat('B', 22))->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')->where('character', 'Viewer')->where('viewedCharacter.name', 'OtherMember')
            ->where('viewedCharacter.id', str_repeat('B', 22))->where('officer', false)->missing('ticket')->missing('savedLogs'));
        $this->get('/characters/'.str_repeat('B', 22), $this->partial('Dashboard', 'stats'))->assertOk()
            ->assertJsonPath('props.stats.killFame', 1500)->assertJsonPath('props.stats.deathFame', null);
        $this->assertDatabaseHas('verifications', ['player_name' => 'Viewer', 'player_id' => str_repeat('A', 22)]);
        $this->get('/characters/'.str_repeat('Z', 22))->assertNotFound();
        $this->get('/characters/not-an-id')->assertNotFound();
    }
}
