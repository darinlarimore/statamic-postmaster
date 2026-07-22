<?php

use Darinlarimore\StatamicEmailSubscribe\Http\Controllers\Web\PreferencesController;
use Darinlarimore\StatamicEmailSubscribe\Http\Controllers\Web\SubscribeController;
use Darinlarimore\StatamicEmailSubscribe\Http\Controllers\Web\UnsubscribeController;
use Darinlarimore\StatamicEmailSubscribe\Http\Controllers\Web\VerifyController;
use Illuminate\Support\Facades\Route;

Route::prefix(config('email_subscribe.route_prefix', 'email-subscribe'))
    ->name('email-subscribe.')
    ->group(function () {
        Route::post('subscribe', [SubscribeController::class, 'store'])->name('subscribe');

        Route::post('unsubscribe', [UnsubscribeController::class, 'request'])->name('unsubscribe.request');

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
