<?php

use App\Http\Controllers\Auth\EveAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\RemapController;
use App\Http\Controllers\SkillsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'show'])->name('home');
Route::get('/skills', [SkillsController::class, 'index'])->name('skills');
Route::get('/remap', [RemapController::class, 'show'])->name('remap');

// Local-only helper so browser automation can reach authenticated pages
// without going through EVE SSO. Returns 404 outside the local environment.
Route::get('/dev/login/{characterId}', function (int $characterId) {
    abort_unless(app()->environment('local'), 404);
    abort_unless(\App\Models\Character::whereKey($characterId)->exists(), 404);

    session(['character_id' => $characterId]);

    return redirect()->route('home');
})->name('dev.login');

Route::get('/auth/eve', [EveAuthController::class, 'redirect'])->name('eve.login');
Route::get('/auth/eve/callback', [EveAuthController::class, 'callback'])->name('eve.callback');
Route::post('/auth/eve/logout', [EveAuthController::class, 'logout'])->name('eve.logout');
