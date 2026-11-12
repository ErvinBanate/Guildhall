import { useState } from 'react';

type Amount = { quantity: string; value: number | null; unpriced: number; records: number };
export type CharacterLootTally = { name: string; banked: Amount; lost: Amount; conflicts: number };
const compact = new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 2 });

function AmountText({ amount }: { amount: Amount }) {
    return <><strong title={amount.value === null ? undefined : amount.value.toLocaleString('en-US') + ' estimated silver'}>
        {amount.value === null ? 'Silver unavailable' : compact.format(amount.value).toLowerCase() + ' silver'}
    </strong><p className="subtle">
        {BigInt(amount.quantity).toLocaleString('en-US')} items{amount.value !== null && ' · Estimated value'}
        {amount.unpriced > 0 && <> · {amount.unpriced} unpriced/ambiguous records{amount.value !== null ? ' (partial value)' : ''}</>}
    </p></>;
}

export function PersonalLootTally({ tally }: { tally: CharacterLootTally | null }) {
    return <section className="panel character-stats-section"><h3>Confirmed character loot</h3>
        <p className="subtle zvz-description">Across officer-confirmed guild logs. Matched by logger character name; these are recorded outcomes, not Albion API statistics.</p>
        {tally ? <><div className="stats-grid"><div className="stat-card"><div>Confirmed banked</div><AmountText amount={tally.banked} /></div>
            <div className="stat-card"><div>Confirmed lost</div><AmountText amount={tally.lost} /></div></div>
            {tally.conflicts > 0 && <p className="notice">{tally.conflicts} conflicting loot events excluded. An officer must reconcile the saved logs.</p>}</>
            : <p>No officer-confirmed loot recorded for this character yet.</p>}
    </section>;
}

export function GuildLootTallies({ tallies }: { tallies: CharacterLootTally[] }) {
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(0);
    const matches = tallies.filter(row => row.name.toLowerCase().includes(search.trim().toLowerCase()));
    const current = Math.min(page, Math.max(0, Math.ceil(matches.length / 10) - 1));
    return <section className="character-stats-section"><div className="page-heading"><div><h3>Saved character loot tallies</h3>
        <p className="subtle">Combined officer-confirmed guild logs · {tallies.length} characters</p></div>
        <label>Find character<input type="search" placeholder="Character name" value={search} onChange={event => { setSearch(event.target.value); setPage(0); }} /></label></div>
        <p className="subtle zvz-description">Only saved, officer-confirmed outcomes count. Overlapping events count once. Conflicting banked/lost confirmations are excluded. Character names come from the logger; ownership is not verified by the log.</p>
        <div className="panel battle-table-wrap"><table className="battle-table"><thead><tr><th>Character</th><th>Confirmed banked</th><th>Confirmed lost</th><th>Conflicting events</th></tr></thead><tbody>
            {matches.slice(current * 10, current * 10 + 10).map(row => <tr key={row.name.toLowerCase()}><th scope="row">{row.name}</th><td><AmountText amount={row.banked} /></td><td><AmountText amount={row.lost} /></td><td>{row.conflicts}</td></tr>)}
            {matches.length === 0 && <tr><td colSpan={4}>No matching officer-confirmed loot yet.</td></tr>}
        </tbody></table></div>
        {matches.length > 10 && <nav className="inbox-pagination battle-pagination" aria-label="Character loot tally pages"><button className="button secondary" disabled={current === 0} onClick={() => setPage(current - 1)}>Previous</button><span>Page {current + 1} of {Math.ceil(matches.length / 10)}</span><button className="button secondary" disabled={(current + 1) * 10 >= matches.length} onClick={() => setPage(current + 1)}>Next</button></nav>}
    </section>;
}
