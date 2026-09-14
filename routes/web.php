<?php

use App\Http\Controllers\Auth\EveAuthController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'show'])->name('home');

Route::get('/auth/eve', [EveAuthController::class, 'redirect'])->name('eve.login');
Route::get('/auth/eve/callback', [EveAuthController::class, 'callback'])->name('eve.callback');
Route::post('/auth/eve/logout', [EveAuthController::class, 'logout'])->name('eve.logout');
