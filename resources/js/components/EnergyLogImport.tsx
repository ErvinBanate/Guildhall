import { router } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';

type Preview = {
    token: string;
    added: number;
    duplicates: number;
    beforeCutoff: number;
    net: number;
    errors: { line: number; message: string }[];
    accounts: { name: string; before: number; after: number; newAccount: boolean; unconfirmed: boolean }[];
};

export function EnergyLogImport() {
    const [text, setText] = useState('');
    const [cutoff, setCutoff] = useState('');
    const [dateOrder, setDateOrder] = useState('dmy');
    const [initialize, setInitialize] = useState(false);
    const [acknowledge, setAcknowledge] = useState(false);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const confirmRequirements: string[] = [];
    if (preview) {
        if (preview.errors.length > 0) {
            confirmRequirements.push('Fix the ' + preview.errors.length + ' rejected rows shown above, then preview again.');
        }
        if (preview.added === 0) {
            confirmRequirements.push('There are no new transactions to import. Check the cutoff, duplicate count, and rejected rows.');
        }
        if (!acknowledge) {
            confirmRequirements.push('Tick “I checked the preview” after verifying the cutoff and starting balances.');
        }
    }

    function resetPreview() {
        setPreview(null);
        setAcknowledge(false);
        setError('');
        setNotice('');
    }

    async function submit(commit: boolean) {
        setBusy(true);
        setError('');
        setNotice('');
        try {
            const body = {
                text, cutoff: cutoff.length === 16 ? cutoff + ':00Z' : cutoff + 'Z',
                dateOrder, initialize, commit, token: commit ? preview?.token : null, acknowledge,
            };
            if (commit) {
                const response = await axios.post<{ imported: number; duplicates: number }>('/officer/oc/import', body);
                setNotice('Imported ' + response.data.imported + ' transactions · skipped ' + response.data.duplicates + ' previously imported entries.');
                setPreview(null);
                setAcknowledge(false);
                router.reload({ only: ['data'], onFinish: () => setBusy(false) });
            } else {
                const response = await axios.post<Preview>('/officer/oc/import', body);
                setPreview(response.data);
                setBusy(false);
            }
        } catch (reason) {
            const message: unknown = axios.isAxiosError(reason) ? reason.response?.data?.message : null;
            setError(typeof message === 'string' ? message : 'Unable to process this log. Check the format and try again.');
            if (commit) setPreview(null);
            setBusy(false);
        }
    }

    return <section className="panel character-stats-section" aria-labelledby="import-energy">
        <h3 id="import-energy">Import in-game energy log</h3>
        <p className="subtle">Copy the guild’s Siphoned Energy account log and paste its Date, Player, Reason, Amount columns below. Tab-separated text and four-column CSV are supported.</p>
        <fieldset disabled={busy} className="energy-import-form">
            <label>Paste account log
                <textarea rows={7} maxLength={2000000} value={text} spellCheck={false}
                    placeholder={'Date\tPlayer\tReason\tAmount'}
                    onChange={(event) => { setText(event.target.value); resetPreview(); }} />
            </label>
            <div className="energy-form">
                <label>Or open exported log
                    <input type="file" accept=".txt,.tsv,.csv,text/plain,text/tab-separated-values,text/csv"
                        onChange={async (event) => {
                            const file = event.currentTarget.files?.[0];
                            event.currentTarget.value = '';
                            if (!file) return;
                            resetPreview();
                            setBusy(true);
                            try {
                                if (file.size > 2000000) throw new Error('Choose a log smaller than 2 MB.');
                                setText(await file.text());
                            } catch (reason) {
                                setError(reason instanceof Error ? reason.message : 'Unable to open the file.');
                            } finally { setBusy(false); }
                        }} />
                </label>
                <label>Import events after this UTC cutoff
                    <input type="datetime-local" value={cutoff} onChange={(event) => { setCutoff(event.target.value); resetPreview(); }} />
                </label>
                <label>Order for slash dates
                    <select value={dateOrder} onChange={(event) => { setDateOrder(event.target.value); resetPreview(); }}>
                        <option value="dmy">Day / month / year</option>
                        <option value="mdy">Month / day / year</option>
                    </select>
                </label>
            </div>
            <p className="subtle">Choose the point covered by your opening balances and manual entries. Earlier transactions are excluded. Dates are interpreted in UTC. Withdrawals may be signed negative; deposits must be positive.</p>
            <label className="energy-import-check">
                <input type="checkbox" checked={initialize} onChange={(event) => { setInitialize(event.target.checked); resetPreview(); }} />
                For untracked players, including former members, I confirm their balance was zero at the cutoff.
            </label>
            <button className="button secondary" disabled={!text.trim() || !cutoff} onClick={() => void submit(false)}>
                {busy ? 'Processing…' : 'Preview import'}
            </button>
            {preview && <>
                <div className="energy-preview-totals">
                    <span><strong>{preview.added}</strong> new transactions</span>
                    <span><strong>{preview.duplicates}</strong> already imported</span>
                    <span><strong>{preview.beforeCutoff}</strong> at/before cutoff</span>
                    <span><strong>{preview.errors.length}</strong> rejected</span>
                    <span><strong>{preview.net.toLocaleString('en-US')}</strong> net energy change</span>
                </div>
                {preview.errors.length > 0 && <div className="auth-message" role="alert">
                    <p>Fix rejected rows before importing. Unrecognized reasons and ambiguous names are listed below.</p>
                    <ul>{preview.errors.slice(0, 20).map((entry, index) => <li key={index}>
                        {entry.line ? 'Line ' + entry.line + ' · ' : ''}{entry.message}
                    </li>)}</ul>
                    {preview.errors.length > 20 && <p>Showing the first 20 errors.</p>}
                </div>}
                {preview.accounts.length > 0 && <div className="battle-table-wrap">
                    <table className="battle-table">
                        <caption>Projected account balances · {preview.accounts.length} members</caption>
                        <thead><tr><th scope="col">Member</th><th scope="col">Before</th><th scope="col">After import</th></tr></thead>
                        <tbody>{preview.accounts.slice(0, 50).map((member) => <tr key={member.name}>
                            <th scope="row">{member.name}
                                {member.unconfirmed && <p className="subtle">Roster match unconfirmed · history retained by character name</p>}
                                {member.newAccount && <p className="subtle">Confirmed zero baseline</p>}
                            </th>
                            <td>{member.before.toLocaleString('en-US')}</td><td>{member.after.toLocaleString('en-US')}</td>
                        </tr>)}</tbody>
                    </table>
                    {preview.accounts.length > 50 && <p className="subtle">Showing the first 50 affected members.</p>}
                </div>}
                <label className="energy-import-check">
                    <input type="checkbox" checked={acknowledge} onChange={(event) => setAcknowledge(event.target.checked)} />
                    I checked the preview. The cutoff and starting balances are correct, and these entries are not already included in manual records.
                </label>
                {confirmRequirements.length > 0 && <div id="energy-import-requirements" className="energy-import-requirements" role="status">
                    <p>To enable Confirm import</p>
                    <ul>{confirmRequirements.map((message) => <li key={message}>{message}</li>)}</ul>
                </div>}
                <button className="button" disabled={confirmRequirements.length > 0}
                    aria-describedby={confirmRequirements.length > 0 ? 'energy-import-requirements' : undefined}
                    onClick={() => void submit(true)}>Confirm import</button>
            </>}
        </fieldset>
        {error && <p className="auth-message" role="alert">{error}</p>}
        {notice && <p className="subtle" role="status">{notice}</p>}
    </section>;
}
