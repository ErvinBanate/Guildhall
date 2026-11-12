<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class LootLogs
{
    private function approvedCharacter(string $userId): ?string
    {
        return DB::table('verifications')->where('discord_user_id', $userId)
            ->where('status', 'approved')->value('player_name');
    }

    private function logs(string $userId, bool $officer = false)
    {
        $query = DB::table('loot_logs')->where('guild_id', config('guildhall.albion_guild'));
        return $officer ? $query : $query->where('owner_id', $userId);
    }

    public function index(Request $request)
    {
        $user = $request->session()->get('discord_user');
        $officer = in_array($user['role'], ['officer', 'admin'], true);
        $character = $this->approvedCharacter($user['id']);
        if ($character === null) {
            return redirect()->route('guildhall');
        }
        $selected = $request->query('log', $request->session()->get('loot_log_id'));
        if ($selected === 'new') {
            $request->session()->forget('loot_log_id');
            $selected = null;
        }
        $saved = null;
        if ($selected !== null) {
            abort_unless(is_string($selected) && preg_match('/^[a-f0-9-]{36}$/iD', $selected), 404);
            $saved = $this->logs($user['id'], $officer)->where('id', $selected)->first();
            abort_unless($saved, 404);
            $request->session()->put('loot_log_id', $saved->id);
        }

        return Inertia::render('LootLogs', [
            'username' => $user['name'],
            'character' => $character,
            'officer' => in_array($user['role'], ['officer', 'admin'], true),
            'savedLogs' => $this->logs($user['id'], $officer)->latest('updated_at')->get(['id', 'title', 'updated_at', 'confirmed_by']),
            'lootTallies' => app(\App\Services\LootTallies::class)->guild(),
            'savedLog' => $saved ? [
                'id' => $saved->id,
                'title' => $saved->title,
                'entries' => json_decode($saved->entries, true, flags: JSON_THROW_ON_ERROR),
                'perItem' => (bool) $saved->per_item,
                'revision' => $saved->revision,
                'confirmedBy' => $saved->confirmed_by,
            ] : null,
        ]);
    }

    public function save(Request $request)
    {
        $user = $request->session()->get('discord_user');
        abort_unless($this->approvedCharacter($user['id']) !== null, 403);
        abort_if(strlen($request->getContent()) > 4_000_000, 413, 'Save fewer loot records at once.');
        $quantity = function ($attribute, $value, $fail) {
            if (!is_int($value) || $value < 1 || $value > 9007199254740991) $fail('Invalid item quantity.');
        };
        $marketValue = function ($attribute, $value, $fail) {
            if ($value !== null && ((!is_int($value) && !is_float($value)) || !is_finite($value) || $value < 0)) {
                $fail('Invalid market value.');
            }
        };
        $data = $request->validate([
            'confirmOutcomes' => ['sometimes', 'boolean'],
            'id' => ['required', 'uuid'],
            'revision' => ['required', 'integer', 'min:0', 'max:4294967294'],
            'title' => ['required', 'string', 'max:100'],
            'perItem' => ['required', 'boolean'],
            'entries' => ['present', 'array', 'max:5000'],
            'entries.*' => ['array:id,player,item,quantity,source,recordedAt,guild,alliance,itemId,sourceGuild,sourceAlliance,location,marketValue,status,deathRecordedAt'],
            'entries.*.id' => ['required', 'uuid', 'distinct'],
            'entries.*.player' => ['required', 'string', 'max:64'],
            'entries.*.item' => ['required', 'string', 'max:200'],
            'entries.*.quantity' => ['required', $quantity],
            'entries.*.source' => ['present', 'nullable', 'string', 'max:120'],
            'entries.*.recordedAt' => ['required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T.*Z$/D'],
            'entries.*.guild' => ['sometimes', 'nullable', 'string', 'max:200'],
            'entries.*.alliance' => ['sometimes', 'nullable', 'string', 'max:200'],
            'entries.*.itemId' => ['sometimes', 'nullable', 'string', 'max:200'],
            'entries.*.sourceGuild' => ['sometimes', 'nullable', 'string', 'max:200'],
            'entries.*.sourceAlliance' => ['sometimes', 'nullable', 'string', 'max:200'],
            'entries.*.location' => ['sometimes', 'nullable', 'string', 'max:200'],
            'entries.*.marketValue' => ['sometimes', $marketValue],
            'entries.*.status' => ['sometimes', Rule::in(['unknown', 'lost', 'banked'])],
            'entries.*.deathRecordedAt' => ['sometimes', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T.*Z$/D'],
        ]);
        $confirm = (bool) ($data['confirmOutcomes'] ?? false);
        $officer = in_array($user['role'], ['officer', 'admin'], true);
        abort_if($confirm && !$officer, 403, 'Only an officer can confirm guild loot outcomes.');
        // Laravel converts empty strings to null. Keep saved records compatible with the importer.
        foreach ($data['entries'] as &$entry) {
            foreach (['source', 'guild', 'alliance', 'itemId', 'sourceGuild', 'sourceAlliance', 'location'] as $key) {
                if (array_key_exists($key, $entry) && $entry[$key] === null) $entry[$key] = '';
            }
        }
        unset($entry);
        $entries = json_encode($data['entries'], JSON_THROW_ON_ERROR);
        try {
            $revision = DB::transaction(function () use ($user, $data, $entries, $confirm, $officer) {
                $existing = DB::table('loot_logs')->where('id', $data['id'])->lockForUpdate()->first();
                $values = [
                    'title' => trim($data['title']),
                    'entries' => $entries,
                    'per_item' => (bool) $data['perItem'],
                    'confirmed_by' => $confirm ? $user['id'] : null,
                    'confirmed_at' => $confirm ? now() : null,
                ];
                if ($existing) {
                    abort_unless(($existing->owner_id === $user['id'] || $officer)
                        && $existing->guild_id === config('guildhall.albion_guild'), 404);
                    // A retry of a successful save returns the same revision.
                    if ($existing->revision === (int) $data['revision'] + 1
                        && $existing->title === $values['title'] && $existing->entries === $entries
                        && (bool) $existing->per_item === $values['per_item']
                        && $existing->confirmed_by === $values['confirmed_by']) {
                        return $existing->revision;
                    }
                    abort_unless($existing->revision === (int) $data['revision'], 409,
                        'This log changed in another tab. Export your draft before reopening the latest saved log.');
                } else {
                    abort_unless((int) $data['revision'] === 0, 409, 'The saved log is unavailable.');
                }
                $next = (int) $data['revision'] + 1;
                if ($existing) {
                    DB::table('loot_logs')->where('id', $data['id'])->update($values + ['revision' => $next, 'updated_at' => now()]);
                } else {
                    DB::table('loot_logs')->insert($values + [
                        'id' => $data['id'], 'owner_id' => $user['id'],
                        'guild_id' => config('guildhall.albion_guild'), 'revision' => $next,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                return $next;
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Another save is in progress. Try saving again.');
        }

        $request->session()->put('loot_log_id', $data['id']);

        return response()->json(['id' => $data['id'], 'revision' => $revision]);
    }
}
