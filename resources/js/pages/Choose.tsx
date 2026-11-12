import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Onboarding, Shield, Workspace } from '../components/Chrome';

type Member = { id: string; name: string };

export default function Choose({ roster, username, character, officer }: {
    roster: Member[];
    username: string;
    character: string | null;
    officer: boolean;
}) {
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(0);
    const form = useForm({ player: '' });
    const matches = roster.filter((member) => member.name.toLowerCase().includes(search.trim().toLowerCase()));
    const pages = Math.max(1, Math.ceil(matches.length / 12));
    const currentPage = Math.min(page, pages - 1);
    const selected = roster.find((member) => member.id === form.data.player);

    const content = (
        <>
            <Head title="Choose character" />
            <p className="eyebrow">CONSCRIPTED / ASIA</p>
            <h1>Choose your Albion character</h1>
            <div className="binding-notice"><Shield size={20} /><p>Selections use the current guild roster. An officer must verify ownership in Discord.</p></div>
            <form className="panel" onSubmit={(event) => { event.preventDefault(); if (selected) form.post('/verification'); }}>
                <label className="character-search">
                    <input aria-label="Search characters" placeholder="Search characters" value={search} onChange={(event) => { setSearch(event.target.value); setPage(0); }} />
                </label>
                <p className="roster-caption">{matches.length} matching roster members</p>
                <fieldset className="character-grid" aria-label="Guild characters">
                    {matches.slice(currentPage * 12, (currentPage + 1) * 12).map((member) => (
                        <label className={member.id === form.data.player ? 'character-option selected' : 'character-option'} key={member.id}>
                            <input type="radio" name="player" value={member.id} checked={member.id === form.data.player} onChange={() => form.setData('player', member.id)} />
                            <span><strong>{member.name}</strong><small>Conscripted · Asia</small></span>
                        </label>
                    ))}
                </fieldset>
                {!matches.length && <p className="empty-roster">No characters match your search.</p>}
                {pages > 1 && <div className="roster-pagination">
                    <span>Page {currentPage + 1} of {pages}</span>
                    <div>
                        <button type="button" className="button secondary" disabled={currentPage === 0} onClick={() => setPage(currentPage - 1)}>Previous</button>
                        <button type="button" className="button secondary" disabled={currentPage + 1 === pages} onClick={() => setPage(currentPage + 1)}>Next</button>
                    </div>
                </div>}
                {form.errors.player && <p className="auth-message" role="alert">{form.errors.player}</p>}
                <div className="binding-footer">
                    <div><span className="eyebrow">YOUR SELECTION</span><strong>{selected?.name ?? 'No character selected'}</strong></div>
                    <button className="button" disabled={form.processing || !selected}>{form.processing ? 'Creating ticket…' : 'Request officer verification'}</button>
                </div>
            </form>
            <Link className="text-button" href="/">Back to verification</Link>
        </>
    );

    return character
        ? <Workspace character={character} username={username} officer={officer}>{content}</Workspace>
        : <Onboarding step={1}>{content}</Onboarding>;
}
