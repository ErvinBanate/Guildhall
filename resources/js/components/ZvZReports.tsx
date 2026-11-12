import { Link, router, usePage } from '@inertiajs/react';

export type ZvZFeed = {
    reports: {
        id: number;
        startedAt: string;
        personalStats: { kills: number | null; deaths: number | null; killFame: number | null } | null;
        guildMembers: number;
        matchedMembers: number;
        alliances: string[];
        characterRecorded: boolean;
        totalKills: number | null;
        totalFame: number | null;
    }[];
    scanned: number;
    skipped: number;
    hasMore: boolean;
    hasOlder: boolean;
    noAlliance: boolean;
};

const date = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'UTC',
    hourCycle: 'h23',
});
const compact = new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 2 });
const format = (value: number | null | undefined) => value == null ? '—' : compact.format(value).toLowerCase();

export function ZvZReports({ feed, limit, board, minimum = 20, subject }: {
    feed: ZvZFeed | null;
    limit?: number;
    board?: 'guild' | 'alliance';
    minimum?: number;
    subject?: string;
}) {
    const query = usePage().url.split('?')[1];
    if (feed === null) {
        return <div className="panel zvz-state">
            <p>Albion Gameinfo battle reports are temporarily unavailable.</p>
            <button className="button secondary" onClick={() => router.reload({ only: ['zvz'] })}>Try again</button>
        </div>;
    }

    if (feed.noAlliance) {
        return <p className="panel">Gameinfo shows that Conscripted currently has no alliance.</p>;
    }

    return (
        <>
            {feed.skipped > 0 && <p className="subtle">Some Gameinfo reports could not be read.</p>}
            {feed.reports.length === 0 ? (
                <p className="panel">{board ? `No reports on this page meet the ${minimum}-participant minimum.` : `No recent reports record ${subject ?? 'your character'} in a fight with at least ${minimum} Conscripted members.`}</p>
            ) : (
                <div className="panel battle-table-wrap">
                    <table className="battle-table">
                        <caption className="sr-only">Matching Albion Asia battles</caption>
                        <thead><tr>
                            <th scope="col">Battle</th>
                            {board && <th scope="col">Conscripted</th>}
                            {board === 'alliance' && <th scope="col">Alliance total</th>}
                            <th scope="col">{board ? 'Battle kills' : subject ? 'Character kills' : 'Your kills'}</th>
                            {!board && <th scope="col">{subject ? 'Character deaths' : 'Your deaths'}</th>}
                            <th scope="col">{board ? 'Battle fame' : subject ? 'Character kill fame' : 'Your kill fame'}</th>
                            <th scope="col"><span className="sr-only">Actions</span></th>
                        </tr></thead>
                        <tbody>{feed.reports.slice(0, limit).map((battle) => (
                            <tr key={battle.id}>
                                <th scope="row">
                                    <strong>Battle #{battle.id}</strong>
                                    <time className="subtle" dateTime={battle.startedAt}>{date.format(new Date(battle.startedAt))} UTC</time>
                                    <p className="subtle">{battle.alliances.length ? battle.alliances.join(' · ') : 'No alliances listed'}</p>
                                    {board && battle.characterRecorded && <p className="status-good">Your character is recorded</p>}
                                </th>
                                {board && <td>{battle.guildMembers}</td>}
                                {board === 'alliance' && <td>{battle.matchedMembers}</td>}
                                <td>{format(board ? battle.totalKills : battle.personalStats?.kills)}</td>
                                {!board && <td>{format(battle.personalStats?.deaths)}</td>}
                                <td>{format(board ? battle.totalFame : battle.personalStats?.killFame)}</td>
                                <td><Link className="button secondary" href={'/battles/' + battle.id + (query ? '?' + query : '')}
                                    aria-label={'Details for battle ' + battle.id}>Details <span aria-hidden="true">→</span></Link></td>
                            </tr>
                        ))}</tbody>
                    </table>
                </div>
            )}
        </>
    );
}
