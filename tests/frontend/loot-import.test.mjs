import assert from 'node:assert/strict';
import test from 'node:test';
import { mergeLootEntries, parseEntries, parseLoggerCsv } from '../../resources/js/lib/loot-import.ts';
import { lootTotals } from '../../resources/js/lib/loot-totals.ts';

const header = 'timestamp_utc;looted_by__name;item_id;item_name;quantity;looted_from__name;died;looted_by__guild;average_est_market_value';

test('imports logger CSV with BOM, CRLF, quoted fields and death events', () => {
    const result = parseLoggerCsv('\uFEFF' + header + '\r\n'
        + '2026-11-07T14:05:29.0416371Z;Member;T7_ITEM;"Item; with ""quotes""";4;Enemy;;Conscripted;0\r\n'
        + '2026-11-07T14:05:28Z;;;;;;Enemy;;\r\n');
    assert.equal(result.entries.length, 1);
    assert.equal(result.deathEvents, 1);
    assert.equal(result.entries[0].item, 'Item; with "quotes"');
    assert.equal(result.entries[0].quantity, 4);
    assert.equal(result.entries[0].marketValue, 0);
    assert.equal(result.entries[0].recordedAt, '2026-11-07T14:05:29.0416371Z');
    assert.deepEqual(parseEntries(JSON.parse(JSON.stringify(result.entries))), result.entries);
});

test('missing market values stay unavailable and multiline fields parse', () => {
    const result = parseLoggerCsv(header + '\n'
        + '2026-11-07T14:05:29Z;Member;T7_ITEM;"First line\nSecond line";1;Enemy;;Conscripted;');
    assert.equal(result.entries[0].marketValue, null);
    assert.equal(result.entries[0].item, 'First line\nSecond line');
});

test('rejects malformed data before returning partial imports', () => {
    const valid = '2026-11-07T14:05:29Z;Member;T7_ITEM;Item;1;Enemy;;Conscripted;';
    assert.throws(() => parseLoggerCsv('unrelated;header\na;b'));
    assert.throws(() => parseLoggerCsv(header + '\n' + valid + '\n' + valid.replace(';1;', ';-1;')));
    assert.throws(() => parseLoggerCsv(header + '\n' + valid + ';extra'));
    assert.throws(() => parseLoggerCsv(header + '\n"unclosed'));
});

test('matches deaths by collector and time without assuming banking or confirmed item loss', () => {
    const result = parseLoggerCsv(header + '\n'
        + '2026-11-07T14:05:30Z;;;;;;Member;;\n'
        + '2026-11-07T14:05:29Z;Member;T7_ITEM;Item;1;Enemy;;Conscripted;\n'
        + '2026-11-07T14:05:31Z;Member;T7_ITEM;Item;1;Enemy;;Conscripted;\n'
        + '2026-11-07T14:05:29Z;Other;T7_ITEM;Item;1;Enemy;;Conscripted;');
    assert.equal(result.entries[0].deathRecordedAt, '2026-11-07T14:05:30.000Z');
    assert.equal(result.entries[0].status, undefined);
    assert.equal(result.entries[1].deathRecordedAt, undefined);
    assert.equal(result.entries[2].deathRecordedAt, undefined);
    const saved = [{ ...result.entries[0], status: 'banked' }];
    assert.deepEqual(parseEntries(JSON.parse(JSON.stringify(saved))), saved);
    assert.throws(() => parseEntries([{ ...result.entries[0], status: 'invalid' }]));
});

