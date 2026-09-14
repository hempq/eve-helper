<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Services\Esi\EsiClientInterface;
use App\Services\Esi\Exceptions\EsiErrorLimited;
use App\Services\Esi\Exceptions\EsiRequestFailed;
use App\Services\Universe\WarzoneService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WarzoneController extends Controller
{
    public function show(Request $request, WarzoneService $warzone, EsiClientInterface $esi): View|RedirectResponse
    {
        $character = ($id = $request->session()->get('character_id')) !== null
            ? Character::find($id)
            : null;

        if ($character === null) {
            return redirect()->route('home');
        }

        [$originId, $originName] = $this->origin($character, $esi);

        return view('warzone', [
            'character' => $character,
            'originName' => $originName,
            'incursions' => $warzone->incursions($originId),
            'fw' => $warzone->factionWarfare($originId),
        ]);
    }

    /**
     * @return array{0: ?int, 1: string}
     */
    private function origin(Character $character, EsiClientInterface $esi): array
    {
        try {
            $location = $esi->get("/characters/{$character->character_id}/location", [], $character);
            $systemId = (int) $location->data['solar_system_id'];

            return [$systemId, (string) (DB::table('solar_systems')->where('system_id', $systemId)->value('name') ?? "#{$systemId}")];
        } catch (EsiErrorLimited|EsiRequestFailed) {
            return [null, 'unknown'];
        }
    }
}
