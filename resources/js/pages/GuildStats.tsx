import { Deferred, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Workspace } from '../components/Chrome';

type Stats = { name: string | null; alliance: string | null; memberCount: number | null; killFame: number | null; deathFame: number | null };
type Attendance = {
    members: { id: string; name: string; battles: number | null; lastRecorded: string | null }[];
    qualifying: number;
    scanned: number;
    skipped: number;
};

const compact = new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 2 });
const utc = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short', timeZone: 'UTC', hourCycle: 'h23' });
const pageSize = 10;

export default function GuildStats({ username, character, officer, stats, attendance }: {
    username: string; character: string; officer: boolean; stats?: Stats | null; attendance?: Attendance | null;
}) {
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(0);
    const members = attendance?.members.filter((member) => member.name.toLowerCase().includes(search.trim().toLowerCase())) ?? [];
    const pages = Math.max(1, Math.ceil(members.length / pageSize));
    const current = Math.min(page, pages - 1);

    return (
        <Workspace username={username} character={character} officer={officer}>
            <Head title="Guild Stats" />
            <div className="character-overview-grid">
                <section className="panel character-overview-profile guild-heading">
                    <div className="guild-crest">
                        <img src="/images/Cons_logo.png" alt="Conscripted guild logo" width={84} height={84} />
                    </div>
                    <p className="eyebrow">GUILD PROFILE · ALBION ASIA</p>
                    <h1>{stats?.name ?? 'Conscripted'}</h1>
                    {stats?.alliance && <p className="subtle">{stats.alliance} alliance</p>}
                    <Link href="/battles" className="button secondary">Battle Board <span aria-hidden="true">→</span></Link>
                </section>
                <section className="panel character-overview-combat" aria-labelledby="guild-overview">
                    <h2 id="guild-overview" className="guild-overview-title">Guild overview</h2>
                    <Deferred data="stats" fallback={<p className="subtle" role="status">Loading guild stats…</p>}>
                        {stats ? <div className="stats-grid">
                            {([
                                ['Guild members', stats.memberCount],
                                ['Guild kill fame', stats.killFame],
                                ['Guild death fame', stats.deathFame],
                            ] as const).map(([label, value]) => <section key={label} className="stat-card">
                                <div>{label}</div><strong>{value === null ? '—' : compact.format(value).toLowerCase()}</strong>
                            </section>)}
                        </div> : <p className="subtle">Guild profile stats are unavailable.</p>}
                    </Deferred>
                </section>
            </div>
            <section className="character-stats-section" aria-labelledby="guild-attendance">
                <div className="panel-heading"><div>
                    <p className="eyebrow">RECORDED PARTICIPATION</p>
                    <h3 id="guild-attendance">Member attendance</h3>
                </div></div>
                <p className="subtle zvz-description">Counts use the current roster and reports with at least 20 distinct Conscripted participants. Unconfirmed means no matching record in this sample.</p>
                <Deferred data="attendance" fallback={<p className="panel" role="status">Loading member attendance…</p>}>
                    {attendance ? <>
                        <div className="panel battle-filters">
                            <label>Find a member<input type="search" placeholder="Search character name" value={search}
                                onChange={(event) => { setSearch(event.target.value); setPage(0); }} /></label>
                        </div>
                        <p className="subtle zvz-description">{attendance.qualifying} qualifying ZvZ reports from {attendance.scanned} checked. {attendance.skipped > 0 && 'Some reports could not be read.'}</p>
                        {members.length > 0 ? <div className="panel battle-table-wrap">
                            <table className="battle-table">
                                <caption className="sr-only">Recorded ZvZ participation by current guild member</caption>
                                <thead><tr><th scope="col">Member</th><th scope="col">Recorded ZvZs</th><th scope="col">Last recorded</th></tr></thead>
                                <tbody>{members.slice(current * pageSize, (current + 1) * pageSize).map((member) => (
                                    <tr key={member.id}>
                                        <th scope="row">{member.name}</th>
                                        <td>{member.battles ?? 'Unconfirmed'}</td>
                                        <td>{member.lastRecorded ? <time dateTime={member.lastRecorded}>{utc.format(new Date(member.lastRecorded))} UTC</time> : '—'}</td>
                                    </tr>
                                ))}</tbody>
                            </table>
                        </div> : <p className="panel">No matching roster members.</p>}
                        {pages > 1 && <nav className="inbox-pagination battle-pagination" aria-label="Attendance pages">
                            <button className="button secondary" disabled={current === 0} onClick={() => setPage(current - 1)}>Previous</button>
                            <span>Page {current + 1} of {pages}</span>
                            <button className="button secondary" disabled={current + 1 === pages} onClick={() => setPage(current + 1)}>Next</button>
                        </nav>}
                    </> : <div className="panel zvz-state">
                        <p>Attendance is unavailable from Albion Gameinfo.</p>
                        <button className="button secondary" onClick={() => router.reload({ only: ['stats', 'attendance'] })}>Try again</button>
                    </div>}
                </Deferred>
            </section>
        </Workspace>
    );
}
