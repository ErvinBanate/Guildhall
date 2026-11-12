import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Login from './pages/Login';
import Guildhall from './pages/Guildhall';
import Choose from './pages/Choose';
import Inbox from './pages/Inbox';
import Dashboard from './pages/Dashboard';
import Characters from './pages/Characters';
import Battles from './pages/Battles';
import BattleDetails from './pages/BattleDetails';
import GuildStats from './pages/GuildStats';
import LootLogs from './pages/LootLogs';
import OcPermissions from './pages/OcPermissions';
import OcExemptions from './pages/OcExemptions';
import '../css/app.css';

const pages = { Login, Guildhall, Choose, Inbox, Dashboard, Characters, Battles, BattleDetails, GuildStats, LootLogs, OcPermissions, OcExemptions };

void createInertiaApp({
    title: (title) => title + ' | Guildhall',
    resolve: (name) => {
        if (!(name in pages)) throw new Error('Unknown Guildhall page');
        return pages[name as keyof typeof pages];
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
