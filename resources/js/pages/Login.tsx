import { Head } from '@inertiajs/react';
import { Brand, Shield } from '../components/Chrome';

export default function Login() {
    return (
        <main className="auth-screen">
            <Head title="Sign in" />
            <section className="login-card panel">
                <Brand href="/login" />
                <span className="eyebrow">YOUR GUILD. ONE COMMAND CENTER.</span>
                <h1>Every guild needs<br /><span>a gathering place.</span></h1>
                <p className="subtle">Sign in with Discord to access the Conscripted guild dashboard on Albion Asia.</p>
                <a className="button login-button" href="/api/auth/login">Continue with Discord <span aria-hidden="true">→</span></a>
                <div className="login-security"><Shield size={17} /><p>Guildhall checks your Discord membership and roles. An officer verifies your Albion character separately.</p></div>
                <p className="login-footer">UNITY · STRATEGY · VICTORY</p>
            </section>
        </main>
    );
}