test('repeated and overlapping imports skip duplicates and preserve confirmed outcomes', () => {
    const [entry] = parseLoggerCsv(header + '\n'
        + '2026-11-07T14:05:29.0416371Z;Member;T7_ITEM;Item;1;Enemy;;Conscripted;100').entries;
    const existing = [{ ...entry, id: 'original', status: 'banked' }];
    const overlap = { ...entry, id: 'other-file', player: 'member', marketValue: 200,
        deathRecordedAt: '2026-11-07T15:00:00.000Z', status: 'lost' };
    const nextPickup = { ...entry, id: 'next', recordedAt: '2026-11-07T14:05:29.0416372Z' };
    const result = mergeLootEntries(existing, [overlap, nextPickup, nextPickup]);
    assert.equal(result.added, 1);
    assert.equal(result.duplicates, 2);
    assert.equal(result.entries.length, 2);
    const retained = result.entries.find((row) => row.id === 'original');
    assert.equal(retained.status, 'banked');
    assert.equal(retained.marketValue, 100);
    assert.equal(retained.deathRecordedAt, overlap.deathRecordedAt);
    assert.equal(existing[0].deathRecordedAt, undefined);
    const repeated = mergeLootEntries(result.entries, [overlap, nextPickup]);
    assert.equal(repeated.added, 0);
    assert.equal(repeated.duplicates, 2);
    const roundTrip = parseEntries(JSON.parse(JSON.stringify(result.entries)));
    assert.equal(mergeLootEntries(roundTrip, [entry, nextPickup]).added, 0);
});

test('deduplication preserves differing items, quantities, sources and times', () => {
    const [entry] = parseLoggerCsv(header + '\n'
        + '2026-11-07T14:05:29.0416371Z;Member;T7_ITEM;Item;1;Enemy;;Conscripted;').entries;
    const distinct = [
        { ...entry, quantity: 2 },
        { ...entry, itemId: 'T7_OTHER' },
        { ...entry, source: 'Other enemy' },
        { ...entry, player: 'Other player' },
        { ...entry, recordedAt: '2026-11-07T14:05:30.0416371Z' },
    ];
    assert.equal(mergeLootEntries([entry], distinct).added, 5);
    assert.equal(mergeLootEntries([entry], [entry, entry], 1).duplicates, 2);
    assert.throws(() => mergeLootEntries([entry], distinct, 1));
    assert.equal(mergeLootEntries([], [entry, entry]).duplicates, 1);
});

test('totals track quantities and separate confirmed outcomes from death indications', () => {
    const base = { player: 'Member', item: 'Item', quantity: 4, source: 'Enemy',
        recordedAt: '2026-11-07T14:00:00Z', marketValue: 100 };
    const deathRecordedAt = '2026-11-07T15:00:00Z';
    const entries = [
        base,
        { ...base, status: 'lost', quantity: 2, deathRecordedAt },
        { ...base, status: 'banked', quantity: 3, deathRecordedAt },
        { ...base, quantity: 5, deathRecordedAt },
        { ...base, status: 'unknown', quantity: 1, deathRecordedAt },
    ];
    const stack = lootTotals(entries, false);
    assert.equal(stack.total.quantity, 15n);
    assert.equal(stack.total.value, 500);
    assert.equal(stack.lost.quantity, 2n);
    assert.equal(stack.lost.value, 100);
    assert.equal(stack.banked.quantity, 3n);
    assert.equal(stack.possible.quantity, 5n);
    const perItem = lootTotals(entries, true);
    assert.equal(perItem.total.value, 1500);
    assert.equal(perItem.lost.value, 200);
    assert.equal(perItem.possible.value, 500);
    const confirmed = lootTotals(entries.map((entry) => entry.quantity === 5 ? { ...entry, status: 'banked' } : entry), true);
    assert.equal(confirmed.possible.value, 0);
    assert.equal(confirmed.banked.value, 800);
});

test('missing values stay unavailable and partial totals disclose unpriced records', () => {
    const base = { player: 'Member', item: 'Item', quantity: 4, source: 'Enemy',
        recordedAt: '2026-11-07T14:00:00Z' };
    const unknown = lootTotals([base], false);
    assert.equal(unknown.total.value, null);
    assert.equal(unknown.total.quantity, 4n);
    assert.equal(unknown.lost.value, 0);
    const partial = lootTotals([base, { ...base, marketValue: 0 }], false);
    assert.equal(partial.total.value, 0);
    assert.equal(partial.total.unpriced, 1);
    assert.equal(lootTotals([base, { ...base, marketValue: 120 }], false).total.value, 120);
    assert.equal(lootTotals([{ ...base, quantity: Number.MAX_SAFE_INTEGER }, base], false).total.quantity,
        BigInt(Number.MAX_SAFE_INTEGER) + 4n);
});
