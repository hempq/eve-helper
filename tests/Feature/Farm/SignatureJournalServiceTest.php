<?php

namespace Tests\Feature\Farm;

use App\Models\Character;
use App\Services\Farm\SignatureJournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SignatureJournalServiceTest extends TestCase
{
    use RefreshDatabase;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->character = Character::factory()->create();

        DB::table('regions')->insert(['region_id' => 1, 'name' => 'Essence']);
        DB::table('constellations')->insert(['constellation_id' => 1, 'region_id' => 1, 'name' => 'Vieres']);
        DB::table('solar_systems')->insert([
            'system_id' => 100, 'constellation_id' => 1, 'region_id' => 1, 'name' => 'Ardallabier', 'security' => 0.5,
        ]);
    }

    public function test_ingest_tracks_new_updated_and_despawned(): void
    {
        $journal = $this->app->make(SignatureJournalService::class);

        // First scan: two unscanned sigs.
        $r1 = $journal->ingest($this->character, 100,
            "VOB-799\tCosmic Signature\t\t\t0.0%\t7.03 AU\n".
            "ABC-123\tCosmic Signature\t\t\t12.5%\t4.1 AU");
        $this->assertSame(['new' => 2, 'updated' => 0, 'gone' => 0], $r1);

        // Second scan: VOB resolved to a combat site, ABC despawned, new DEF.
        $r2 = $journal->ingest($this->character, 100,
            "VOB-799\tCosmic Signature\tCombat Site\tSerpentis Vigil\t100.0%\t7.03 AU\n".
            "DEF-456\tCosmic Signature\tGas Site\t\t75.0%\t1 AU");
        $this->assertSame(['new' => 1, 'updated' => 1, 'gone' => 1], $r2);

        $vob = DB::table('signatures')->where('sig_id', 'VOB-799')->first();
        $this->assertSame('Combat Site', $vob->category);
        $this->assertSame('Serpentis Vigil', $vob->name);
        $this->assertSame(100.0, (float) $vob->signal);
        $this->assertSame('active', $vob->status);

        $this->assertSame('gone', DB::table('signatures')->where('sig_id', 'ABC-123')->value('status'));

        $active = $journal->active($this->character);
        $this->assertEqualsCanonicalizing(['VOB-799', 'DEF-456'], $active->pluck('sig_id')->all());
    }

    public function test_escalations_expire_after_a_day(): void
    {
        $journal = $this->app->make(SignatureJournalService::class);

        $journal->addEscalation($this->character, 100, 'Serpentis Phi-Outpost');

        $this->assertCount(1, $journal->active($this->character));

        $this->travel(25)->hours();
        $this->assertCount(0, $journal->active($this->character));
    }

    public function test_escalations_survive_probe_pastes_and_mark_done_works(): void
    {
        $journal = $this->app->make(SignatureJournalService::class);
        $journal->addEscalation($this->character, 100, 'Escalation X');

        // A full probe paste without the escalation must NOT mark it gone.
        $journal->ingest($this->character, 100, "AAA-111\tCosmic Anomaly\tCombat Site\tSerpentis Hideaway\t100.0%\t2 AU");

        $active = $journal->active($this->character);
        $this->assertCount(2, $active);

        $escalation = $active->firstWhere('sig_group', 'Escalation');
        $journal->markDone($this->character, (int) $escalation->id);

        $this->assertCount(1, $journal->active($this->character));
        $this->assertSame('done', DB::table('signatures')->where('id', $escalation->id)->value('status'));
    }

    public function test_constellation_stats(): void
    {
        $journal = $this->app->make(SignatureJournalService::class);

        $journal->ingest($this->character, 100,
            "AAA-111\tCosmic Anomaly\tCombat Site\tSerpentis Hideaway\t100.0%\t2 AU\n".
            "BBB-222\tCosmic Signature\tData Site\tSomething\t100.0%\t2 AU");
        $journal->markDone($this->character, (int) DB::table('signatures')->where('sig_id', 'AAA-111')->value('id'));

        $stats = $journal->constellationStats($this->character);

        $this->assertCount(1, $stats);
        $this->assertSame('Vieres', $stats[0]->constellation);
        $this->assertSame(2, (int) $stats[0]->total);
        $this->assertSame(1, (int) $stats[0]->combat);
        $this->assertSame(1, (int) $stats[0]->done);
    }
}
