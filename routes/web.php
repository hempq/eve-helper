<?php

use App\Http\Controllers\Auth\EveAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FarmController;
use App\Http\Controllers\MarketController;
use App\Http\Controllers\PlannerController;
use App\Http\Controllers\RemapController;
use App\Http\Controllers\SkillsController;
use App\Http\Controllers\TradeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'show'])->name('home');
Route::get('/skills', [SkillsController::class, 'index'])->name('skills');
Route::get('/planner', [PlannerController::class, 'show'])->name('planner');
Route::get('/remap', [RemapController::class, 'show'])->name('remap');
Route::get('/market', [MarketController::class, 'show'])->name('market');
Route::get('/farm', [FarmController::class, 'show'])->name('farm');
Route::get('/trade', [TradeController::class, 'show'])->name('trade');
Route::get('/warzone', [\App\Http\Controllers\WarzoneController::class, 'show'])->name('warzone');
Route::get('/industry', function (\Illuminate\Http\Request $request) {
    $character = ($id = $request->session()->get('character_id')) !== null
        ? \App\Models\Character::find($id)
        : null;

    return $character === null
        ? redirect()->route('home')
        : view('industry', ['character' => $character]);
})->name('industry');
Route::get('/agents', function (\Illuminate\Http\Request $request) {
    $character = ($id = $request->session()->get('character_id')) !== null
        ? \App\Models\Character::find($id)
        : null;

    return $character === null
        ? redirect()->route('home')
        : view('agents', ['character' => $character]);
})->name('agents');
Route::get('/settings', [\App\Http\Controllers\SettingsController::class, 'show'])->name('settings');
Route::post('/settings', [\App\Http\Controllers\SettingsController::class, 'update'])->name('settings.update');

// Local-only helper so browser automation can reach authenticated pages
// without going through EVE SSO. Returns 404 outside the local environment.
Route::get('/dev/login/{characterId}', function (int $characterId) {
    abort_unless(app()->environment('local') || config('eve.allow_dev_login'), 404);
    abort_unless(\App\Models\Character::whereKey($characterId)->exists(), 404);

    session(['character_id' => $characterId]);

    return redirect()->route('home');
})->name('dev.login');

Route::get('/auth/eve', [EveAuthController::class, 'redirect'])->name('eve.login');
Route::get('/auth/eve/callback', [EveAuthController::class, 'callback'])->name('eve.callback');
Route::post('/auth/eve/logout', [EveAuthController::class, 'logout'])->name('eve.logout');

Route::post('/character/{characterId}/activate', function (int $characterId, \Illuminate\Http\Request $request) {
    abort_unless(\App\Models\Character::whereKey($characterId)->exists(), 404);
    $request->session()->put('character_id', $characterId);

    return back();
})->name('character.activate');
