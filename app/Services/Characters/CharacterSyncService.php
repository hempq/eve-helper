<?php

namespace App\Services\Characters;

use App\Models\Character;
use App\Services\Esi\EsiClientInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pulls the character's skills, skill queue and attributes from ESI into the
 * local database. Safe to call often: the ESI client already honors cache
 * timers, and a staleness guard skips full re-syncs done moments ago.
 */
class CharacterSyncService
{
    private const STALE_AFTER_MINUTES = 5;

    public function __construct(private readonly EsiClientInterface $esi) {}

    public function sync(Character $character, bool $force = false): void
    {
        if (! $force && $character->last_synced_at?->gt(now()->subMinutes(self::STALE_AFTER_MINUTES))) {
            return;
        }

        $this->syncSkills($character);
        $this->syncSkillQueue($character);
        $this->syncAttributes($character);
        $this->syncImplants($character);
        $this->syncClones($character);
        $this->syncWallet($character);
        $this->syncWalletJournal($character);
        $this->syncAssets($character);
        $this->syncOrders($character);
        $this->syncContracts($character);

        $character->forceFill(['last_synced_at' => CarbonImmutable::now()])->save();
    }

    private function syncSkills(Character $character): void
    {
        $response = $this->esi->get("/characters/{$character->character_id}/skills", [], $character);

        $rows = array_map(fn (array $skill) => [
            'character_id' => $character->character_id,
            'skill_id' => $skill['skill_id'],
            'trained_level' => $skill['trained_skill_level'],
            'active_level' => $skill['active_skill_level'],
            'skillpoints' => $skill['skillpoints_in_skill'],
        ], $response->data['skills']);

        DB::transaction(function () use ($character, $rows, $response) {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('character_skills')->upsert($chunk, ['character_id', 'skill_id']);
            }

            $character->forceFill([
                'total_sp' => $response->data['total_sp'] ?? null,
                'unallocated_sp' => $response->data['unallocated_sp'] ?? 0,
            ])->save();
        });
    }

    private function syncSkillQueue(Character $character): void
    {
        $response = $this->esi->get("/characters/{$character->character_id}/skillqueue", [], $character);

        $rows = array_map(fn (array $entry) => [
            'character_id' => $character->character_id,
            'position' => $entry['queue_position'],
            'skill_id' => $entry['skill_id'],
            'finished_level' => $entry['finished_level'],
            // Missing dates mean the queue is paused.
            'start_date' => isset($entry['start_date']) ? CarbonImmutable::parse($entry['start_date']) : null,
            'finish_date' => isset($entry['finish_date']) ? CarbonImmutable::parse($entry['finish_date']) : null,
            'level_start_sp' => $entry['level_start_sp'] ?? null,
            'level_end_sp' => $entry['level_end_sp'] ?? null,
            'training_start_sp' => $entry['training_start_sp'] ?? null,
        ], $response->data);

        // Positions shift on every queue change; replace wholesale.
        DB::transaction(function () use ($character, $rows) {
            DB::table('character_skill_queue')->where('character_id', $character->character_id)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('character_skill_queue')->insert($chunk);
            }
        });
    }

    private function syncWalletJournal(Character $character): void
    {
        $entries = $this->esi->getAllPages("/characters/{$character->character_id}/wallet/journal", [], $character);

        $rows = array_map(fn (array $entry) => [
            'journal_id' => $entry['id'],
            'character_id' => $character->character_id,
            'ref_type' => $entry['ref_type'],
            'amount' => $entry['amount'] ?? null,
            'balance' => $entry['balance'] ?? null,
            'date' => CarbonImmutable::parse($entry['date']),
            'context_id' => $entry['context_id'] ?? null,
            'context_id_type' => $entry['context_id_type'] ?? null,
            'description' => mb_substr($entry['description'] ?? '', 0, 500),
        ], $entries);

        // The journal is append-only (ESI serves ~30 days); keep history forever.
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('wallet_journal')->upsert($chunk, 'journal_id');
        }
    }

    private function syncAssets(Character $character): void
    {
        $assets = $this->esi->getAllPages("/characters/{$character->character_id}/assets", [], $character);

        $rows = array_map(fn (array $asset) => [
            'character_id' => $character->character_id,
            'item_id' => $asset['item_id'],
            'type_id' => $asset['type_id'],
            'quantity' => $asset['quantity'],
            'location_id' => $asset['location_id'],
            'location_flag' => $asset['location_flag'],
            'location_type' => $asset['location_type'],
            'is_singleton' => $asset['is_singleton'],
        ], $assets);

        DB::transaction(function () use ($character, $rows) {
            DB::table('character_assets')->where('character_id', $character->character_id)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('character_assets')->insert($chunk);
            }
        });
    }

    private function syncContracts(Character $character): void
    {
        // Requires esi-contracts.read_character_contracts.v1 — a re-login may
        // be needed to grant it; degrade quietly until then.
        try {
            $contracts = $this->esi->getAllPages("/characters/{$character->character_id}/contracts", [], $character);
        } catch (\App\Services\Esi\Exceptions\EsiRequestFailed) {
            return;
        }

        $rows = array_map(fn (array $c) => [
            'contract_id' => $c['contract_id'],
            'character_id' => $character->character_id,
            'type' => $c['type'],
            'status' => $c['status'],
            'title' => $c['title'] ?? null,
            'price' => $c['price'] ?? 0,
            'reward' => $c['reward'] ?? 0,
            'collateral' => $c['collateral'] ?? 0,
            'volume' => $c['volume'] ?? null,
            'for_corporation' => $c['for_corporation'] ?? false,
            'date_issued' => isset($c['date_issued']) ? CarbonImmutable::parse($c['date_issued']) : null,
            'date_expired' => isset($c['date_expired']) ? CarbonImmutable::parse($c['date_expired']) : null,
            'date_completed' => isset($c['date_completed']) ? CarbonImmutable::parse($c['date_completed']) : null,
        ], $contracts);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('character_contracts')->upsert($chunk, 'contract_id');
        }
    }

    private function syncOrders(Character $character): void
    {
        $response = $this->esi->get("/characters/{$character->character_id}/orders", [], $character);

        $rows = array_map(fn (array $order) => [
            'order_id' => $order['order_id'],
            'character_id' => $character->character_id,
            'type_id' => $order['type_id'],
            'location_id' => $order['location_id'],
            'region_id' => $order['region_id'],
            'is_buy_order' => $order['is_buy_order'] ?? false,
            'price' => $order['price'],
            'volume_remain' => $order['volume_remain'],
            'volume_total' => $order['volume_total'],
            'issued' => CarbonImmutable::parse($order['issued']),
        ], $response->data);

        DB::transaction(function () use ($character, $rows) {
            DB::table('character_orders')->where('character_id', $character->character_id)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('character_orders')->insert($chunk);
            }
        });
    }

    private function syncWallet(Character $character): void
    {
        $response = $this->esi->get("/characters/{$character->character_id}/wallet", [], $character);

        // The wallet endpoint returns a bare number; the ESI client wraps
        // scalar JSON responses in an array under key 0.
        $balance = is_array($response->data) ? ($response->data[0] ?? null) : $response->data;

        if (is_numeric($balance)) {
            $character->forceFill(['wallet_balance' => (float) $balance])->save();
        }
    }

    private function syncClones(Character $character): void
    {
        $data = $this->esi->get("/characters/{$character->character_id}/clones", [], $character)->data;

        $rows = array_map(fn (array $clone) => [
            'character_id' => $character->character_id,
            'jump_clone_id' => $clone['jump_clone_id'],
            'name' => $clone['name'] ?? null,
            'location_id' => $clone['location_id'],
            'location_type' => $clone['location_type'],
            'implants' => json_encode($clone['implants'] ?? []),
        ], $data['jump_clones'] ?? []);

        DB::transaction(function () use ($character, $rows, $data) {
            DB::table('character_clones')->where('character_id', $character->character_id)->delete();

            if ($rows !== []) {
                DB::table('character_clones')->insert($rows);
            }

            $character->forceFill([
                'last_clone_jump_date' => isset($data['last_clone_jump_date'])
                    ? CarbonImmutable::parse($data['last_clone_jump_date'])
                    : null,
                'home_location_id' => $data['home_location']['location_id'] ?? null,
                'home_location_type' => $data['home_location']['location_type'] ?? null,
            ])->save();
        });
    }

    private function syncImplants(Character $character): void
    {
        $response = $this->esi->get("/characters/{$character->character_id}/implants", [], $character);

        DB::transaction(function () use ($character, $response) {
            DB::table('character_implants')->where('character_id', $character->character_id)->delete();

            if ($response->data !== []) {
                DB::table('character_implants')->insert(array_map(fn (int $typeId) => [
                    'character_id' => $character->character_id,
                    'type_id' => $typeId,
                ], $response->data));
            }
        });
    }

    private function syncAttributes(Character $character): void
    {
        $response = $this->esi->get("/characters/{$character->character_id}/attributes", [], $character);
        $data = $response->data;

        $character->forceFill([
            'charisma' => $data['charisma'],
            'intelligence' => $data['intelligence'],
            'memory' => $data['memory'],
            'perception' => $data['perception'],
            'willpower' => $data['willpower'],
            'bonus_remaps' => $data['bonus_remaps'] ?? null,
            'last_remap_date' => isset($data['last_remap_date']) ? CarbonImmutable::parse($data['last_remap_date']) : null,
            'accrued_remap_cooldown_date' => isset($data['accrued_remap_cooldown_date']) ? CarbonImmutable::parse($data['accrued_remap_cooldown_date']) : null,
        ])->save();
    }
}
