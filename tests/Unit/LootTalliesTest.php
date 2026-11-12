<?php

namespace Tests\Unit;

use App\Services\LootTallies;
use PHPUnit\Framework\TestCase;

class LootTalliesTest extends TestCase
{
    private function entry(array $changes = []): array
    {
        return array_replace(['player' => 'Member', 'item' => 'Sword', 'itemId' => 'T4_MAIN_SWORD',
            'quantity' => 4, 'source' => 'Enemy', 'recordedAt' => '2026-11-07T14:05:29.0416371Z',
            'marketValue' => 1200, 'status' => 'banked'], $changes);
    }

    private function log(array $entries, bool $perItem = true): object
    {
        return (object) ['entries' => json_encode($entries), 'per_item' => $perItem];
    }

    public function test_deduplicates_overlaps_and_excludes_unconfirmed_deaths(): void
    {
        $entry = $this->entry();
        $result = (new LootTallies)->summarize([
            $this->log([$entry, $this->entry(['status' => 'unknown', 'recordedAt' => '2026-11-07T14:06:00Z', 'deathRecordedAt' => '2026-11-07T14:07:00Z'])]),
            $this->log([$this->entry(['player' => ' member ', 'recordedAt' => '2026-11-07T14:05:29.041637100Z'])]),
            $this->log([$this->entry(['status' => 'lost', 'recordedAt' => '2026-11-07T14:05:29.0416372Z', 'marketValue' => null])]),
        ]);
        $this->assertSame('4', $result[0]['banked']['quantity']);
        $this->assertSame(4800.0, $result[0]['banked']['value']);
        $this->assertSame('4', $result[0]['lost']['quantity']);
        $this->assertNull($result[0]['lost']['value']);
        $this->assertSame(1, $result[0]['lost']['unpriced']);
    }

    public function test_conflicting_confirmations_are_excluded_and_corrected_snapshots_recalculate(): void
    {
        $service = new LootTallies;
        $banked = $this->log([$this->entry()]);
        $lost = $this->log([$this->entry(['status' => 'lost'])]);
        $conflict = $service->summarize([$banked, $lost])[0];
        $this->assertSame(1, $conflict['conflicts']);
        $this->assertSame('0', $conflict['banked']['quantity']);
        $this->assertSame('0', $conflict['lost']['quantity']);
        $corrected = $service->summarize([$lost])[0];
        $this->assertSame('4', $corrected['lost']['quantity']);
        $this->assertSame('0', $corrected['banked']['quantity']);
        $this->assertSame(1200.0, $service->summarize([$this->log([$this->entry()], false)])[0]['banked']['value']);
    }
}
