<?php

namespace App\Services\Farm;

use App\Models\Character;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Personal site log: probe-scanner pastes keep a per-system signature list
 * up to date (new/updated/despawned), escalations get a 24-hour timer, and
 * the history shows which constellations actually produce sites for YOU.
 */
class SignatureJournalService
{
    public function __construct(private readonly ProbeScanParser $parser) {}

    /**
     * @return array{new: int, updated: int, gone: int}
     */
    public function ingest(Character $character, int $systemId, string $text): array
    {
        $rows = $this->parser->parse($text);
        $now = CarbonImmutable::now();

        $seenIds = [];
        $new = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $seenIds[] = $row['sigId'];

            $existing = DB::table('signatures')
                ->where('character_id', $character->character_id)
                ->where('system_id', $systemId)
                ->where('sig_id', $row['sigId'])
                ->first();

            if ($existing === null) {
                DB::table('signatures')->insert([
                    'character_id' => $character->character_id,
                    'system_id' => $systemId,
                    'sig_id' => $row['sigId'],
                    'sig_group' => $row['group'],
                    'category' => $row['category'],
                    'name' => $row['name'],
                    'signal' => $row['signal'],
                    'status' => 'active',
                    'first_seen' => $now,
                    'last_seen' => $now,
                ]);
                $new++;
            } else {
                DB::table('signatures')->where('id', $existing->id)->update([
                    // A better scan only ever adds information.
                    'category' => $row['category'] ?? $existing->category,
                    'name' => $row['name'] ?? $existing->name,
                    'signal' => max((float) ($row['signal'] ?? 0), (float) ($existing->signal ?? 0)),
                    'status' => 'active',
                    'last_seen' => $now,
                ]);
                $updated++;
            }
        }

        // Anything previously active in this system but absent from a full
        // paste has despawned (or was completed by someone).
        $gone = DB::table('signatures')
            ->where('character_id', $character->character_id)
            ->where('system_id', $systemId)
            ->where('status', 'active')
            ->where('sig_group', '!=', 'Escalation')
            ->whereNotIn('sig_id', $seenIds)
            ->update(['status' => 'gone', 'last_seen' => $now]);

        return ['new' => $new, 'updated' => $updated, 'gone' => $gone];
    }

    public function markDone(Character $character, int $signatureId): void
    {
        DB::table('signatures')
            ->where('character_id', $character->character_id)
            ->where('id', $signatureId)
            ->update(['status' => 'done', 'completed_at' => CarbonImmutable::now()]);
    }

    public function addEscalation(Character $character, int $systemId, string $name): void
    {
        $now = CarbonImmutable::now();

        DB::table('signatures')->insert([
            'character_id' => $character->character_id,
            'system_id' => $systemId,
            'sig_id' => 'ESC-'.Str::upper(Str::random(3)),
            'sig_group' => 'Escalation',
            'category' => 'Escalation',
            'name' => $name,
            'status' => 'active',
            'first_seen' => $now,
            'last_seen' => $now,
            'expires_at' => $now->addDay(),
        ]);
    }

    /**
     * Active signatures (escalations first — they expire).
     */
    public function active(Character $character): Collection
    {
        return DB::table('signatures as sig')
            ->join('solar_systems as s', 's.system_id', '=', 'sig.system_id')
            ->where('sig.character_id', $character->character_id)
            ->where('sig.status', 'active')
            ->where(fn ($q) => $q->whereNull('sig.expires_at')->orWhere('sig.expires_at', '>', now()))
            ->orderByRaw('sig.expires_at IS NULL, sig.expires_at, sig.first_seen DESC')
            ->get(['sig.*', 's.name as system_name']);
    }

    /**
     * Which constellations produced (combat) signatures for this character.
     *
     * @return Collection<int, object>
     */
    public function constellationStats(Character $character, int $days = 30): Collection
    {
        return DB::table('signatures as sig')
            ->join('solar_systems as s', 's.system_id', '=', 'sig.system_id')
            ->join('constellations as c', 'c.constellation_id', '=', 's.constellation_id')
            ->where('sig.character_id', $character->character_id)
            ->where('sig.first_seen', '>=', now()->subDays($days))
            ->selectRaw("c.name as constellation, COUNT(*) as total,
                SUM(CASE WHEN sig.category = 'Combat Site' OR sig.sig_group = 'Cosmic Anomaly' THEN 1 ELSE 0 END) as combat,
                SUM(CASE WHEN sig.status = 'done' THEN 1 ELSE 0 END) as done")
            ->groupBy('c.name')
            ->orderByDesc('total')
            ->limit(10)
            ->get();
    }
}
