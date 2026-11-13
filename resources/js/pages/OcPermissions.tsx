import { Deferred, Head, Link, router } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';
import axios from 'axios';
import { Workspace } from '../components/Chrome';
import { EnergyLogImport } from '../components/EnergyLogImport';
import { exemptionRoles, type EnergyExemption } from '../lib/energy-exemptions';

type Account = { id: string; balance: number; deposits: number; qualified: boolean; permission: boolean | null; revision: number };
type Member = { id: string; name: string; unconfirmed?: boolean; exemption?: EnergyExemption; account: Account | null };
type Kind = 'opening' | 'deposit' | 'withdrawal' | 'permission_granted' | 'permission_revoked';
export type EnergyData = {
    members: Member[];
    rosterAvailable: boolean;
    transactions: { id: string; player: string; kind: Kind; amount: number; note: string; created_at: string }[];
    exemptionHistory: { id: string; player: string; role: keyof typeof exemptionRoles; active: boolean; note: string; officer_id: string; created_at: string }[];
};

const kinds: Record<Kind, string> = {
    opening: 'Opening balance', deposit: 'Deposit', withdrawal: 'Withdrawal',
    permission_granted: 'Granted in game', permission_revoked: 'Revoked in game',
};
const number = new Intl.NumberFormat('en-US');
type View = 'balances' | 'import' | 'record' | 'activity';

export default function OcPermissions({ username, character, requirement, data }: {
    username: string; character: string; requirement: number; data?: EnergyData;
}) {
    const [view, setView] = useState<View>('balances');
    return <Workspace username={username} character={character} officer>
        <Head title="OC Permissions" />
        <div className="page-heading">
            <div><p className="eyebrow">OFFICER TOOLS · CONSCRIPTED</p>
                <h1>OC Permissions<span>.</span></h1>
                <p className="subtle">Track siphoned energy balances and withdrawal access.</p>
            </div>
            <div className="character-actions">
                <Link href="/officer/oc/exemptions" className="button secondary">Manage exemptions</Link>
                <button className="button secondary" onClick={() => router.reload({ only: ['data'] })}>Refresh accounts</button>
            </div>
        </div>
        <div className="oc-navigation" role="group" aria-label="OC permissions view">
            {([['balances', 'Member balances'], ['import', 'Import game log'], ['record', 'Record action'], ['activity', 'Activity history']] as const).map(([value, label]) =>
                <button key={value} type="button" className={view === value ? 'active' : ''} aria-pressed={view === value} onClick={() => setView(value)}>{label}</button>)}
        </div>
        <details className="oc-policy"><summary>How OC permissions work</summary><p className="subtle">Members qualify after depositing {requirement} energy once. Low or negative balances prompt a top-up; recorded exemptions bypass these requirements. Apply withdrawal permissions in Albion, then record the decision here. Balances come from officer-entered records and imported logs.</p></details>
        <div hidden={view !== 'import'}>
            <div className="oc-view-intro"><h2>Import a game log</h2><p className="subtle">Paste or upload the log, check the cutoff and preview, then confirm the import.</p></div>
            <EnergyLogImport />
        </div>
        <Deferred data="data" fallback={<p className="panel" role="status">Loading energy accounts…</p>}>
            {data && <EnergyAccounts key="accounts" data={data} requirement={requirement} view={view} onView={setView} />}
        </Deferred>
    </Workspace>;
}

