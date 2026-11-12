import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import axios from 'axios';
import { Workspace } from '../components/Chrome';
import { GuildLootTallies, type CharacterLootTally } from '../components/LootTallies';
import { mergeLootEntries, parseEntries, parseLoggerCsv, type LootInput } from '../lib/loot-import';
import { lootTotals, type LootAmount } from '../lib/loot-totals';

type LootEntry = LootInput & { id: string };
type SavedLog = { id: string; title: string; entries: LootEntry[]; perItem: boolean; revision: number; confirmedBy: string | null };
type PageProps = {
    username: string;
    character: string;
    officer: boolean;
    lootTallies: CharacterLootTally[];
    savedLog: SavedLog | null;
    savedLogs: { id: string; title: string; updated_at: string }[];
};

const silver = new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 2 });
const amountLabels = [
    ['total', 'Total loot'],
    ['lost', 'Confirmed lost'],
    ['possible', 'Possible loss after death'],
    ['banked', 'Confirmed banked'],
] as const;

function LootAmounts({ totals, summary = false }: {
    totals: ReturnType<typeof lootTotals>;
    summary?: boolean;
}) {
    function valueText(amount: LootAmount) {
        if (amount.value === null) return 'Value unavailable';
        return silver.format(amount.value).toLowerCase() + ' silver' + (amount.unpriced ? ' (partial)' : '');
    }
    return <dl className={summary ? 'loot-session-totals' : 'loot-player-totals'}>
        {amountLabels.map(([key, label]) => {
            const amount = totals[key];
            return <div key={key} className={'loot-amount loot-amount-' + key}>
                <dt>{label}</dt>
                <dd>
                    <strong title={amount.value === null ? undefined : amount.value.toLocaleString('en-US') + ' silver'}>{valueText(amount)}</strong>
                    <span>{amount.quantity.toLocaleString('en-US')} items</span>
                    {amount.unpriced > 0 && <small>{amount.unpriced} records without a value</small>}
                </dd>
            </div>;
        })}
    </dl>;
}

function LootIcon({ itemId, item }: { itemId?: string; item: string }) {
    const [failed, setFailed] = useState(false);
    const identifier = itemId || (/^T[1-8]_[A-Z0-9_]+(?:@[1-4])?$/.test(item) ? item : null);

    return (
        <div className="loot-item-icon">
            {identifier && !failed ? (
                <img src={'https://render.albiononline.com/v1/item/' + encodeURIComponent(identifier) + '.png?size=128'}
                    width={88} height={88} alt={item} loading="lazy" decoding="async"
                    referrerPolicy="no-referrer" onError={() => setFailed(true)} />
            ) : (
                <span className="loot-item-fallback" role="img" aria-label={'Image unavailable for ' + item}>
                    <svg aria-hidden="true" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.4">
                        <path d="m12 3 9 5-9 5-9-5 9-5Zm-9 5v9l9 5 9-5V8M12 13v9" />
                    </svg>
                    <span>No image</span>
                </span>
            )}
        </div>
    );
}

const utc = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium', timeStyle: 'short', timeZone: 'UTC', hourCycle: 'h23',
});

export default function LootLogs(props: PageProps) {
    return <LootLogEditor key={props.savedLog?.id ?? 'new'} {...props} />;
}

