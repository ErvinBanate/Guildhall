import { Deferred, Head, Link, router } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';
import axios from 'axios';
import { Workspace } from '../components/Chrome';
import { exemptionRoles } from '../lib/energy-exemptions';
import type { EnergyData } from './OcPermissions';

export default function OcExemptions({ username, character, data }: { username: string; character: string; data?: EnergyData }) {
    return <Workspace username={username} character={character} officer>
        <Head title="OC Exemptions" />
        <div className="page-heading"><div><p className="eyebrow">OFFICER TOOLS · CONSCRIPTED</p><h1>OC Exemptions<span>.</span></h1>
            <p className="subtle">Manage mentors, officers and the guild master.</p></div>
            <Link href="/officer/oc" className="button secondary">← OC Permissions</Link></div>
        <p className="notice">Exempt characters bypass the 300-energy deposit requirement and balance reminders. Their balances and transactions remain recorded. Apply withdrawal permissions separately in Albion.</p>
        <Deferred data="data" fallback={<p className="panel" role="status">Loading exemptions…</p>}>
            {data && <Manager data={data} />}
        </Deferred>
    </Workspace>;
}

function Manager({ data }: { data: EnergyData }) {
    const [selected, setSelected] = useState('');
    const [role, setRole] = useState<keyof typeof exemptionRoles>('mentor');
    const [note, setNote] = useState('');
    const [search, setSearch] = useState('');
    const [showRemoved, setShowRemoved] = useState(false);
    const [page, setPage] = useState(0);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState('');
    const operation = useRef<{ signature: string; id: string } | null>(null);
    const form = useRef<HTMLFormElement>(null);
    const member = data.members.find(entry => entry.id === selected);
    const rows = data.members.filter(entry => entry.exemption && (showRemoved || entry.exemption.active)
        && entry.name.toLowerCase().includes(search.toLowerCase()));
    const currentPage = Math.min(page, Math.max(0, Math.ceil(rows.length / 10) - 1));
    function choose(id: string) {
        const entry = data.members.find(item => item.id === id);
        setSelected(id); setRole(entry?.exemption?.active ? entry.exemption.role : 'mentor'); setNote('');
    }
    async function save(event: FormEvent) {
        event.preventDefault();
        if (!member || saving) return;
        const payload = { memberId: member.account ? 'account:' + member.account.id : member.id, role,
            revision: member.exemption?.revision ?? 0, note: note.trim() };
        const signature = JSON.stringify(payload);
        if (operation.current?.signature !== signature) operation.current = { signature, id: crypto.randomUUID() };
        setSaving(true); setMessage('');
        try {
            await axios.post('/officer/oc/exemptions', { ...payload, id: operation.current.id });
            operation.current = null; setSelected(''); setNote(''); setMessage('Exemption saved.');
            router.reload({ only: ['data'] });
        } catch (error) {
            setMessage(axios.isAxiosError(error) ? error.response?.data?.message ?? 'Unable to save. Try again.' : 'Unable to save. Try again.');
        } finally { setSaving(false); }
    }
    return <>
        {!data.rosterAvailable && <p className="notice">The current roster is unavailable. Existing accounts and exemptions can still be managed.</p>}
        <form className="panel exemption-form" ref={form} onSubmit={save}>
            <h2>Assign or update exemption</h2>
            <div className="energy-form-grid">
                <label>Character<select required value={selected} onChange={event => choose(event.target.value)}>
                    <option value="">Select a character</option>
                    {data.members.map(entry => <option key={entry.id} value={entry.id}>{entry.name}{entry.unconfirmed ? ' · Unconfirmed roster membership' : ''}</option>)}
                </select></label>
                <label>Guild role<select value={role} onChange={event => setRole(event.target.value as keyof typeof exemptionRoles)}>
                    {Object.entries(exemptionRoles).map(([value, label]) => <option key={value} value={value} disabled={value === 'none' && !member?.exemption?.active}>{label}</option>)}
                </select></label>
                <label>Officer note<input required maxLength={300} value={note} onChange={event => setNote(event.target.value)} placeholder="Reason for this change" /></label>
            </div>
            <div className="character-actions"><button className="button" disabled={saving || !member || !note.trim()}>{saving ? 'Saving…' : 'Save exemption'}</button></div>
            {message && <p role="status">{message}</p>}
        </form>
        <section className="panel exemption-section"><div className="page-heading"><div><h2>Exempt characters</h2><p className="subtle">{data.members.filter(entry => entry.exemption?.active).length} active exemptions · Guild roles are assigned here by an officer.</p></div>
            <input aria-label="Search exempt characters" placeholder="Search character" value={search} onChange={event => { setSearch(event.target.value); setPage(0); }} /></div>
            <label><input type="checkbox" checked={showRemoved} onChange={event => { setShowRemoved(event.target.checked); setPage(0); }} /> Include removed exemptions</label>
            <div className="battle-table-wrap"><table className="battle-table"><thead><tr><th>Character</th><th>Guild role</th><th>Officer note</th><th>Action</th></tr></thead>
                <tbody>{rows.slice(currentPage * 10, currentPage * 10 + 10).map(entry => <tr key={entry.id}>
                    <td>{entry.name}{entry.unconfirmed && <small className="subtle"> · Unconfirmed</small>}</td>
                    <td>{entry.exemption?.active ? exemptionRoles[entry.exemption.role] : 'Removed'}</td><td>{entry.exemption?.note}</td>
                    <td><button className="button secondary" onClick={() => { choose(entry.id); form.current?.scrollIntoView({ behavior: 'smooth', block: 'center' }); }}>Manage</button></td>
                </tr>)}{rows.length === 0 && <tr><td colSpan={4}>No matching exemptions. Select a character above to add one.</td></tr>}</tbody></table></div>
            {rows.length > 10 && <div className="character-actions"><button className="button secondary" disabled={currentPage === 0} onClick={() => setPage(currentPage - 1)}>Previous</button><span>Page {currentPage + 1} of {Math.ceil(rows.length / 10)}</span><button className="button secondary" disabled={(currentPage + 1) * 10 >= rows.length} onClick={() => setPage(currentPage + 1)}>Next</button></div>}
        </section>
        <section className="panel exemption-section"><h2>Recent exemption decisions</h2><p className="subtle">Latest 30 changes. Removing an exemption restores the normal OC requirements; recorded in-game permissions remain unchanged until an officer updates them.</p>
            <div className="battle-table-wrap"><table className="battle-table"><thead><tr><th>Time (UTC)</th><th>Character</th><th>Decision</th><th>Officer Discord ID</th><th>Note</th></tr></thead><tbody>
                {data.exemptionHistory.map(event => <tr key={event.id}><td>{event.created_at} UTC</td><td>{event.player}</td><td>{event.active ? exemptionRoles[event.role] : 'Exemption removed'}</td><td>{event.officer_id}</td><td>{event.note}</td></tr>)}
                {data.exemptionHistory.length === 0 && <tr><td colSpan={5}>No exemption decisions yet.</td></tr>}
            </tbody></table></div>
        </section>
    </>;
}