function EnergyAccounts({ data, requirement, view, onView }: { data: EnergyData; requirement: number; view: View; onView: (view: View) => void }) {
    const [selected, setSelected] = useState('');
    const [kind, setKind] = useState<Kind>('opening');
    const [amount, setAmount] = useState('');
    const [note, setNote] = useState('');
    const [qualifiedBefore, setQualifiedBefore] = useState(false);
    const [search, setSearch] = useState('');
    const [filter, setFilter] = useState('all');
    const [page, setPage] = useState(0);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const request = useRef<{ signature: string; id: string } | null>(null);
    const form = useRef<HTMLFormElement>(null);
    const member = data.members.find((entry) => entry.id === selected);
    const account = member?.account;
    const permissionAction = kind === 'permission_granted' || kind === 'permission_revoked';
    const known = data.members.filter((entry) => entry.account !== null && !entry.exemption?.active);
    const negative = known.filter((entry) => entry.account!.balance < 0).length;
    const low = known.filter((entry) => entry.account!.balance >= 0 && entry.account!.balance < requirement).length;
    const ready = data.members.filter((entry) => (entry.exemption?.active || entry.account?.qualified) && entry.account?.permission !== true).length;
    const untracked = data.members.filter((entry) => entry.account === null && !entry.exemption?.active).length;
    const matches = data.members.filter((entry) => entry.name.toLowerCase().includes(search.trim().toLowerCase())
        && (filter === 'all' || (filter === 'negative' && !entry.exemption?.active && entry.account !== null && entry.account.balance < 0)
            || (filter === 'low' && !entry.exemption?.active && entry.account !== null && entry.account.balance >= 0 && entry.account.balance < requirement)
            || (filter === 'ready' && (entry.exemption?.active || entry.account?.qualified) && entry.account?.permission !== true)
            || (filter === 'untracked' && !entry.exemption?.active && entry.account === null)
            || (filter === 'exempt' && entry.exemption?.active)));
    const pages = Math.max(1, Math.ceil(matches.length / 10));
    const current = Math.min(page, pages - 1);

    function selectMember(entry: Member) {
        setSelected(entry.id);
        setKind(entry.account ? 'deposit' : 'opening');
        setAmount('');
        setNote('');
        setQualifiedBefore(false);
        setError('');
    }

    async function record(event: FormEvent) {
        event.preventDefault();
        if (!member) return;
        const body = {
            ...(account ? { accountId: account.id } : { playerId: member.id }),
            kind, amount: permissionAction ? 0 : Number(amount),
            revision: account?.revision ?? 0, qualifiedBefore: kind === 'opening' && qualifiedBefore, note,
        };
        const signature = JSON.stringify(body);
        if (request.current?.signature !== signature) request.current = { signature, id: crypto.randomUUID() };
        setSaving(true);
        setError('');
        try {
            await axios.post('/officer/oc/record', { ...body, id: request.current.id }, { headers: { Accept: 'application/json' } });
            request.current = null;
            setAmount(''); setNote(''); setQualifiedBefore(false);
            if (kind === 'opening') setKind('deposit');
            setNotice('Account updated. The action is recorded in recent activity.');
            router.reload({ only: ['data'], onFinish: () => setSaving(false) });
        } catch (reason) {
            const message: unknown = axios.isAxiosError(reason) ? reason.response?.data?.message : null;
            setError(typeof message === 'string' ? message : 'Unable to record this action. Check your connection and retry.');
            setSaving(false);
        }
    }

    return <>
        {!data.rosterAvailable && <p className="auth-message" role="status">The current roster is unavailable. Existing accounts are shown; creating opening records needs a roster refresh.</p>}
        <div hidden={view !== 'balances'}>
        <div className="oc-view-intro"><h2>Member balances</h2><p className="subtle">Start with members who need attention. Select a category to filter the list, or use Manage to record an action.</p></div>
        <div className="oc-summary-grid">
            {([
                ['ready', 'Ready for permission review', ready], ['negative', 'Negative balances', negative],
                ['low', 'Low or empty balances', low], ['untracked', 'Untracked members', untracked],
            ] as const).map(([value, label, count]) => <button type="button" className={'stat-card oc-summary' + (filter === value ? ' active' : '')} aria-pressed={filter === value} key={value} onClick={() => { setFilter(filter === value ? 'all' : value); setPage(0); }}><span>{label}</span><strong>{count}</strong><small>View members →</small></button>)}
        </div>
        </div>
        <div hidden={view !== 'record'}>
        <section className="panel character-stats-section">
            <div className="panel-heading"><h3>Record an account action</h3><button type="button" className="button secondary" disabled={saving} onClick={() => onView('balances')}>← Member balances</button></div>
            <p className="subtle">Start each member with a verified opening balance. Record subsequent deposits and withdrawals from the in-game log.</p>
            {member && <div className="oc-account-context"><strong>{member.name}</strong><span>Balance: {account ? number.format(account.balance) : 'Not initialized'}</span><span>Permission: {account?.permission === true ? 'Granted' : account?.permission === false ? 'Revoked' : 'Not checked'}</span>{member.exemption?.active && <span>Exempt · {exemptionRoles[member.exemption.role]}</span>}</div>}
            <form ref={form} onSubmit={(event) => void record(event)}>
                <fieldset disabled={saving} className="energy-form">
                    <label>Member<select value={selected} required onChange={(event) => {
                        const entry = data.members.find((value) => value.id === event.target.value);
                        if (entry) selectMember(entry);
                    }}>
                        <option value="">Choose a character</option>
                        {data.members.map((entry) => <option key={entry.id} value={entry.id}>{entry.name}</option>)}
                    </select></label>
                    <label>Action<select value={kind} onChange={(event) => setKind(event.target.value as Kind)}>
                        {!account && <option value="opening">Opening balance</option>}
                        {account && <>
                            <option value="deposit">Deposit</option><option value="withdrawal">Withdrawal</option>
                            <option value="permission_granted" disabled={!account.qualified && !member?.exemption?.active}>Record permission granted in game</option>
                            <option value="permission_revoked">Record permission revoked in game</option>
                        </>}
                    </select></label>
                    {!permissionAction && <label>{kind === 'opening' ? 'Verified starting balance' : 'Energy amount'}
                        <input type="number" step={1} min={kind === 'opening' ? -1000000000 : 1} max={1000000000} required value={amount} onChange={(event) => setAmount(event.target.value)} />
                    </label>}
                    <label>Reference / officer note<input required maxLength={300} placeholder="Log timestamp or verification reference" value={note} onChange={(event) => setNote(event.target.value)} /></label>
                    {kind === 'opening' && <label className="energy-check">
                        <input type="checkbox" checked={qualifiedBefore} onChange={(event) => setQualifiedBefore(event.target.checked)} />
                        I verified this member deposited at least {requirement} before tracking started.
                    </label>}
                    <button className="button" disabled={!member} type="submit">{saving ? 'Recording…' : 'Record action'}</button>
                </fieldset>
            </form>
            {error && <p className="auth-message" role="alert">{error}</p>}
            {notice && <p className="subtle" role="status">{notice}</p>}
        </section>
        </div>
        <div hidden={view !== 'balances'}>
        <section className="character-stats-section" aria-labelledby="energy-members">
            <h3 id="energy-members">Accounts ({matches.length})</h3>
            <p className="subtle">Top-up amounts target a {requirement}-energy balance. Mentors, officers and the guild master with recorded exemptions are excluded from deposit and top-up requirements.</p>
            <div className="panel battle-filters">
                <label>Find member<input type="search" value={search} placeholder="Character name" onChange={(event) => { setSearch(event.target.value); setPage(0); }} /></label>
                <label>Show<select value={filter} onChange={(event) => { setFilter(event.target.value); setPage(0); }}>
                    <option value="all">All members</option><option value="negative">Negative balances</option>
                    <option value="low">Low / empty balances</option><option value="ready">Ready for permission review</option><option value="untracked">Untracked</option>
                    <option value="exempt">Exempt characters</option>
                </select></label>
            </div>
            {matches.length ? <div className="panel battle-table-wrap"><table className="battle-table">
                <thead><tr><th scope="col">Member</th><th scope="col">Balance</th><th scope="col">Deposit requirement</th><th scope="col">Permission recorded</th><th scope="col">Top-up to {requirement}</th><th scope="col">Action</th></tr></thead>
                <tbody>{matches.slice(current * 10, (current + 1) * 10).map((entry) => <tr key={entry.id}>
                    <th scope="row">{entry.name}{entry.unconfirmed && <p className="subtle">Roster match unconfirmed</p>}</th>
                    <td className={!entry.exemption?.active && entry.account && entry.account.balance < 0 ? 'energy-negative' : ''}>
                        {entry.account ? number.format(entry.account.balance) : 'Unconfirmed'}
                    </td>
                    <td>{entry.exemption?.active ? 'Exempt · ' + exemptionRoles[entry.exemption.role] : entry.account ? entry.account.qualified ? 'Verified · 300 once' : number.format(entry.account.deposits) + ' / ' + requirement + ' deposited' : 'Unconfirmed'}</td>
                    <td>{entry.account?.permission === true ? 'Granted' : entry.account?.permission === false ? 'Revoked' : 'Not checked'}</td>
                    <td>{entry.exemption?.active ? 'Exempt' : entry.account ? number.format(Math.max(0, requirement - entry.account.balance)) : '—'}</td>
                    <td><button className="button secondary" disabled={saving} onClick={() => {
                        selectMember(entry);
                        onView('record');
                        requestAnimationFrame(() => form.current?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
                    }}>Manage</button></td>
                </tr>)}</tbody>
            </table></div> : <p className="panel">No members match these filters.</p>}
            {pages > 1 && <nav className="inbox-pagination battle-pagination" aria-label="Energy account pages">
                <button className="button secondary" disabled={current === 0} onClick={() => setPage(current - 1)}>Previous</button>
                <span>Page {current + 1} of {pages}</span>
                <button className="button secondary" disabled={current + 1 === pages} onClick={() => setPage(current + 1)}>Next</button>
            </nav>}
        </section>
        </div>
        <div hidden={view !== 'activity'}>
        <section className="character-stats-section" aria-labelledby="energy-activity">
            <h3 id="energy-activity">Recent officer records</h3>
            <p className="subtle">Latest 50 recorded actions. Notes refer to the in-game transaction being entered.</p>
            {data.transactions.length ? <div className="panel battle-table-wrap"><table className="battle-table">
                <thead><tr><th scope="col">Member</th><th scope="col">Action</th><th scope="col">Amount</th><th scope="col">Reference</th></tr></thead>
                <tbody>{data.transactions.map((transaction) => <tr key={transaction.id}>
                    <th scope="row">{transaction.player}</th><td>{kinds[transaction.kind]}</td>
                    <td>{transaction.kind.startsWith('permission_') ? '—' : number.format(Number(transaction.amount))}</td>
                    <td className="energy-note">{transaction.note}</td>
                </tr>)}</tbody>
            </table></div> : <p className="panel">No energy actions recorded yet.</p>}
        </section>
        </div>
    </>;
}
