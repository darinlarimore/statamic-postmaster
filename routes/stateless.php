<?php

use Darinlarimore\Postmaster\Http\Controllers\Web\TrackingController;
use Darinlarimore\Postmaster\Http\Controllers\Web\UnsubscribeController;
use Darinlarimore\Postmaster\Http\Controllers\Web\WebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Endpoints hit by mail clients and providers rather than browsers with a
 * session: no CSRF, no cookies.
 */

Route::prefix(config('postmaster.route_prefix', 'postmaster'))
    ->name('postmaster.')
    ->group(function () {
        Route::get('t/open/{sendout}/{subscriber}', [TrackingController::class, 'open'])
            ->middleware('signed')
            ->name('track.open');

        Route::get('t/click/{link}/{subscriber}', [TrackingController::class, 'click'])
            ->middleware('signed')
            ->name('track.click');

        Route::post('unsubscribe/{subscriber}/one-click', [UnsubscribeController::class, 'oneClick'])
            ->middleware('signed')
            ->name('unsubscribe.one-click');

        Route::post('webhook/{provider}', WebhookController::class)
            ->name('webhook');
    });
