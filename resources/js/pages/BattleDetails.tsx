import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Workspace } from '../components/Chrome';

type Score = { kills: number | null; deaths: number | null; killFame: number | null; itemPower: number | null };
type Group = Score & { id: string; name: string | null; alliance: string | null; participants: number };
type Highlight = { name: string; guild: string | null; value: number } | null;
type Battle = {
    id: number;
    startedAt: string;
    location: string | null;
    guilds: Group[];
    alliances: Group[];
    players: (Score & { id: string; name: string; guild: string | null; alliance: string | null; conscripted: boolean })[];
    composition: { weapon: string; count: number }[];
    events: {
        available: boolean;
        complete: boolean;
        kills: { id: number; killer: string; victim: string; fame: number | null; at: string | null }[];
        highlights: Record<'kills' | 'healing' | 'damage' | 'deathFame', Highlight>;
    };
};

const compact = new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 2 });
const format = (value: number | null) => value === null ? '—' : compact.format(value).toLowerCase();

function ScoreCells({ score }: { score: Score }) {
    return <><td>{format(score.kills)}</td><td>{format(score.deaths)}</td><td>{score.itemPower === null ? '—' : Math.round(score.itemPower)}</td><td>{format(score.killFame)}</td></>;
}

function Pagination({ page, count, onPage, label }: { page: number; count: number; onPage: (page: number) => void; label: string }) {
    if (count <= 10) return null;
    return <nav className="inbox-pagination battle-pagination" aria-label={label}>
        <button className="button secondary" disabled={page === 0} onClick={() => onPage(page - 1)}>Previous</button>
        <span>Page {page + 1} of {Math.ceil(count / 10)}</span>
        <button className="button secondary" disabled={(page + 1) * 10 >= count} onClick={() => onPage(page + 1)}>Next</button>
    </nav>;
}

function GroupTable({ groups, kind }: { groups: Group[]; kind: 'Alliance' | 'Guild' }) {
    const [search, setSearch] = useState('');
    const rows = groups.filter((group) => !search.trim() || group.name?.toLowerCase().includes(search.trim().toLowerCase()));
    return <section className="battle-group">
        <div className="battle-group-heading">
            <h3>{kind}s ({groups.length})</h3>
            <input className="battle-search" type="search" aria-label={'Search ' + kind.toLowerCase()} placeholder={'Search ' + kind.toLowerCase()} value={search} onChange={(event) => setSearch(event.target.value)} />
        </div>
        {rows.length ? <div className="panel battle-table-wrap">
            <table className="battle-table">
                <caption className="sr-only">{kind} battle scores</caption>
                <thead><tr><th scope="col">{kind}</th>{kind === 'Guild' && <th scope="col">Alliance</th>}<th scope="col">Players</th><th scope="col">Kills</th><th scope="col">Deaths</th><th scope="col">Avg IP</th><th scope="col">Fame</th></tr></thead>
                <tbody>{rows.map((group) => <tr key={group.id}>
                    <th scope="row">{group.name ?? 'Name unavailable'}</th>{kind === 'Guild' && <td>{group.alliance ?? '—'}</td>}
                    <td>{group.participants}</td><ScoreCells score={group} />
                </tr>)}</tbody>
            </table>
        </div> : <p className="panel">No matching {kind.toLowerCase()} records.</p>}
    </section>;
}

