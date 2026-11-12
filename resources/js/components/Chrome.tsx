import { Link, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

export function Shield({ size = 30 }: { size?: number }) {
    return <svg aria-hidden="true" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.4"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z" /></svg>;
}

export function Brand({ href }: { href: string }) {
    return <Link className="brand" href={href}>
        <span className="brand-crest"><img src="/images/Cons_logo.png" alt="Conscripted" width={72} height={84} /></span>
        <span>GUILDHALL<small>ALBION COMMAND</small></span>
    </Link>;
}

export function SignOut() {
    return <Link href="/logout" method="post" as="button" className="button secondary">Sign out</Link>;
}

export function Onboarding({ children, step }: { children: ReactNode; step?: 1 | 2 }) {
    return (
        <div className="onboarding-shell">
            <header className="onboarding-header"><Brand href="/" /><SignOut /></header>
            <main className="onboarding-main">
                {step && <div className="onboarding-steps">
                    <span className={step === 1 ? 'current' : ''}>01 · Bind your character</span>
                    <span className={step === 2 ? 'current' : ''}>02 · Officer verification</span>
                </div>}
                {children}
            </main>
        </div>
    );
}

export function Workspace({ children, character, username, officer }: {
    children: ReactNode;
    character: string;
    username: string;
    officer: boolean;
}) {
    const [open, setOpen] = useState(false);
    const path = usePage().url.split('?')[0];
    const current = path === '/choose-character' ? '/' : path.startsWith('/battles/') ? '/battles'
        : path.startsWith('/officer/oc/') ? '/officer/oc' : path.startsWith('/characters/') ? '/characters' : path;
    const links = [
        { href: '/dashboard', label: 'Overview' },
        { href: '/characters', label: 'Characters' },
        { href: '/battles', label: 'Battle Board' },
        { href: '/guild', label: 'Guild Stats' },
        { href: '/loot-logs', label: 'Loot Logs' },
        { href: '/', label: 'Character verification' },
        ...(officer ? [{ href: '/officer/verification', label: 'Officer inbox' }] : []),
        ...(officer ? [{ href: '/officer/oc', label: 'OC Permissions' }] : []),
    ];

    return (
        <div className="app-shell">
            <aside className={open ? 'sidebar is-open' : 'sidebar'}>
                <Brand href="/dashboard" />
                <div className="sidebar-label">WORKSPACE</div>
                <nav aria-label="Main navigation">
                    {links.map(({ href, label }) => (
                        <Link key={href} href={href} className={current === href ? 'active' : undefined}
                            aria-current={path === href ? 'page' : undefined} onClick={() => setOpen(false)}>
                            {label}
                        </Link>
                    ))}
                </nav>
                <div className="sidebar-note">
                    <span className="eyebrow">BUILT FOR YOUR GUILD</span>
                    <p>Every battle tells a story.<br />Bring yours together.</p>
                    <span className="subtle">Guild access · Verified</span>
                </div>
                <SignOut />
            </aside>
            {open && <button className="mobile-backdrop" aria-label="Close navigation" onClick={() => setOpen(false)} />}
            <div className="main-shell">
                <header className="topbar">
                    <button className="menu-button" aria-expanded={open} aria-label={open ? 'Close menu' : 'Open menu'} onClick={() => setOpen(!open)}>{open ? '✕' : '☰'}</button>
                    <span className="breadcrumb">Workspace <span>/</span> Guild command</span>
                    <div className="topbar-right">
                        <Link href="/" className="character-chip"><strong>{character}</strong><small>Conscripted · Asia</small></Link>
                        <span className="avatar" title={username} aria-label={username}>{username.slice(0, 1).toUpperCase()}</span>
                    </div>
                </header>
                <main>{children}</main>
                <footer>GUILDHALL <span>Independent guild tool · Not affiliated with Sandbox Interactive</span></footer>
            </div>
        </div>
    );
}
