export type LootInput = {
    player: string;
    item: string;
    quantity: number;
    source: string;
    recordedAt: string;
    guild?: string;
    alliance?: string;
    itemId?: string;
    sourceGuild?: string;
    sourceAlliance?: string;
    location?: string;
    marketValue?: number | null;
    status?: 'unknown' | 'lost' | 'banked';
    deathRecordedAt?: string;
};

const optionalText = ['guild', 'alliance', 'itemId', 'sourceGuild', 'sourceAlliance', 'location'] as const;

function preciseTimestamp(value: string): string {
    const normalized = new Date(value).toISOString();
    const fraction = /\.(\d+)Z$/i.exec(value)?.[1];
    if (fraction === undefined) return normalized;
    const digits = fraction.replace(/0+$/, '');
    return normalized.slice(0, 19) + (digits ? '.' + digits : '.000') + 'Z';
}

function lootKey(entry: LootInput): string {
    return JSON.stringify([
        preciseTimestamp(entry.recordedAt),
        entry.player.trim().toLowerCase(),
        entry.itemId ? ['id', entry.itemId.trim().toUpperCase()] : ['name', entry.item.trim().toLowerCase()],
        entry.quantity,
        entry.source.trim().toLowerCase(),
    ]);
}

export function mergeLootEntries<T extends LootInput>(existing: T[], incoming: T[], limit = 5000): {
    entries: T[];
    added: number;
    duplicates: number;
} {
    const records = [...existing];
    const positions = new Map(records.map((entry, index) => [lootKey(entry), index]));
    let added = 0;
    let duplicates = 0;
    for (const entry of incoming) {
        const key = lootKey(entry);
        const position = positions.get(key);
        if (position !== undefined) {
            duplicates++;
            const current = records[position];
            // Another officer's file may contain death evidence missing from the first.
            // Preserve the existing record ID and manually confirmed outcome.
            if (entry.deathRecordedAt && (!current.deathRecordedAt || entry.deathRecordedAt < current.deathRecordedAt)) {
                records[position] = { ...current, deathRecordedAt: entry.deathRecordedAt };
            }
            continue;
        }
        if (records.length >= limit) throw new Error('Keep the total under ' + limit.toLocaleString('en-US') + ' unique records.');
        positions.set(key, records.length);
        records.push(entry);
        added++;
    }
    records.sort((a, b) => b.recordedAt.localeCompare(a.recordedAt));
    return { entries: records, added, duplicates };
}

export function parseEntries(value: unknown): LootInput[] {
    if (!Array.isArray(value) || value.length > 5000) throw new Error('Import up to 5,000 loot records.');
    return value.map((entry: unknown) => {
        if (typeof entry !== 'object' || entry === null) throw new Error('Invalid loot record.');
        const row = entry as Record<string, unknown>;
        if (typeof row.player !== 'string' || !row.player.trim() || row.player.length > 64
            || typeof row.item !== 'string' || !row.item.trim() || row.item.length > 200
            || typeof row.source !== 'string' || row.source.length > 120
            || typeof row.quantity !== 'number' || !Number.isSafeInteger(row.quantity) || row.quantity < 1
            || typeof row.recordedAt !== 'string' || !Number.isFinite(Date.parse(row.recordedAt))) {
            throw new Error('A record has an invalid player, item, quantity, source, or date.');
        }
        const parsed: LootInput = {
            player: row.player.trim(), item: row.item.trim(), source: row.source.trim(),
            quantity: row.quantity, recordedAt: preciseTimestamp(row.recordedAt),
        };
        for (const key of optionalText) {
            if (row[key] !== undefined) {
                if (typeof row[key] !== 'string' || row[key].length > 200) throw new Error('Invalid loot record details.');
                parsed[key] = row[key].trim();
            }
        }
        if (row.marketValue !== undefined) {
            if (row.marketValue !== null && (typeof row.marketValue !== 'number'
                || !Number.isFinite(row.marketValue) || row.marketValue < 0)) throw new Error('Invalid logger market value.');
            parsed.marketValue = row.marketValue as number | null;
        }
        if (row.status !== undefined) {
            if (row.status !== 'unknown' && row.status !== 'lost' && row.status !== 'banked') throw new Error('Invalid loot status.');
            parsed.status = row.status;
        }
        if (row.deathRecordedAt !== undefined) {
            if (typeof row.deathRecordedAt !== 'string' || !Number.isFinite(Date.parse(row.deathRecordedAt))) throw new Error('Invalid death timestamp.');
            parsed.deathRecordedAt = new Date(row.deathRecordedAt).toISOString();
        }
        return parsed;
    });
}

