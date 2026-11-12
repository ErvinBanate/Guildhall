import { Head, Link } from '@inertiajs/react';
import { Onboarding, Workspace } from '../components/Chrome';

type TicketPage = {
    data: { id: string; name: string; status: string; code: string; url: string }[];
    prev_page_url: string | null;
    next_page_url: string | null;
};

export default function Inbox({ tickets, username, character }: { tickets: TicketPage; username: string; character: string | null }) {
    const content = (
        <>
            <Head title="Officer inbox" />
            <div className="inbox-heading">
                <div>
                    <p className="eyebrow">OFFICER TOOLS</p>
                    <h1>Verification inbox</h1>
                    <p className="subtle">Open a Discord ticket to review the member’s proof and record your decision.</p>
                </div>
                {!character && <Link href="/" className="button secondary"><span aria-hidden="true">←</span> My character verification</Link>}
            </div>
            {tickets.data.length === 0 ? (
                <p className="panel">No verification requests yet.</p>
            ) : <ul className="inbox-list">
                {tickets.data.map((ticket) => (
                    <li key={ticket.id} className="panel inbox-ticket">
                        <div className="inbox-ticket-heading">
                            <div>
                                <h2>{ticket.name}</h2>
                                <span className="tag">{ticket.status === 'pending' ? 'Awaiting review' : ticket.status}</span>
                            </div>
                            <a className="button" href={ticket.url} target="_blank" rel="noreferrer">
                                Open Discord ticket <span aria-hidden="true">↗</span>
                            </a>
                        </div>
                        {ticket.status === 'pending' && (
                            <div className="inbox-instructions">
                                <p>Review the proof in the ticket. Then run <code>/guildhall approve</code> or <code>/guildhall deny</code> in that channel.</p>
                                <p>Verification code <code className="inbox-code">{ticket.code}</code></p>
                            </div>
                        )}
                        {ticket.status === 'approved' && (
                            <div className="inbox-instructions">
                                <p>To remove access, run <code>/guildhall revoke</code> in the ticket channel.</p>
                                <p>Verification code <code className="inbox-code">{ticket.code}</code></p>
                            </div>
                        )}
                    </li>
                ))}
            </ul>}
            {(tickets.prev_page_url || tickets.next_page_url) && (
                <nav className="inbox-pagination" aria-label="Verification inbox pages">
                    {tickets.prev_page_url && <Link className="button secondary" href={tickets.prev_page_url}>Previous page</Link>}
                    {tickets.next_page_url && <Link className="button secondary" href={tickets.next_page_url}>Next page</Link>}
                </nav>
            )}
        </>
    );

    return character
        ? <Workspace character={character} username={username} officer>{content}</Workspace>
        : <Onboarding>{content}</Onboarding>;
}
