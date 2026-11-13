# Guildhall on Laravel

OC Permissions opens on Member balances, with clickable attention counts and searchable accounts. Import game log, Record action and Activity history are separate views; draft inputs and import previews remain mounted when switching. Manage on a member opens their action form with current balance/permission context. Policy help is collapsed, and exemption management stays available in the page header. Backend rules, preview validation and authorization are unchanged.

Loot Logs separates the session editor and guild-wide character tallies into two views. The editor follows Choose a session → Import & review → Save your work. Manual entry and price settings are collapsed until needed; search stays beside player records. A desktop sticky save bar keeps save/confirmation actions accessible, with a regular footer on mobile. Switching views preserves unsaved session edits and existing navigation warnings.

The Loot Logs import picker uses a prominent gold Choose File button, keyboard focus styling and a CSV/JSON format hint. Find records sits directly above the player records, separate from the import controls.

The **Characters** sidebar page lets approved guild members search the current Conscripted Asia roster (10 per page) and open another member's overview. `/characters/{AlbionPlayerId}` reuses the fame, progression, character-specific ZvZ reports and confirmed loot summary from Overview. Viewed profiles must belong to the current API roster; missing values remain unavailable. The viewer's own linked character and permissions stay unchanged. Character roster entries do not imply officer-verified Discord ownership, and raw loot logs/Discord verification records are not exposed on these profiles. No new storage or deployment steps are required.

Confirmed loot tallies display estimated silver as the primary value, with item quantities underneath. Missing silver values and partial pricing remain explicitly labeled.

Persistent character loot tallies combine saved guild logs explicitly reviewed using **Save & confirm outcomes** by an approved Discord officer/admin. Regular **Save loot log** stores a draft and removes any prior confirmation for that snapshot. Existing logs require officer review before contributing. The Loot Logs page lists all character totals with search and 10-row pagination; Overview shows totals matching the linked character's logger name. These name matches do not establish character ownership. Tallies use the saved MySQL snapshots directly, so reloads, edits and repeated saves do not accumulate extra totals. No additional save step is needed for the character tally. Run `php artisan migrate` to install the reviewer/timestamp fields.

Only explicitly banked/lost outcomes count; death markers alone are unconfirmed. Duplicate events across confirmed logs use the full UTC timestamp precision, normalized character, item ID/name, quantity and source. Contradictory banked/lost confirmations are excluded and shown as conflicts until officers reconcile all affected saved logs. Prices follow each snapshot's stack/unit setting. Missing or conflicting prices remain unavailable (partial totals are labeled). Counts are item quantities, not stack counts. Guild-wide tallies expose character summaries to approved members; raw logs remain visible only to their uploader and approved officers/admins. Fresh Discord roles are checked on each save, with origin/CSRF protections and revision checks retained.

OC Permissions → Manage exemptions opens the officer/admin-only OC Exemptions page. Assign a character as Mentor, Officer or Guild master, or select No exemption to remove their exemption. A required officer note and decision history record who changed each assignment. These characters bypass the one-time 300-energy deposit prerequisite and low/negative balance reminders while their balances, deposits and transactions remain intact. Removing an exemption restores the normal rules without rewriting recorded in-game permissions. Guild roles are assigned explicitly here; Discord roles still control access to officer tools. Changes use revision checks and idempotent requests. Apply actual withdrawal permissions manually in Albion. Run `php artisan migrate` after updating to install the exemption and audit tables.

OC Permissions is an officer/admin-only energy ledger shared across the guild. Begin each member with a verified opening balance and record subsequent deposits and withdrawals. A cumulative 300 deposited once satisfies the permission requirement, or an officer can attest to a prior qualifying deposit on the opening record. Positive balances below 300 and negative balances are flagged with a top-up target of 300. Permission actions record changes already made in Albion. Run php artisan migrate after updates to install the energy tables and import fields.