function csvRows(text: string): string[][] {
    const rows: string[][] = [];
    let row: string[] = [];
    let field = '';
    let quoted = false;
    let closed = false;
    const input = text.replace(/^\uFEFF/, '');
    for (let index = 0; index < input.length; index++) {
        const char = input[index];
        if (quoted) {
            if (char === '"') {
                if (input[index + 1] === '"') { field += '"'; index++; }
                else { quoted = false; closed = true; }
            } else field += char;
        } else if (char === ';') {
            row.push(field); field = ''; closed = false;
        } else if (char === '\n' || char === '\r') {
            if (char === '\r' && input[index + 1] === '\n') index++;
            row.push(field);
            if (row.some((value) => value.trim() !== '')) rows.push(row);
            row = []; field = ''; closed = false;
        } else if (char === '"' && field === '' && !closed) {
            quoted = true;
        } else {
            if (closed || char === '"') throw new Error('Malformed CSV quoting.');
            field += char;
        }
    }
    if (quoted) throw new Error('The CSV contains an unclosed quoted field.');
    row.push(field);
    if (row.some((value) => value.trim() !== '')) rows.push(row);
    return rows;
}

export function parseLoggerCsv(text: string): { entries: LootInput[]; deathEvents: number } {
    const [header, ...rows] = csvRows(text);
    const required = ['timestamp_utc', 'looted_by__name', 'item_id', 'item_name', 'quantity', 'looted_from__name', 'died'];
    if (!header || required.some((key) => !header.includes(key)) || new Set(header).size !== header.length) {
        throw new Error('Choose a semicolon-delimited loot logger CSV with timestamp_utc, looted_by__name, item_id, item_name, quantity, looted_from__name, and died columns.');
    }
    let deathEvents = 0;
    const deaths = new Map<string, number[]>();
    const entries: LootInput[] = [];
    for (const [index, cells] of rows.entries()) {
        if (cells.length !== header.length) throw new Error('CSV row ' + (index + 2) + ' has an unexpected number of columns.');
        const row = Object.fromEntries(header.map((key, column) => [key, cells[column].trim()]));
        if (!row.item_id) {
            if (row.died) {
                const timestamp = Date.parse(row.timestamp_utc);
                if (!/^\d{4}-\d{2}-\d{2}T.*Z$/.test(row.timestamp_utc) || !Number.isFinite(timestamp)) {
                    throw new Error('CSV row ' + (index + 2) + ' has an invalid death timestamp.');
                }
                const key = row.died.toLowerCase();
                const times = deaths.get(key) ?? [];
                times.push(timestamp);
                deaths.set(key, times);
                deathEvents++;
                continue;
            }
            throw new Error('CSV row ' + (index + 2) + ' contains neither loot nor a death event.');
        }
        if (!/^\d+$/.test(row.quantity) || !/^\d{4}-\d{2}-\d{2}T.*Z$/.test(row.timestamp_utc)) {
            throw new Error('CSV row ' + (index + 2) + ' has an invalid quantity or UTC timestamp.');
        }
        const rawValue = row.average_est_market_value ?? '';
        if (rawValue !== '' && !/^\d+(\.\d+)?$/.test(rawValue)) {
            throw new Error('CSV row ' + (index + 2) + ' has an invalid market value.');
        }
        entries.push({
            player: row.looted_by__name, item: row.item_name || row.item_id,
            quantity: Number(row.quantity), recordedAt: row.timestamp_utc,
            source: row.looted_from__name, itemId: row.item_id,
            guild: row.looted_by__guild ?? '', alliance: row.looted_by__alliance ?? '',
            sourceGuild: row.looted_from__guild ?? '', sourceAlliance: row.looted_from__alliance ?? '',
            location: row.cluster ?? '', marketValue: rawValue === '' ? null : Number(rawValue),
        });
    }
    for (const times of deaths.values()) times.sort((a, b) => a - b);
    for (const entry of entries) {
        const lootedAt = Date.parse(entry.recordedAt);
        const deathAt = deaths.get(entry.player.toLowerCase())?.find((timestamp) => timestamp > lootedAt);
        if (deathAt !== undefined) entry.deathRecordedAt = new Date(deathAt).toISOString();
    }
    return { entries: parseEntries(entries), deathEvents };
}
