<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use App\Services\Farm\SignatureJournalService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class SignatureJournal extends Component
{
    public Character $character;

    public string $paste = '';

    public string $escalationName = '';

    public ?string $notice = null;

    public function ingest(SignatureJournalService $journal, EsiClientInterface $esi): void
    {
        if (trim($this->paste) === '') {
            return;
        }

        $systemId = $this->currentSystemId($esi);

        if ($systemId === null) {
            $this->notice = 'Cannot read your location from ESI right now — try again shortly.';

            return;
        }

        $result = $journal->ingest($this->character, $systemId, $this->paste);

        $this->paste = '';
        $this->notice = sprintf('Logged: %d new, %d updated, %d despawned.', $result['new'], $result['updated'], $result['gone']);
    }

    public function addEscalation(SignatureJournalService $journal, EsiClientInterface $esi): void
    {
        if (trim($this->escalationName) === '') {
            return;
        }

        $systemId = $this->currentSystemId($esi);

        if ($systemId === null) {
            $this->notice = 'Cannot read your location from ESI right now — try again shortly.';

            return;
        }

        $journal->addEscalation($this->character, $systemId, trim($this->escalationName));
        $this->escalationName = '';
        $this->notice = 'Escalation logged — expires in 24 hours.';
    }

    public function setDestination(int $systemId, EsiClientInterface $esi): void
    {
        try {
            $esi->post('/ui/autopilot/waypoint', [
                'destination_id' => $systemId,
                'add_to_beginning' => 'false',
                'clear_other_waypoints' => 'true',
            ], $this->character);
            $this->notice = 'Destination sent to the EVE client. Fly safe o7';
        } catch (EsiErrorLimited|EsiRequestFailed $e) {
            $this->notice = 'Could not set the destination ('.$e->getMessage().')';
        }
    }

    public function markDone(int $signatureId, SignatureJournalService $journal): void
    {
        $journal->markDone($this->character, $signatureId);
    }

    public function render(SignatureJournalService $journal): View
    {
        return view('livewire.signature-journal', [
            'active' => $journal->active($this->character),
            'stats' => $journal->constellationStats($this->character),
        ]);
    }

    private function currentSystemId(EsiClientInterface $esi): ?int
    {
        try {
            $location = $esi->get("/characters/{$this->character->character_id}/location", [], $this->character);

            return (int) $location->data['solar_system_id'];
        } catch (EsiErrorLimited|EsiRequestFailed) {
            return null;
        }
    }
}
