<?php

use App\Http\Controllers\Auth\EveAuthController;
use App\Models\Character;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $character = ($id = session('character_id')) !== null
        ? Character::find($id)
        : null;

    return view('home', ['character' => $character]);
})->name('home');

Route::get('/auth/eve', [EveAuthController::class, 'redirect'])->name('eve.login');
Route::get('/auth/eve/callback', [EveAuthController::class, 'callback'])->name('eve.callback');
Route::post('/auth/eve/logout', [EveAuthController::class, 'logout'])->name('eve.logout');
