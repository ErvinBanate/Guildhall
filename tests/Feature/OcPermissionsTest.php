<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OcPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): void
    {
        config(['guildhall.bot' => 'test', 'guildhall.guild' => '222222222222222222',
            'guildhall.roles.officer' => '333333333333333333', 'guildhall.roles.member' => '444444444444444444']);
        Http::fake([
            'discord.com/*' => Http::response(['roles' => ['333333333333333333']]),
            'gameinfo-sgp.albiononline.com/*' => Http::response([
                ['Id' => str_repeat('A', 22), 'Name' => 'Member'],
                ['Id' => str_repeat('B', 22), 'Name' => 'Untracked'],
            ]),
        ]);
        DB::table('verifications')->insert([
            'discord_user_id' => '123456789012345678', 'player_id' => str_repeat('A', 22),
            'player_name' => 'Officer', 'code' => '0123456789', 'status' => 'approved',
        ]);
        $this->withSession(['discord_user' => [
            'id' => '123456789012345678', 'name' => 'Officer', 'role' => 'officer', 'checked' => time(),
        ]]);
    }

    private function action(string $kind, int $amount, int $revision, bool $qualified = false): array
    {
        return ['id' => (string) Str::uuid(), 'playerId' => str_repeat('A', 22), 'kind' => $kind,
            'amount' => $amount, 'revision' => $revision, 'qualifiedBefore' => $qualified, 'note' => 'Officer verified in-game log'];
    }

    public function test_exemptions_bypass_deposit_requirement_and_removal_preserves_ledger(): void
    {
        $this->officer();
        $headers = ['Origin' => config('app.url')];
        $input = ['id' => (string) Str::uuid(), 'memberId' => str_repeat('A', 22),
            'role' => 'mentor', 'revision' => 0, 'note' => 'Appointed guild mentor'];
        $this->postJson('/officer/oc/exemptions', $input, $headers)->assertOk();
        $this->postJson('/officer/oc/exemptions', $input, $headers)->assertOk();
        $this->assertDatabaseCount('energy_exemption_events', 1);
        $this->assertDatabaseEmpty('energy_accounts');
        $this->postJson('/officer/oc/exemptions', array_replace($input, ['id' => (string) Str::uuid(), 'role' => 'officer']), $headers)->assertConflict();
        $this->postJson('/officer/oc/record', $this->action('opening', 0, 0), $headers)->assertOk();
        $this->postJson('/officer/oc/record', $this->action('permission_granted', 0, 1), $headers)->assertOk();
        $this->postJson('/officer/oc/record', $this->action('withdrawal', 50, 2), $headers)->assertOk();
        $this->get('/officer/oc/exemptions')->assertOk()->assertInertia(fn (Assert $page) => $page->component('OcExemptions'));
        $this->postJson('/officer/oc/exemptions', array_replace($input, [
            'id' => (string) Str::uuid(), 'role' => 'none', 'revision' => 1, 'note' => 'Mentor term ended',
        ]), $headers)->assertOk();
        $this->assertDatabaseHas('energy_accounts', ['balance' => -50, 'deposits' => 0, 'permission_granted' => true]);
        $this->assertDatabaseCount('energy_transactions', 3);
        $this->assertDatabaseHas('energy_exemption_events', ['officer_id' => '123456789012345678', 'role' => 'none', 'active' => false]);
        $this->postJson('/officer/oc/record', $this->action('permission_revoked', 0, 3), $headers)->assertOk();
        $this->postJson('/officer/oc/record', $this->action('permission_granted', 0, 4), $headers)->assertUnprocessable();
    }

    public function test_exemption_endpoints_require_approved_officer_and_origin(): void
    {
        $this->get('/officer/oc/exemptions')->assertRedirect('/login');
        $this->officer();
        $input = ['id' => (string) Str::uuid(), 'memberId' => str_repeat('B', 22),
            'role' => 'guild_master', 'revision' => 0, 'note' => 'Guild master appointment'];
        $headers = ['Origin' => config('app.url')];
        $this->postJson('/officer/oc/exemptions', $input)->assertForbidden();
        $this->postJson('/officer/oc/exemptions', array_replace($input, ['memberId' => str_repeat('Z', 22)]), $headers)->assertUnprocessable();
        DB::table('verifications')->update(['status' => 'revoked']);
        $this->get('/officer/oc/exemptions')->assertForbidden();
        $this->postJson('/officer/oc/exemptions', $input, $headers)->assertForbidden();
        DB::table('verifications')->update(['status' => 'approved']);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['discord.com/*' => Http::response(['roles' => ['444444444444444444']])]);
        $this->get('/officer/oc/exemptions')->assertForbidden();
        $this->postJson('/officer/oc/exemptions', $input, $headers)->assertForbidden();
        $this->assertDatabaseEmpty('energy_exemptions');
    }

    public function test_deposit_requirement_is_once_and_withdrawals_flag_negative_balances(): void
    {
        $this->officer();
        $headers = ['Origin' => config('app.url')];
        $this->postJson('/officer/oc/record', $this->action('opening', 0, 0), $headers)->assertOk();
        $this->postJson('/officer/oc/record', $this->action('permission_granted', 0, 1), $headers)->assertUnprocessable();
        $deposit = $this->action('deposit', 300, 1);
        $this->postJson('/officer/oc/record', $deposit, $headers)->assertOk();
        $this->postJson('/officer/oc/record', $deposit, $headers)->assertOk();
        $this->assertSame(300, (int) DB::table('energy_accounts')->value('balance'));
        $this->assertDatabaseCount('energy_transactions', 2);
        $this->postJson('/officer/oc/record', $this->action('permission_granted', 0, 2), $headers)->assertOk();
        $this->postJson('/officer/oc/record', $this->action('withdrawal', 350, 3), $headers)->assertOk();
        $this->assertDatabaseHas('energy_accounts', ['balance' => -50, 'deposits' => 300, 'permission_granted' => true]);
        $this->postJson('/officer/oc/record', $this->action('deposit', 100, 3), $headers)->assertConflict();
        $this->assertDatabaseHas('energy_accounts', ['balance' => -50]);
        $this->get('/officer/oc', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(\App\Http\Middleware\InertiaRequests::class)->version(\Illuminate\Http\Request::create('/officer/oc')),
            'X-Inertia-Partial-Component' => 'OcPermissions', 'X-Inertia-Partial-Data' => 'data',
        ])->assertOk()->assertJsonPath('props.data.members.0.account.qualified', true)
            ->assertJsonPath('props.data.members.0.account.balance', -50)
            ->assertJsonPath('props.data.members.1.account', null);
    }

    public function test_opening_balance_does_not_automatically_claim_a_prior_deposit(): void
    {
        $this->officer();
        $headers = ['Origin' => config('app.url')];
        $this->postJson('/officer/oc/record', $this->action('opening', 500, 0), $headers)->assertOk();
        $this->postJson('/officer/oc/record', $this->action('permission_granted', 0, 1), $headers)->assertUnprocessable();
        $this->postJson('/officer/oc/record', $this->action('opening', 100, 0, true), $headers)->assertConflict();
        $this->assertDatabaseHas('energy_accounts', ['balance' => 500, 'qualified_before' => false, 'deposits' => 0]);
    }

    public function test_oc_endpoints_fail_closed_for_members_and_unapproved_officers(): void
    {
        $this->get('/officer/oc')->assertRedirect('/login');
        $this->officer();
        $draft = $this->action('opening', 0, 0);
        $this->postJson('/officer/oc/record', $draft)->assertForbidden();
        DB::table('verifications')->update(['status' => 'revoked']);
        $this->get('/officer/oc')->assertForbidden();
        $this->postJson('/officer/oc/record', $draft, ['Origin' => config('app.url')])->assertForbidden();
        DB::table('verifications')->update(['status' => 'approved']);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['discord.com/*' => Http::response(['roles' => ['444444444444444444']])]);
        $this->get('/officer/oc')->assertForbidden();
        $this->postJson('/officer/oc/record', $draft, ['Origin' => config('app.url')])->assertForbidden();
        $this->assertDatabaseEmpty('energy_accounts');
    }

    public function test_game_log_preview_import_retry_and_overlap(): void
    {
        $this->officer();
        $headers = ['Origin' => config('app.url')];
        $input = [
            'text' => "Date\tPlayer\tReason\tAmount\n2026-11-07T14:00:00Z\tMember\tDeposit\t300\n"
                ."2026-11-07T14:01:00Z\tMember\tWithdrawal\t-50",
            'dateOrder' => 'dmy', 'cutoff' => '2026-11-01T00:00:00Z',
            'initialize' => true, 'commit' => false, 'acknowledge' => false,
        ];
        $preview = $this->postJson('/officer/oc/import', $input, $headers)->assertOk()
            ->assertJsonPath('added', 2)->assertJsonPath('net', 250)->assertJsonPath('errors', [])
            ->assertJsonPath('accounts.0.after', 250)->json();
        $this->assertDatabaseEmpty('energy_accounts');
        $commit = array_replace($input, ['commit' => true, 'acknowledge' => true, 'token' => $preview['token']]);
        $this->postJson('/officer/oc/import', $commit, $headers)->assertOk()->assertJsonPath('imported', 2);
        $this->postJson('/officer/oc/import', $commit, $headers)->assertOk()
            ->assertJsonPath('imported', 0)->assertJsonPath('duplicates', 2);
        $this->assertDatabaseHas('energy_accounts', ['balance' => 250, 'deposits' => 300, 'permission_granted' => null]);
        $this->assertDatabaseCount('energy_transactions', 3);

        $input['text'] .= "\n2026-11-07T14:02:00Z\tMember\tWithdrawal\t-30";
        $preview = $this->postJson('/officer/oc/import', $input, $headers)->assertOk()
            ->assertJsonPath('added', 1)->assertJsonPath('duplicates', 2)->json();
        $this->postJson('/officer/oc/import', array_replace($input, [
            'commit' => true, 'acknowledge' => true, 'token' => $preview['token'],
        ]), $headers)->assertOk()->assertJsonPath('imported', 1);
        $this->assertDatabaseHas('energy_accounts', ['balance' => 220, 'deposits' => 300]);
    }

    public function test_import_rejects_unmatched_rows_and_stale_preview_without_partial_updates(): void
    {
        $this->officer();
        $headers = ['Origin' => config('app.url')];
        $input = [
            'text' => "2026-11-07T14:00:00Z\tMember\tDeposit\t300\n2026-11-07T14:01:00Z\tUnknown\tUnsupported\t300",
            'dateOrder' => 'dmy', 'cutoff' => '2026-11-01T00:00:00Z',
            'initialize' => true, 'commit' => false,
        ];
        $preview = $this->postJson('/officer/oc/import', $input, $headers)->assertOk()->assertJsonCount(1, 'errors')->json();
        $this->postJson('/officer/oc/import', array_replace($input, [
            'commit' => true, 'acknowledge' => true, 'token' => $preview['token'],
        ]), $headers)->assertUnprocessable();
        $this->assertDatabaseEmpty('energy_accounts');
        $input['text'] = "2026-11-07T14:00:00Z\tMember\tDeposit\t300";
        $preview = $this->postJson('/officer/oc/import', $input, $headers)->assertOk()->json();
        $this->postJson('/officer/oc/record', $this->action('opening', 100, 0), $headers)->assertOk();
        $this->postJson('/officer/oc/import', array_replace($input, [
            'commit' => true, 'acknowledge' => true, 'token' => $preview['token'],
        ]), $headers)->assertConflict();
        $this->assertDatabaseHas('energy_accounts', ['balance' => 100, 'deposits' => 0]);
        $this->postJson('/officer/oc/import', $input)->assertForbidden();
    }

    public function test_former_members_keep_separate_ledgers_without_invented_player_ids(): void
    {
        $this->officer();
        $headers = ['Origin' => config('app.url')];
        $input = [
            'text' => "2026-11-07T14:00:00Z\tFormerOne\tWithdrawal\t-10\n"
                ."2026-11-07T14:01:00Z\tFormerTwo\tDeposit\t300",
            'dateOrder' => 'dmy', 'cutoff' => '2026-11-01T00:00:00Z',
            'initialize' => true, 'commit' => false,
        ];
        $preview = $this->postJson('/officer/oc/import', $input, $headers)->assertOk()
            ->assertJsonPath('errors', [])->assertJsonCount(2, 'accounts')
            ->assertJsonPath('accounts.0.unconfirmed', true)->json();
        $commit = array_replace($input, ['commit' => true, 'acknowledge' => true, 'token' => $preview['token']]);
        $this->postJson('/officer/oc/import', $commit, $headers)->assertOk()->assertJsonPath('imported', 2);
        $this->postJson('/officer/oc/import', $commit, $headers)->assertOk()->assertJsonPath('duplicates', 2);
        $this->assertDatabaseHas('energy_accounts', ['player_name' => 'FormerOne', 'player_id' => null, 'balance' => -10]);
        $this->assertDatabaseHas('energy_accounts', ['player_name' => 'FormerTwo', 'player_id' => null, 'balance' => 300]);
        $this->get('/officer/oc', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(\App\Http\Middleware\InertiaRequests::class)->version(\Illuminate\Http\Request::create('/officer/oc')),
            'X-Inertia-Partial-Component' => 'OcPermissions', 'X-Inertia-Partial-Data' => 'data',
        ])->assertOk()->assertJsonCount(4, 'props.data.members')
            ->assertJsonPath('props.data.members.0.unconfirmed', true);
        $account = DB::table('energy_accounts')->where('player_name', 'FormerOne')->first();
        $repayment = $this->action('deposit', 10, 1);
        unset($repayment['playerId']);
        $repayment['accountId'] = $account->id;
        $this->postJson('/officer/oc/record', $repayment, $headers)->assertOk();
        $this->assertDatabaseHas('energy_accounts', ['id' => $account->id, 'balance' => 0]);
        $input['text'] .= "\n2026-11-07T14:02:00Z\tformerone\tWithdrawal\t-5";
        $preview = $this->postJson('/officer/oc/import', $input, $headers)->assertOk()
            ->assertJsonPath('added', 1)->assertJsonPath('errors', [])->json();
        $this->postJson('/officer/oc/import', array_replace($input, [
            'commit' => true, 'acknowledge' => true, 'token' => $preview['token'],
        ]), $headers)->assertOk();
        $this->assertDatabaseCount('energy_accounts', 2);
        $this->assertDatabaseHas('energy_accounts', ['id' => $account->id, 'balance' => -5]);
    }
}
