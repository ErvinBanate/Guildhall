import { Head, Link, router } from '@inertiajs/react';
import { useEffect } from 'react';
import { Onboarding, Shield, Workspace } from '../components/Chrome';

type Ticket = { name: string; status: 'pending' | 'approved' | 'denied' | 'revoked'; url: string };

export default function Guildhall({ username, officer, ticket }: { username: string; officer: boolean; ticket: Ticket | null }) {
    useEffect(() => {
        if (ticket?.status !== 'pending') return;
        const timer = window.setInterval(() => router.reload({ only: ['ticket'] }), 15_000);
        return () => window.clearInterval(timer);
    }, [ticket?.status]);

    if (ticket?.status === 'approved') {
        return (
            <Workspace character={ticket.name} username={username} officer={officer}>
                <Head title="Character verification" />
                <div className="page-heading">
                    <div>
                        <p className="eyebrow">YOUR GUILDHALL ACCOUNT</p>
                        <h1>Character verification<span>.</span></h1>
                        <p className="subtle">Manage the Albion character linked to your Discord account.</p>
                    </div>
                </div>
                <section className="panel verification-card">
                    <p className="tag status-good"><span aria-hidden="true">✓</span> Officer verified</p>
                    <div className="verification-card-heading">
                        <h2>{ticket.name}</h2>
                        <p className="subtle">Conscripted · Albion Asia</p>
                    </div>
                    <p>Your linked character has access to Guildhall.</p>
                    <div className="binding-notice">
                        <Shield size={18} />
                        <p>Submitting a request for a different character pauses dashboard access until an officer approves it.</p>
                    </div>
                    <div className="character-actions">
                        <Link href="/choose-character" className="button">Change linked character</Link>
                        <a className="button secondary" href={ticket.url} target="_blank" rel="noreferrer">View Discord ticket <span aria-hidden="true">↗</span></a>
                    </div>
                </section>
            </Workspace>
        );
    }

    return (
        <Onboarding step={2}>
            <Head title="Guildhall" />
            <p className="eyebrow">CONSCRIPTED / ASIA</p>
            <h1>Officer verification</h1>
            <p>Signed in as {username}</p>
            {ticket ? (
                <section className="panel verification-card">
                    <div className="verification-card-heading">
                        <h2>{ticket.name}</h2>
                        <p className="subtle">Conscripted · Albion Asia</p>
                    </div>
                    <p className="tag verification-status">Status: {ticket.status}</p>
                    {ticket.status === 'pending' && <p>Submit proof of character ownership in Discord.</p>}
                    <a className="button secondary" href={ticket.url} target="_blank" rel="noreferrer">Open Discord ticket</a>
                </section>
            ) : <p>Choose a character to request verification.</p>}
            <div className="character-actions">
                <Link href="/choose-character" className="button">{ticket ? 'Choose another character' : 'Choose character'}</Link>
                {officer && <Link className="button secondary" href="/officer/verification">Officer inbox</Link>}
            </div>
        </Onboarding>
    );
}
