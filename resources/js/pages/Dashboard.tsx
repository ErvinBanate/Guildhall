import { Deferred, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Shield, Workspace } from '../components/Chrome';
import { PersonalLootTally, type CharacterLootTally } from '../components/LootTallies';
import { ZvZReports, type ZvZFeed } from '../components/ZvZReports';

type CharacterStats = {
    killFame: number | null;
    deathFame: number | null;
    fameRatio: number | null;
    pveFame: number | null;
    gatheringFame: number | null;
    craftingFame: number | null;
    fishingFame: number | null;
    farmingFame: number | null;
};

const number = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 });
const compactNumber = new Intl.NumberFormat('en-US', {
    notation: 'compact',
    maximumFractionDigits: 2,
});

function Stat({ label, value, detail, ratio = false }: {
    label: string; value: number | null; detail?: string; ratio?: boolean;
}) {
    const formatted = value === null ? '—' : (ratio ? number : compactNumber).format(value).toLowerCase();
    return (
        <section className="stat-card">
            <div>{label}</div>
            <strong title={value === null ? undefined : number.format(value)} aria-label={value === null ? 'Unavailable' : undefined}>{formatted}</strong>
            {detail && <p>{detail}</p>}
        </section>
    );
}

export default function Dashboard({ username, character, officer, stats, zvz, lootTally, viewedCharacter }: {
    username: string;
    character: string;
    officer: boolean;
    lootTally: CharacterLootTally | null;
    viewedCharacter?: { id: string; name: string } | null;
    stats?: CharacterStats;
    zvz?: ZvZFeed | null;
}) {
    const [refreshing, setRefreshing] = useState(false);

    return (
        <Workspace username={username} character={character} officer={officer}>
            <Head title={viewedCharacter ? viewedCharacter.name + ' · Character overview' : 'Character overview'} />
            <div className="page-heading">
                <div>
                    <p className="eyebrow">GUILDHALL WORKSPACE</p>
                    <h1>{viewedCharacter ? viewedCharacter.name + ' · Overview' : 'Character overview'}</h1>
                    <p className="subtle">Character fame and Conscripted ZvZ reports from Albion Gameinfo.</p>
                </div>
                <div className="character-actions"><Link href="/characters" className="button secondary">{viewedCharacter ? '← All characters' : 'Browse characters'}</Link>
                <button type="button" className="button secondary" disabled={!stats || refreshing} onClick={() => {
                    setRefreshing(true);
                    router.reload({ only: ['stats', 'zvz', 'lootTally'], onFinish: () => setRefreshing(false) });
                }}>{refreshing ? 'Refreshing…' : 'Refresh stats'}</button></div>
            </div>
            <div className="character-overview-grid">
                <section className="panel character-overview-profile">
                    <Shield size={24} />
                    <span className="eyebrow">{viewedCharacter ? 'CURRENT GUILD ROSTER' : 'OFFICER-VERIFIED CHARACTER'}</span>
                    <h2>{viewedCharacter?.name ?? character}</h2>
                    <p className="subtle">Conscripted · Albion Asia</p>
                    {viewedCharacter ? <Link className="button secondary" href="/dashboard">My overview →</Link>
                        : <Link className="button secondary" href="/">Manage linked character <span aria-hidden="true">→</span></Link>}
                </section>
                <Deferred data="stats" fallback={<p className="panel" role="status">Loading combat fame…</p>}>
                    {stats && (
                        <section className="panel character-overview-combat" aria-labelledby="combat-fame">
                            <h3 id="combat-fame">Combat fame</h3>
                            <div className="stats-grid">
                                <Stat label="Kill fame" value={stats.killFame} detail="Lifetime PvP fame earned" />
                                <Stat label="Death fame" value={stats.deathFame} detail="Lifetime PvP death fame" />
                                <Stat label="K/D ratio (based on fame)" value={stats.fameRatio} detail="Reported by Albion Gameinfo" ratio />
                            </div>
                        </section>
                    )}
                </Deferred>
            </div>
            <PersonalLootTally tally={lootTally} />
            <section className="character-stats-section" aria-labelledby="recent-zvz">
                <div className="panel-heading">
                    <div><p className="eyebrow">CONSCRIPTED · ALBION ASIA</p><h3 id="recent-zvz">Recent ZvZ Reports</h3></div>
                    <Link href="/battles" className="button secondary">Open Battle Board <span aria-hidden="true">→</span></Link>
                </div>
                <p className="subtle zvz-description">Fights that recorded {viewedCharacter?.name ?? 'your character'} and at least 20 distinct Conscripted characters.</p>
                <Deferred data="zvz" fallback={<p className="panel" role="status">Loading ZvZ reports…</p>}>
                    <ZvZReports feed={zvz ?? null} limit={3} subject={viewedCharacter?.name} />
                </Deferred>
            </section>
            <Deferred data="stats" fallback={<p className="panel character-stats-section" role="status">Loading progression…</p>}>
                {stats && (
                    <>
                        <section className="character-stats-section" aria-labelledby="progression-fame">
                            <div className="panel-heading"><div><p className="eyebrow">LIFETIME FAME</p><h3 id="progression-fame">Progression</h3></div></div>
                            <div className="stats-grid">
                                <Stat label="PvE fame" value={stats.pveFame} />
                                <Stat label="Gathering fame" value={stats.gatheringFame} />
                                <Stat label="Crafting fame" value={stats.craftingFame} />
                                <Stat label="Fishing fame" value={stats.fishingFame} />
                                <Stat label="Farming fame" value={stats.farmingFame} />
                            </div>
                        </section>
                    </>
                )}
            </Deferred>
        </Workspace>
    );
}
