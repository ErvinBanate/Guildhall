import { Deferred, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Workspace } from '../components/Chrome';

type Member = { id: string; name: string };
export default function Characters({ username, character, officer, roster }: {
    username: string; character: string; officer: boolean; roster?: Member[];
}) {
    return <Workspace username={username} character={character} officer={officer}>
        <Head title="Guild characters" />
        <div className="page-heading"><div><p className="eyebrow">CONSCRIPTED · ALBION ASIA</p><h1>Characters<span>.</span></h1>
            <p className="subtle">Find a guild member and open their character overview.</p></div>
            <button className="button secondary" onClick={() => router.reload({ only: ['roster'] })}>Refresh roster</button></div>
        <p className="subtle zvz-description">Current Albion roster. An overview shows Gameinfo fame, the character’s recorded ZvZ reports and officer-confirmed loot. Roster membership does not verify Discord ownership.</p>
        <Deferred data="roster" fallback={<p className="panel" role="status">Loading guild characters…</p>}>
            {roster && <Directory roster={roster} />}
        </Deferred>
    </Workspace>;
}

function Directory({ roster }: { roster: Member[] }) {
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(0);
    const rows = roster.filter(row => row.name.toLowerCase().includes(search.trim().toLowerCase()))
        .sort((a, b) => a.name.localeCompare(b.name));
    const current = Math.min(page, Math.max(0, Math.ceil(rows.length / 10) - 1));
    return <>
        <div className="panel battle-filters"><label>Search character<input type="search" placeholder="Character name" value={search} onChange={event => { setSearch(event.target.value); setPage(0); }} /></label><p className="subtle">{rows.length} of {roster.length} characters</p></div>
        <div className="panel battle-table-wrap"><table className="battle-table"><thead><tr><th>Character</th><th>Guild</th><th>Region</th><th>Overview</th></tr></thead><tbody>
            {rows.slice(current * 10, current * 10 + 10).map(row => <tr key={row.id}><th scope="row">{row.name}</th><td>Conscripted</td><td>Asia</td><td><Link className="button secondary" href={'/characters/' + encodeURIComponent(row.id)} aria-label={'View ' + row.name + ' overview'}>View overview →</Link></td></tr>)}
            {rows.length === 0 && <tr><td colSpan={4}>No characters match your search.</td></tr>}
        </tbody></table></div>
        {rows.length > 10 && <nav className="inbox-pagination battle-pagination" aria-label="Character pages"><button className="button secondary" disabled={current === 0} onClick={() => setPage(current - 1)}>Previous</button><span>Page {current + 1} of {Math.ceil(rows.length / 10)}</span><button className="button secondary" disabled={(current + 1) * 10 >= rows.length} onClick={() => setPage(current + 1)}>Next</button></nav>}
    </>;
}
