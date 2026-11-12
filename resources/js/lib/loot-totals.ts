import type { LootInput } from './loot-import';

export type LootAmount = {
    quantity: bigint;
    value: number | null;
    unpriced: number;
    priced: number;
};

export function lootTotals(entries: LootInput[], perItem: boolean): Record<'total' | 'lost' | 'possible' | 'banked', LootAmount> {
    function amount(): LootAmount {
        return { quantity: 0n, value: 0, unpriced: 0, priced: 0 };
    }
    const totals = { total: amount(), lost: amount(), possible: amount(), banked: amount() };
    function add(bucket: LootAmount, entry: LootInput) {
        bucket.quantity += BigInt(entry.quantity);
        const value = entry.marketValue == null ? null : entry.marketValue * (perItem ? entry.quantity : 1);
        if (value === null || !Number.isFinite(value)) {
            bucket.unpriced++;
        } else {
            bucket.priced++;
            if (bucket.value !== null) {
                const sum = bucket.value + value;
                bucket.value = Number.isFinite(sum) ? sum : null;
            }
        }
    }
    for (const entry of entries) {
        add(totals.total, entry);
        if (entry.status === 'lost') add(totals.lost, entry);
        else if (entry.status === 'banked') add(totals.banked, entry);
        else if (entry.deathRecordedAt && entry.status !== 'unknown') add(totals.possible, entry);
    }
    for (const bucket of Object.values(totals)) {
        // Empty categories have a known zero. Entirely unpriced loot is unavailable.
        if (bucket.quantity > 0n && bucket.priced === 0) bucket.value = null;
    }
    return totals;
}
