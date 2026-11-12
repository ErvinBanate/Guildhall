<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LootLogsTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $id = '123456789012345678'): void
    {
        config(['guildhall.bot' => 'test', 'guildhall.guild' => '222222222222222222',
            'guildhall.roles.member' => '333333333333333333']);
        Http::fake(['discord.com/*' => Http::response(['roles' => ['333333333333333333']])]);
        DB::table('verifications')->insert([
            'discord_user_id' => $id, 'player_id' => str_repeat('A', 22),
            'player_name' => 'Member', 'code' => '0123456789', 'status' => 'approved',
        ]);
        $this->withSession(['discord_user' => [
            'id' => $id, 'name' => 'Member', 'role' => 'member', 'checked' => time(),
        ]]);
    }

    private function draft(): array
    {
        return [
            'id' => (string) Str::uuid(), 'revision' => 0, 'title' => 'Saturday ZvZ', 'perItem' => true,
            'entries' => [[
                'id' => (string) Str::uuid(), 'player' => 'Member', 'item' => 'Item', 'quantity' => 4,
                'source' => '', 'recordedAt' => '2026-11-07T14:05:29.0416371Z',
                'marketValue' => 1200, 'status' => 'banked', 'guild' => '',
            ]],
        ];
    }

    public function test_saved_log_restores_records_and_settings_and_retries_are_idempotent(): void
    {
        $this->member();
        $draft = $this->draft();
        $headers = ['Origin' => config('app.url')];
        $this->postJson('/loot-logs/save', $draft, $headers)->assertOk()->assertJsonPath('revision', 1);
        $this->postJson('/loot-logs/save', $draft, $headers)->assertOk()->assertJsonPath('revision', 1);
        $this->assertDatabaseCount('loot_logs', 1);
        $this->get('/loot-logs?log='.$draft['id'])->assertInertia(fn (Assert $page) => $page
            ->component('LootLogs')->where('savedLog.title', $draft['title'])
            ->where('savedLog.perItem', true)->where('savedLog.revision', 1)
            ->where('savedLog.entries.0.recordedAt', $draft['entries'][0]['recordedAt'])
            ->where('savedLog.entries.0.status', 'banked')->where('savedLog.entries.0.source', '')
            ->has('savedLogs', 1));
        $this->get('/loot-logs')->assertInertia(fn (Assert $page) => $page
            ->where('savedLog.id', $draft['id']));
        $draft['revision'] = 1;
        $draft['entries'][0]['status'] = 'lost';
        $this->postJson('/loot-logs/save', $draft, $headers)->assertOk()->assertJsonPath('revision', 2);
        $draft['entries'][0]['status'] = 'unknown';
        $this->postJson('/loot-logs/save', $draft, $headers)->assertConflict();
        $this->assertSame('lost', json_decode(DB::table('loot_logs')->value('entries'), true)[0]['status']);
    }

    public function test_other_members_cannot_read_or_overwrite_private_logs(): void
    {
        $this->member();
        $draft = $this->draft();
        $this->postJson('/loot-logs/save', $draft, ['Origin' => config('app.url')])->assertOk();
        $this->flushSession();
        $this->member('123456789012345679');
        $this->get('/loot-logs')->assertInertia(fn (Assert $page) => $page->has('savedLogs', 0));
        $this->get('/loot-logs?log='.$draft['id'])->assertNotFound();
        $draft['revision'] = 1;
        $this->postJson('/loot-logs/save', $draft, ['Origin' => config('app.url')])->assertNotFound();
    }

    public function test_saving_requires_approval_origin_and_valid_records(): void
    {
        $this->member();
        $draft = $this->draft();
        $this->postJson('/loot-logs/save', $draft)->assertForbidden();
        $this->postJson('/loot-logs/save', $draft, ['Origin' => 'https://other.example'])->assertForbidden();
        $draft['entries'][0]['quantity'] = -1;
        $this->postJson('/loot-logs/save', $draft, ['Origin' => config('app.url')])
            ->assertUnprocessable()->assertJsonValidationErrors('entries.0.quantity');
        DB::table('verifications')->update(['status' => 'revoked']);
        $this->postJson('/loot-logs/save', $this->draft(), ['Origin' => config('app.url')])->assertForbidden();
        $this->assertDatabaseEmpty('loot_logs');
    }

    public function test_officer_review_publishes_shared_totals_and_regular_edits_require_review_again(): void
    {
        $this->member();
        $headers = ['Origin' => config('app.url')];
        $draft = $this->draft();
        $this->postJson('/loot-logs/save', $draft + ['confirmOutcomes' => true], $headers)->assertForbidden();
        $this->postJson('/loot-logs/save', $draft, $headers)->assertOk();
        $this->assertSame([], app(\App\Services\LootTallies::class)->guild());
        $this->flushSession();
        $this->member('123456789012345679');
        config(['guildhall.roles.officer' => '333333333333333333']);
        $this->withSession(['discord_user' => ['id' => '123456789012345679', 'name' => 'Reviewer', 'role' => 'officer', 'checked' => time()]]);
        $this->get('/loot-logs?log='.$draft['id'])->assertInertia(fn (Assert $page) => $page->where('savedLog.id', $draft['id']));
        $draft['revision'] = 1;
        $draft['confirmOutcomes'] = true;
        $this->postJson('/loot-logs/save', $draft, $headers)->assertOk();
        $this->postJson('/loot-logs/save', $draft, $headers)->assertOk();
        $this->assertDatabaseHas('loot_logs', ['owner_id' => '123456789012345678', 'confirmed_by' => '123456789012345679']);
        $this->get('/loot-logs')->assertInertia(fn (Assert $page) => $page
            ->where('lootTallies.0.banked.quantity', '4')->where('lootTallies.0.banked.value', 4800));
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('lootTally.banked.quantity', '4'));
        $copy = $draft;
        $copy['id'] = (string) Str::uuid(); $copy['revision'] = 0;
        $copy['entries'][0]['id'] = (string) Str::uuid();
        $this->postJson('/loot-logs/save', $copy, $headers)->assertOk();
        $this->assertSame('4', app(\App\Services\LootTallies::class)->guild()[0]['banked']['quantity']);
        $copy['revision'] = 1; $copy['entries'][0]['status'] = 'lost';
        $this->postJson('/loot-logs/save', $copy, $headers)->assertOk();
        $this->assertSame(1, app(\App\Services\LootTallies::class)->guild()[0]['conflicts']);
        $draft['revision'] = 2; $draft['entries'][0]['status'] = 'lost';
        $this->postJson('/loot-logs/save', $draft, $headers)->assertOk();
        $this->assertSame('4', app(\App\Services\LootTallies::class)->guild()[0]['lost']['quantity']);
        // A normal save withdraws that snapshot from the authoritative tally.
        $copy['revision'] = 2; $copy['confirmOutcomes'] = false;
        $this->postJson('/loot-logs/save', $copy, $headers)->assertOk();
        $draft['revision'] = 3; $draft['confirmOutcomes'] = false;
        $this->postJson('/loot-logs/save', $draft, $headers)->assertOk();
        $this->assertSame([], app(\App\Services\LootTallies::class)->guild());
    }
}
