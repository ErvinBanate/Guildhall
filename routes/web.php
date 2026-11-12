<?php

use App\Http\Controllers\DiscordLogin;
use App\Http\Controllers\Verification;
use App\Http\Controllers\LootLogs;
use App\Http\Controllers\OcPermissions;
use App\Http\Middleware\DiscordMember;
use App\Http\Middleware\InertiaRequests;
use App\Http\Middleware\VerifyOrigin;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Inertia\Inertia;

Route::get('/login', fn () => Inertia::render('Login'))->name('login');
Route::get('/api/auth/login', [DiscordLogin::class, 'start']);
Route::get('/api/auth/callback', [DiscordLogin::class, 'callback'])->name('discord.callback');
Route::post('/logout', [DiscordLogin::class, 'logout'])->middleware(VerifyOrigin::class);
Route::post('/api/discord/interactions', [Verification::class, 'interaction'])
    ->withoutMiddleware([ValidateCsrfToken::class, StartSession::class, ShareErrorsFromSession::class, InertiaRequests::class]);

Route::middleware(DiscordMember::class)->group(function () {
    Route::get('/', [Verification::class, 'index'])->name('guildhall');
    Route::get('/dashboard', [Verification::class, 'dashboard']);
    Route::get('/characters', [Verification::class, 'characters']);
    Route::get('/characters/{player}', [Verification::class, 'characterOverview'])->where('player', '[A-Za-z0-9_-]{22}');
    Route::get('/guild', [Verification::class, 'guildStats']);
    Route::get('/loot-logs', [LootLogs::class, 'index']);
    Route::post('/loot-logs/save', [LootLogs::class, 'save'])->middleware(VerifyOrigin::class);
    Route::get('/battles', [Verification::class, 'battles']);
    Route::get('/battles/{battle}', [Verification::class, 'battle'])->where('battle', '[1-9][0-9]{0,14}');
    Route::get('/choose-character', [Verification::class, 'choose']);
    Route::get('/officer/verification', [Verification::class, 'inbox']);
    Route::get('/officer/oc', [OcPermissions::class, 'index']);
    Route::get('/officer/oc/exemptions', [OcPermissions::class, 'index'])->name('oc.exemptions');
    Route::post('/officer/oc/exemptions', [OcPermissions::class, 'setExemption'])->middleware(VerifyOrigin::class);
    Route::post('/officer/oc/record', [OcPermissions::class, 'record'])->middleware(VerifyOrigin::class);
    Route::post('/officer/oc/import', [OcPermissions::class, 'importLog'])->middleware(VerifyOrigin::class);
    Route::post('/verification', [Verification::class, 'create'])->middleware(VerifyOrigin::class);
});
