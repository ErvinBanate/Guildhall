import { Deferred, Head, Link, router } from '@inertiajs/react';
import { Workspace } from '../components/Chrome';
import { ZvZReports, type ZvZFeed } from '../components/ZvZReports';

export default function Battles({ username, character, officer, page, batch, scope, range, minimum, minimumOptions, zvz }: {
    username: string;
    character: string;
    officer: boolean;
    page: number;
    batch: number;
    scope: 'guild' | 'alliance';
    range: 'month' | 'lastMonth';
    minimum: number;
    minimumOptions: number[];
    zvz?: ZvZFeed | null;
}) {
    const pageUrl = (nextPage: number, nextBatch = batch) => '/battles?' + new URLSearchParams({
        scope, range, minimum: String(minimum), page: String(nextPage), batch: String(nextBatch),
    });

    return (
        <Workspace username={username} character={character} officer={officer}>
            <Head title="Battle Board" />
            <div className="page-heading">
                <div>
                    <p className="eyebrow">CONSCRIPTED · ALBION ASIA</p>
                    <h1>Battle Board<span>.</span></h1>
                    <p className="subtle">Browse battles involving Conscripted or its current alliance.</p>
                </div>
                <span className="tag">REPORT SET {batch} · PAGE {page}</span>
            </div>
            <div className="panel battle-filters" aria-label="Battle filters">
                <label>Count participants from
                    <select value={scope} onChange={(event) => router.get('/battles', { scope: event.target.value, range, minimum })}>
                        <option value="guild">Conscripted only</option>
                        <option value="alliance">Conscripted + alliance</option>
                    </select>
                </label>
                <label>Minimum participants
                    <select value={minimum} onChange={(event) => router.get('/battles', { scope, range, minimum: event.target.value })}>
                        {minimumOptions.map((option) => <option key={option} value={option}>{option}</option>)}
                    </select>
                </label>
                <label>Battle period
                    <select value={range} onChange={(event) => router.get('/battles', { scope, minimum, range: event.target.value })}>
                        <option value="month">This month</option>
                        <option value="lastMonth">Previous month</option>
                    </select>
                </label>
            </div>
            <Deferred data="zvz" fallback={<p className="panel" role="status">Loading battle reports…</p>}>
                <ZvZReports feed={zvz ?? null} board={scope} minimum={minimum} />
                {zvz && !zvz.noAlliance && <p className="subtle character-stats-note">Sorted newest first within this set of {zvz.scanned} Gameinfo reports. Participant counts use the guild and alliance IDs recorded in each report. Kills and fame are for the whole battle. History is limited to reports Gameinfo still returns.</p>}
                {(batch > 1 || page > 1 || zvz?.hasMore || zvz?.hasOlder) && (
                    <nav className="inbox-pagination" aria-label="Battle Board pages">
                        {page > 1 && <Link className="button secondary" href={pageUrl(page - 1)}>Previous page</Link>}
                        {zvz?.hasMore && <Link className="button secondary" href={pageUrl(page + 1)}>Next page</Link>}
                        {batch > 1 && <Link className="button secondary" href={pageUrl(1, batch - 1)}>Previous report set</Link>}
                        {zvz?.hasOlder && <Link className="button secondary" href={pageUrl(1, batch + 1)}>Browse older reports →</Link>}
                    </nav>
                )}
            </Deferred>
        </Workspace>
    );
}
