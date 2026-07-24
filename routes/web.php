<?php

use Darinlarimore\Postmaster\Http\Controllers\Web\EntryPreviewController;
use Darinlarimore\Postmaster\Http\Controllers\Web\PreferencesController;
use Darinlarimore\Postmaster\Http\Controllers\Web\SubscribeController;
use Darinlarimore\Postmaster\Http\Controllers\Web\UnsubscribeController;
use Darinlarimore\Postmaster\Http\Controllers\Web\VerifyController;
use Illuminate\Support\Facades\Route;

Route::prefix(config('postmaster.route_prefix', 'postmaster'))
    ->name('postmaster.')
    ->group(function () {
        Route::post('subscribe', [SubscribeController::class, 'store'])->name('subscribe');

        Route::get('entry-preview/{entry}', EntryPreviewController::class)->name('entry-preview');

        Route::get('verify/{subscriber}', VerifyController::class)
            ->middleware('signed')
            ->name('verify');

        Route::get('unsubscribe/{subscriber}', [UnsubscribeController::class, 'unsubscribe'])
            ->middleware('signed')
            ->name('unsubscribe');

        Route::get('preferences/{subscriber}', [PreferencesController::class, 'show'])
            ->middleware('signed')
            ->name('preferences');

        Route::post('preferences/{subscriber}', [PreferencesController::class, 'update'])
            ->middleware('signed')
            ->name('preferences.update');
    });