The OC game-log importer accepts pasted tab-separated Date, Player, Reason, Amount rows or a four-column text/CSV file. Supported reasons are Deposit and Withdrawal/Withdraw. Choose day/month or month/day explicitly for slash dates. ISO and year-month-day dates are also accepted; all times are treated as UTC. Set an exclusive UTC cutoff covered by the starting balances and existing manual entries, then preview projected changes before confirming. Untracked players can be initialized at zero only with explicit officer confirmation. Former members without a current roster match receive name-based accounts labeled Roster match unconfirmed, with a nullable player ID. Their debts and repayments remain visible. Ambiguous name matches and malformed rows still block the import.

Imports are atomic. Repeated and overlapping imports match normalized timestamp, player name, reason, amount and occurrence number within that identical group. Repeated identical rows in one paste retain their multiplicity. Records already entered manually have no source fingerprint, so officers must choose the cutoff to avoid counting them again. The importer rejects stale previews when balances change, and imported transactions retain their source timestamp and recording officer.

Confirm import requires at least one new transaction, no rejected rows, and the officer’s preview acknowledgement. Any unmet requirements are displayed beside the disabled button.

Loot Logs imports semicolon-delimited loot logger CSVs using timestamp_utc, looted_by__name, item_id, item_name, quantity, looted_from__name and died columns. Death events are counted and matched to earlier loot by collector name. Collector and source guild/alliance, location and the logger market value are retained. Manual entry, search, 10-row pagination and JSON export/import are supported. Name the loot session and click Save loot log to store its records, item outcomes and price basis in MySQL. Members can reopen their own saved sessions with the session selector. Approved officers/admins can review and edit all saved logs in the guild. The last opened saved session is restored when returning to Loot Logs. Unsaved changes trigger a navigation prompt. A version check prevents stale tabs from overwriting newer saved changes. Run php artisan migrate after updating to install the loot_logs table. Repeated or overlapping imports skip matching records using the full UTC timestamp, collector, item ID (or item name for manual records), quantity and source. Imports report the new and duplicate counts. Existing confirmed outcomes are preserved. Distinct timestamps remain separate, including sub-millisecond differences. Older JSON exports that already rounded timestamps cannot recover that lost precision.

Loot records are grouped into one row per collector, with item icons and quantity badges beside the player. Select an icon to see its source, time and market value. Pagination shows 10 players at a time. Item images use the imported item ID, including enchantment suffixes. Names and record details remain available when an image cannot load.

A later death in the same imported CSV greys earlier loot as loss unconfirmed. The file has no banking events or proof that a specific item was carried at death. Each item has a manual outcome selector for unknown, confirmed lost and confirmed banked. Banked icons glow green. Exported JSON retains these outcomes.

Loot summaries show silver estimates and item quantities for all loaded records and each collector. Confirmed losses, possible losses after a death and confirmed banked loot are calculated separately. Missing prices are disclosed as unpriced records. The default value basis sums the CSV market value once per recorded stack. Select per-item values if the logger exports unit prices, which multiplies each value by quantity.

The Inertia pages use the original Guildhall dark-and-gold theme, including the workspace sidebar, overview crest and character selection cards.

Once an officer approves a character, character verification and the officer inbox use the workspace sidebar. The linked-character page shows the active character and how to request a change. Officers can review tickets before their own character is approved through the inbox without the sidebar.

The dashboard reads profile fame and progression from Asia Gameinfo using the approved player ID. Recent ZvZ Reports require at least 20 distinct Conscripted characters. The Battle Board offers minimums of 7, 25, 50 and 100 for Conscripted alone or Conscripted plus its current alliance. The alliance view uses Gameinfo’s alliance battle feed and includes ally-only reports. Participant counts use guild and alliance IDs recorded in each report. The current roster can differ from the membership recorded for that fight, and the report can omit participants.

The board browses the selected Gameinfo feed in sets of up to 100 source reports, sorts each set newest first, and displays 25 matches per page. Browse older reports advances through available source sets even when the current set has no matches. History depends on Gameinfo retention. The dashboard previews up to three qualifying reports that include the linked player ID, with that character’s kills, deaths and kill fame. Missing values are displayed as unavailable. Battle reports are fetched on demand.

Each Battle Board row opens a detail page with whole-battle totals, a guild scoreboard and a searchable player scoreboard. Detail links retain the board filters. Detail pages require an approved character and a battle involving Conscripted or its current alliance.