export default function BattleDetails({ username, character, playerId, officer, battle }: {
    username: string; character: string; playerId: string; officer: boolean; battle: Battle;
}) {
    const query = usePage().url.split('?')[1];
    const [search, setSearch] = useState('');
    const [guildOnly, setGuildOnly] = useState(false);
    const [playerPage, setPlayerPage] = useState(0);
    const [killPage, setKillPage] = useState(0);
    const term = search.trim().toLowerCase();
    const players = battle.players.filter((player) => (!guildOnly || player.conscripted)
        && [player.name, player.guild, player.alliance].some((value) => value?.toLowerCase().includes(term)));

    return (
        <Workspace username={username} character={character} officer={officer}>
            <Head title={'Battle #' + battle.id} />
            <div className="inbox-heading">
                <div>
                    <p className="eyebrow">ALBION ASIA · BATTLE #{battle.id}</p>
                    <h1>{battle.location ?? 'Battle details'}</h1>
                    <time className="subtle" dateTime={battle.startedAt}>
                        {new Date(battle.startedAt).toLocaleString(undefined, { dateStyle: 'long', timeStyle: 'short' })}
                    </time>
                </div>
                <Link className="button secondary" href={'/battles' + (query ? '?' + query : '')}>← Back to Battle Board</Link>
            </div>
            <div className="battle-groups character-stats-section">
                <GroupTable kind="Alliance" groups={battle.alliances} />
                <GroupTable kind="Guild" groups={battle.guilds} />
            </div>
            <div className="stats-grid battle-highlights">
                {([
                    ['Top kills', 'kills'], ['Top heal', 'healing'], ['Top damage', 'damage'], ['Top death fame', 'deathFame'],
                ] as const).map(([label, key]) => {
                    const highlight = battle.events.highlights[key];
                    return <section className={'stat-card battle-highlight ' + key} key={key}>
                        <p className="eyebrow">{label}</p>
                        <div><span>{highlight?.name ?? 'Unavailable'}</span><strong>{format(highlight?.value ?? null)}</strong></div>
                        {highlight?.guild && <p>{highlight.guild}</p>}
                    </section>;
                })}
            </div>
            <p className="subtle character-stats-note">Avg IP uses one observed equipment snapshot per player. Healing, damage, and death fame highlights show the highest single recorded event value.</p>
            <section className="character-stats-section" aria-labelledby="battle-players">
                <h3 id="battle-players">Player scoreboard</h3>
                <div className="panel battle-filters">
                    <label>Find a player or guild<input type="search" value={search} onChange={(event) => { setSearch(event.target.value); setPlayerPage(0); }} /></label>
                    <label className="battle-check"><input type="checkbox" checked={guildOnly} onChange={(event) => { setGuildOnly(event.target.checked); setPlayerPage(0); }} /> Conscripted only</label>
                </div>
                {players.length ? <div className="panel battle-table-wrap">
                    <table className="battle-table">
                        <caption className="sr-only">Player battle scores</caption>
                        <thead><tr><th scope="col">Player</th><th scope="col">Guild</th><th scope="col">Kills</th><th scope="col">Deaths</th><th scope="col">IP</th><th scope="col">Kill fame</th></tr></thead>
                        <tbody>{players.slice(playerPage * 10, (playerPage + 1) * 10).map((player) => <tr key={player.id}>
                            <th scope="row"><strong>{player.name}</strong>{player.id === playerId && <span className="status-good">Your character</span>}</th>
                            <td>{player.guild ?? '—'}</td><ScoreCells score={player} />
                        </tr>)}</tbody>
                    </table>
                </div> : <p className="panel">No recorded players match these filters.</p>}
                <Pagination page={playerPage} count={players.length} onPage={setPlayerPage} label="Player scoreboard pages" />
            </section>
            <section className="character-stats-section" aria-labelledby="battle-kills">
                <h3 id="battle-kills">Kills</h3>
                {battle.events.kills.length ? <div className="panel battle-table-wrap"><table className="battle-table">
                    <caption className="sr-only">Recorded battle kills</caption>
                    <thead><tr><th scope="col">Time</th><th scope="col">Killer</th><th scope="col">Victim</th><th scope="col">Fame</th></tr></thead>
                    <tbody>{battle.events.kills.slice(killPage * 10, (killPage + 1) * 10).map((kill) => <tr key={kill.id}>
                        <td>{kill.at ? <time dateTime={kill.at}>{new Date(kill.at).toLocaleTimeString()}</time> : '—'}</td>
                        <td>{kill.killer}</td><td>{kill.victim}</td><td>{format(kill.fame)}</td>
                    </tr>)}</tbody>
                </table></div> : <p className="panel">{battle.events.available ? 'No kill events were returned.' : 'Kill events are unavailable.'}</p>}
                <Pagination page={killPage} count={battle.events.kills.length} onPage={setKillPage} label="Kill event pages" />
                {battle.events.available && !battle.events.complete && <p className="subtle character-stats-note">Showing a partial event feed, up to 200 reports.</p>}
            </section>
            <section className="character-stats-section" aria-labelledby="battle-composition">
                <h3 id="battle-composition">Player composition</h3>
                <p className="subtle">Main-hand weapons observed in kill reports. Players without a weapon snapshot are excluded.</p>
                {battle.composition.length ? <div className="panel battle-table-wrap battle-composition"><table className="battle-table">
                    <caption className="sr-only">Observed main-hand weapons</caption>
                    <thead><tr><th scope="col">Weapon</th><th scope="col">Recorded players</th></tr></thead>
                    <tbody>{battle.composition.map(({ weapon, count }) => <tr key={weapon}>
                        <th scope="row">{weapon}</th><td>{count}</td>
                    </tr>)}</tbody>
                </table></div> : <p className="panel">Weapon snapshots are unavailable.</p>}
            </section>
            <p className="subtle character-stats-note">Scores come from the Gameinfo battle report. A dash means the value is unavailable.</p>
        </Workspace>
    );
}