function LootLogEditor({ username, character, officer, savedLog = null, savedLogs = [], lootTallies }: PageProps) {
    const [entries, setEntries] = useState<LootEntry[]>(savedLog?.entries ?? []);
    const [title, setTitle] = useState(savedLog?.title ?? 'Loot session ' + new Date().toISOString().slice(0, 10));
    const [saving, setSaving] = useState(false);
    const [revision, setRevision] = useState(savedLog?.revision ?? 0);
    const saveInFlight = useRef(false);
    const sessionId = useRef(savedLog?.id ?? crypto.randomUUID());
    const signature = JSON.stringify({ entries, title, perItem: savedLog?.perItem ?? false });
    const savedSignature = useRef(savedLog ? signature : null);
    const dirty = useRef(false);
    const leavingAfterSave = useRef(false);
    const [view, setView] = useState<'session' | 'tallies'>('session');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(0);
    const [player, setPlayer] = useState(character);
    const [item, setItem] = useState('');
    const [quantity, setQuantity] = useState('1');
    const [source, setSource] = useState('');
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [importing, setImporting] = useState(false);
    const [perItem, setPerItem] = useState(savedLog?.perItem ?? false);
    const currentSignature = JSON.stringify({ entries, title, perItem });
    dirty.current = savedSignature.current !== null
        ? currentSignature !== savedSignature.current
        : entries.length > 0;
    const totals = lootTotals(entries, perItem);

    useEffect(() => {
        function beforeUnload(event: BeforeUnloadEvent) {
            if (dirty.current) {
                event.preventDefault();
                event.returnValue = '';
            }
        }
        window.addEventListener('beforeunload', beforeUnload);
        const unsubscribe = router.on('before', () => {
            if (saveInFlight.current && !leavingAfterSave.current) return false;
            if (leavingAfterSave.current || !dirty.current) return;
            return window.confirm('This loot log has unsaved changes. Leave without saving?');
        });
        return () => {
            window.removeEventListener('beforeunload', beforeUnload);
            unsubscribe();
        };
    }, []);

    async function saveLog(confirmOutcomes = false) {
        if (saveInFlight.current) return;
        saveInFlight.current = true;
        setSaving(true);
        setError('');
        try {
            const response = await axios.post<{ id: string; revision: number }>('/loot-logs/save', {
                id: sessionId.current, revision, title, perItem, entries, confirmOutcomes,
            }, { headers: { Accept: 'application/json' } });
            savedSignature.current = currentSignature;
            setRevision(response.data.revision);
            dirty.current = false;
            leavingAfterSave.current = true;
            router.visit('/loot-logs?log=' + encodeURIComponent(response.data.id), {
                preserveState: false,
                onFinish: () => { leavingAfterSave.current = false; saveInFlight.current = false; setSaving(false); },
            });
        } catch (reason) {
            const status = axios.isAxiosError(reason) ? reason.response?.status : undefined;
            setError(status === 409
                ? 'Another tab changed this log. Export your draft, then reopen the saved log before merging.'
                : status === 419 || status === 401
                    ? 'Your login needs refreshing. Export this draft before signing in again.'
                    : 'The log could not be saved. Check your connection and try again, or export this draft.');
            setSaving(false);
            saveInFlight.current = false;
        }
    }

    const term = search.trim().toLowerCase();
    const collectors = new Map<string, LootEntry[]>();
    for (const entry of entries) {
        const key = entry.player.trim().toLowerCase();
        const records = collectors.get(key);
        if (records) records.push(entry);
        else collectors.set(key, [entry]);
    }
    const matches = [...collectors.values()].filter((records) => records.some((entry) =>
        [entry.player, entry.item, entry.itemId, entry.source, entry.guild, entry.sourceGuild, entry.location]
            .some((value) => value?.toLowerCase().includes(term))));
    const pages = Math.max(1, Math.ceil(matches.length / 10));
    const current = Math.min(page, pages - 1);

    function addEntry(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setError('');
        try {
            if (entries.length >= 5000) throw new Error('Export these records before starting a new set.');
            const [entry] = parseEntries([{ player, item, quantity: Number(quantity), source, recordedAt: new Date().toISOString() }]);
            setEntries((previous) => [{ ...entry, id: crypto.randomUUID() }, ...previous]);
            setItem('');
            setQuantity('1');
            setPage(0);
            setNotice('Loot record added.');
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : 'Unable to add this record.');
        }
    }

    function exportEntries() {
        const body = JSON.stringify(entries.map(({ id: _id, ...entry }) => entry), null, 2);
        const url = URL.createObjectURL(new Blob([body], { type: 'application/json' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = 'guildhall-loot-' + new Date().toISOString().slice(0, 10) + '.json';
        link.click();
        URL.revokeObjectURL(url);
    }

    return (
        <Workspace username={username} character={character} officer={officer}>
            <Head title="Loot Logs" />
            <fieldset disabled={saving}>
            <div className="page-heading">
                <div>
                    <p className="eyebrow">CONSCRIPTED · ALBION ASIA</p>
                    <h1>Loot Logs<span>.</span></h1>
                    <p className="subtle">Manage a loot session or browse confirmed guild totals.</p>
                </div>
            </div>
            <div className="loot-view-switch" role="group" aria-label="Loot logs view">
                <button type="button" className={view === 'session' ? 'active' : ''} aria-pressed={view === 'session'} onClick={() => setView('session')}>Loot session</button>
                <button type="button" className={view === 'tallies' ? 'active' : ''} aria-pressed={view === 'tallies'} onClick={() => setView('tallies')}>Guild character tallies</button>
            </div>
            {view === 'tallies' && <><p className="notice">Totals across saved, officer-confirmed guild logs. Your current session stays open when switching views.</p><GuildLootTallies tallies={lootTallies} /></>}
            <div hidden={view !== 'session'}>
            <ol className="loot-workflow" aria-label="Loot session workflow">
                <li><span>1</span><div><strong>Choose a session</strong><small>Open a saved log or start fresh.</small></div></li>
                <li><span>2</span><div><strong>Import &amp; review</strong><small>Load files and check item outcomes.</small></div></li>
                <li><span>3</span><div><strong>Save your work</strong><small>Officers can confirm the results.</small></div></li>
            </ol>
            <section className="panel loot-saved-sessions" aria-label="Saved loot logs">
                <h3 className="loot-session-heading">1 · Choose a session</h3>
                <label>Session name<input maxLength={100} value={title} onChange={(event) => setTitle(event.target.value)} /></label>
                <label>Open a saved session
                    <select value={savedLog?.id ?? ''} onChange={(event) => {
                        if (event.target.value) router.visit('/loot-logs?log=' + encodeURIComponent(event.target.value));
                    }}>
                        <option value="" disabled>Select a saved log</option>
                        {savedLogs.map((log) => <option key={log.id} value={log.id}>{log.title}</option>)}
                    </select>
                </label>
                <Link href="/loot-logs?log=new" className="button secondary">New loot session</Link>
                <p className="subtle" role="status">{dirty.current ? 'Unsaved changes' : savedLog ? 'Saved loot session' : 'New session'} · {savedLog?.confirmedBy ? 'Officer-confirmed snapshot' : 'Awaiting officer confirmation'}. Officers can review all guild logs; other members can open their own logs.</p>
            </section>
            <section className="character-stats-section" aria-labelledby="recorded-loot">
                <div className="panel-heading">
                    <h3 id="recorded-loot">2 · Import &amp; review</h3>
                </div>
                <div className="panel battle-filters">
                    <label className="loot-file-picker">Import loot log<input disabled={importing} type="file" accept=".csv,text/csv,.json,application/json" aria-describedby="loot-file-help" onChange={async (event) => {
                        const file = event.currentTarget.files?.[0];
                        event.currentTarget.value = '';
                        if (!file) return;
                        setError('');
                        setImporting(true);
                        try {
                            if (file.size > 2_000_000) throw new Error('Choose a file smaller than 2 MB.');
                            const content = await file.text();
                            const importedFile = file.name.toLowerCase().endsWith('.csv')
                                ? parseLoggerCsv(content)
                                : { entries: parseEntries(JSON.parse(content)), deathEvents: 0 };
                            const imported = importedFile.entries;
                            const rows = imported.map((entry) => ({ ...entry, id: crypto.randomUUID() }));
                            const merged = mergeLootEntries(entries, rows);
                            setEntries(merged.entries);
                            setPage(0);
                            setNotice('Imported ' + merged.added + ' new records · Skipped ' + merged.duplicates + ' duplicates. '
                                + importedFile.deathEvents + ' death events checked for possible losses.');
                        } catch (reason) {
                            setError(reason instanceof Error ? reason.message : 'Unable to import records.');
                        } finally {
                            setImporting(false);
                        }
                    }} /><span id="loot-file-help" className="subtle">{importing ? 'Importing records…' : 'Choose a loot logger CSV or Guildhall JSON file.'}</span></label>
                </div>
            <details className="loot-manual-entry">
                <summary>Add a record manually</summary>
                <form onSubmit={addEntry}>
                    <div className="loot-form">
                        <label>Collected by<input required maxLength={64} value={player} onChange={(event) => setPlayer(event.target.value)} /></label>
                        <label>Item<input required maxLength={200} placeholder="Item name or item ID" value={item} onChange={(event) => setItem(event.target.value)} /></label>
                        <label>Quantity<input required type="number" min={1} max={Number.MAX_SAFE_INTEGER} step={1} value={quantity} onChange={(event) => setQuantity(event.target.value)} /></label>
                        <label>Source or battle ID<input maxLength={120} placeholder="Optional" value={source} onChange={(event) => setSource(event.target.value)} /></label>
                    </div>
                    <button className="button" type="submit" disabled={importing}>Add record</button>
                </form>
            </details>
                {error && <p className="auth-message" role="alert">{error}</p>}
                {notice && <p className="subtle zvz-description" role="status">{notice}</p>}
                {entries.length > 0 && <>
                    <details className="loot-value-basis"><summary>Price settings</summary>
                        <label>Logger market value basis
                            <select value={perItem ? 'item' : 'stack'} onChange={(event) => setPerItem(event.target.value === 'item')}>
                                <option value="stack">Per looted stack — sum CSV values</option>
                                <option value="item">Per item — multiply by quantity</option>
                            </select>
                        </label>
                        <p className="subtle">Choose how your logger records values. Totals cover all loaded records, including players outside the search.</p>
                    </details>
                    <LootAmounts totals={totals} summary />
                </>}
                {entries.length > 0 && <p className="subtle zvz-description">Select an item icon to see its loot details. Each page shows up to 10 players.</p>}
                {entries.length > 0 && <p className="subtle zvz-description">Grey means lost or a later death was recorded. Green means manually confirmed banked. Deaths alone leave item loss unconfirmed.</p>}
                <div className="panel-heading loot-records-heading">
                    <h3>Loot by player ({collectors.size})</h3>
                    <label className="loot-record-search">Find records<input type="search" placeholder="Search player, item, guild, or location" value={search} onChange={(event) => { setSearch(event.target.value); setPage(0); }} /></label>
                </div>
                {matches.length ? <ul className="loot-player-list" aria-label="Loot by player">
                    {matches.slice(current * 10, (current + 1) * 10).map((records) => {
                        const collector = records[0];
                        return (
                            <li key={collector.player.trim().toLowerCase()} className="panel loot-player-row">
                                <div className="loot-player-identity">
                                    <h4>{collector.player}</h4>
                                    {collector.guild && <p className="subtle">{collector.alliance ? '[' + collector.alliance + '] ' : ''}{collector.guild}</p>}
                                    <p className="subtle">{records.length} loot records</p>
                                    <LootAmounts totals={lootTotals(records, perItem)} />
                                </div>
                                <ul className="loot-player-items" aria-label={'Items looted by ' + collector.player}>
                                    {records.map((entry) => (
                                        <li key={entry.id} className={entry.status === 'banked' ? 'loot-banked' : entry.status === 'lost' || entry.deathRecordedAt && entry.status !== 'unknown' ? 'loot-lost' : ''}>
                                            <details className="loot-icon-details" name="loot-item-details">
                                                <summary aria-label={entry.item + ', quantity ' + entry.quantity + ', ' + (entry.status === 'banked' ? 'confirmed banked' : entry.status === 'lost' ? 'confirmed lost' : entry.deathRecordedAt && entry.status !== 'unknown' ? 'loss unconfirmed after death' : 'status unknown') + '. View loot details.'} title={entry.item + ' ×' + entry.quantity}>
                                                    <span className="loot-icon-stack">
                                                        <LootIcon itemId={entry.itemId} item={entry.item} />
                                                        <span className="loot-quantity">×{entry.quantity}</span>
                                                    </span>
                                                </summary>
                                                <div className="loot-icon-info">
                                                    <strong>{entry.item}</strong>
                                                    {entry.itemId && <p className="subtle">{entry.itemId}</p>}
                                                    <p>Looted from {entry.source || 'unknown source'}</p>
                                                    {entry.sourceGuild && <p className="subtle">{entry.sourceAlliance ? '[' + entry.sourceAlliance + '] ' : ''}{entry.sourceGuild}</p>}
                                                    {entry.location && <p>{entry.location}</p>}
                                                    <time dateTime={entry.recordedAt}>{utc.format(new Date(entry.recordedAt))} UTC</time>
                                                    <p>Logger market value {entry.marketValue == null ? '—' : entry.marketValue.toLocaleString('en-US')}</p>
                                                    {entry.deathRecordedAt && <p>Later death recorded {utc.format(new Date(entry.deathRecordedAt))} UTC. Confirm whether this item was still carried.</p>}
                                                    <label className="loot-status-control">Item outcome
                                                        <select disabled={importing} value={entry.status ?? (entry.deathRecordedAt ? 'death' : 'unknown')} onChange={(event) => {
                                                            const value = event.target.value;
                                                            setEntries((previous) => previous.map((record) => record.id === entry.id
                                                                ? { ...record, status: value === 'death' ? undefined : value as 'unknown' | 'lost' | 'banked' }
                                                                : record));
                                                        }}>
                                                            {entry.deathRecordedAt && <option value="death">Death recorded · loss unconfirmed</option>}
                                                            <option value="unknown">Unknown / still carried</option>
                                                            <option value="lost">Confirmed lost</option>
                                                            <option value="banked">Confirmed banked</option>
                                                        </select>
                                                    </label>
                                                </div>
                                            </details>
                                        </li>
                                    ))}
                                </ul>
                            </li>
                        );
                    })}
                </ul> : <div className="panel empty-page">
                    <h3>{entries.length ? 'No matching records' : 'No loot recorded yet'}</h3>
                    <p className="subtle">{entries.length ? 'Try another player, item, or source.' : 'Import your loot logger CSV above, or add a record manually.'}</p>
                </div>}
                {pages > 1 && <nav className="inbox-pagination battle-pagination" aria-label="Player loot pages">
                    <button className="button secondary" disabled={current === 0} onClick={() => setPage(current - 1)}>Previous</button>
                    <span>Page {current + 1} of {pages}</span>
                    <button className="button secondary" disabled={current + 1 === pages} onClick={() => setPage(current + 1)}>Next</button>
                </nav>}
            </section>
            <div className="panel loot-save-bar">
                <div><strong>3 · Save session</strong><p className="subtle" role="status">{dirty.current ? 'You have unsaved changes.' : savedLog ? 'Session saved.' : 'Ready to start.'}</p>
                    <small>A regular save requires officer review again. Confirming publishes banked/lost outcomes to character tallies.</small></div>
                <div className="character-actions">
                    <button className="button secondary" disabled={!entries.length} onClick={exportEntries}>Export JSON</button>
                    <button className={officer ? 'button secondary' : 'button'} disabled={importing || !title.trim()} onClick={() => void saveLog()}>{saving ? 'Saving…' : 'Save loot log'}</button>
                    {officer && <button className="button" disabled={importing || !title.trim() || !entries.length} onClick={() => void saveLog(true)}>Save &amp; confirm outcomes</button>}
                </div>
            </div>
            </div>
            </fieldset>
        </Workspace>
    );
}