The Battle Board labels rows by battle ID and lists all alliances named in the report.

Use the Battle period filter to browse this month or the previous month in either the guild or alliance feed. These periods use the Gameinfo month filters.

Guild Stats shows live guild profile fame and a searchable attendance table. Attendance counts distinct qualifying ZvZ reports per Albion player ID within the retrieved 100-report window and uses the current guild roster as its member list. Unconfirmed means no matching report in the sample. Attendance is calculated on demand.

Battle details include searchable alliance and guild scoreboards, combat highlights, and player and kill tables with 10 rows per page. Equipment composition and average item power use the latest observed snapshot per recorded player from up to 200 Gameinfo kill events. Damage, healing, and death fame highlights use single-event peaks. The event-derived sections may be partial or unavailable.

Guildhall uses PHP 8.2+, Laravel 12, MySQL, Inertia 2 and React. It includes Discord sign-in, Conscripted’s current Asia roster, private verification tickets, an officer inbox and approval, denial and revocation from Discord. Dashboard reloads read approval from MySQL and refresh Discord roles periodically. Discord roles are checked directly for ticket submissions, officer inbox access and decisions. Verification records represent each member’s latest request. Existing signed Worker tickets require a new request in this edition.

## Local setup

1. Install PHP, Composer and Node 22 or newer. Start MySQL and create an empty `guildhall` database. Grant a dedicated database user access to that database.
2. From the repository root run the following.

   ```powershell
   composer install
   npm ci
   Copy-Item .env.example .env
   php artisan key:generate
   ```

3. Edit `.env` with the database credentials and the server-side `DISCORD_*` values from your existing Discord application. PHP needs PDO MySQL and Sodium. Use the exact host in `APP_URL` to open the site and register `http://localhost:8000/api/auth/callback` in the Discord Developer Portal.

   If you upgraded this workspace in place, the old local Discord settings are in the ignored `.legacy-stack/apps/api/.dev.vars` backup. Copy the relevant Discord values into the new `.env`.
4. Run the following from the repository root.

   ```powershell
   php artisan migrate
   composer run dev
   ```

Open http://localhost:8000. The dev command starts Laravel, the MySQL queue worker and Vite together. The app uses database-backed sessions with a rolling inactivity lifetime of up to 400 days, subject to browser cookie limits. Sign-out invalidates the session. Enabling the bot is required for ticket creation and periodic role checks.

## Connect the Discord application

New ticket messages ask members to submit proof. Officers and admins can find decision commands and ticket codes in the Guildhall officer inbox.

Install the existing Discord application as a bot with the bot and applications.commands scopes. Grant Manage Channels, View Channels, Send Messages, Read Message History, Attach Files and Use Application Commands. From the repository root run `npm run discord:commands` if the commands need registration. Restrict the commands to the officer and administrator roles in Discord.

Set the Discord application’s Interactions Endpoint URL to the Laravel site’s public HTTPS URL ending in `/api/discord/interactions`. For local testing, use a public HTTPS tunnel. A Discord application has one interactions URL, so switching it moves command handling to Laravel. 

## Deploy

For a Railway trial, follow [the Railway deployment guide](docs/railway.md). The repository includes web and queue-worker Railpack configurations. Use three services (web, MySQL with persistent volume, decision worker), server-side secrets, database-backed sessions/cache, and the generated HTTPS origin. The frontend is built during deployment. The trial's $5 credit is usage-limited; limited accounts can restrict external API requests. No paid upgrade is assumed.

One VM can run the web server, PHP-FPM, MySQL and the queue worker. Configure the web root to `public/`, route application requests to `public/index.php`, and use HTTPS. Keep MySQL private and run the queue worker under a process manager. Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` to the public HTTPS origin, and `SESSION_SECURE_COOKIE=true`. Register the corresponding production OAuth callback. Use persistent storage for MySQL, back it up separately, and preserve the Laravel application key securely. Apply migrations and restart the queue worker after deployment.

## Verify

From the repository root run `npm run check` followed by `php artisan test`. The PHP test suite uses SQLite in memory and fakes Discord responses. A live smoke test requires a configured MySQL instance, Discord bot and public interactions endpoint.

