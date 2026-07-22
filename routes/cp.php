<?php

use Darinlarimore\StatamicEmailSubscribe\Http\Controllers\Cp\DashboardController;
use Darinlarimore\StatamicEmailSubscribe\Http\Controllers\Cp\ListsController;
use Darinlarimore\StatamicEmailSubscribe\Http\Controllers\Cp\SendoutsController;
use Darinlarimore\StatamicEmailSubscribe\Http\Controllers\Cp\SubscribersController;
use Illuminate\Support\Facades\Route;

Route::name('email-subscribe.')
    ->prefix('email-subscribe')
    ->middleware('can:view email subscribe')
    ->group(function () {
        Route::get('/', DashboardController::class)->name('index');

        Route::middleware('can:edit email subscribe lists')->group(function () {
            Route::get('lists', [ListsController::class, 'index'])->name('lists.index');
            Route::post('lists', [ListsController::class, 'store'])->name('lists.store');
            Route::post('lists/{list}', [ListsController::class, 'update'])->name('lists.update');
            Route::delete('lists/{list}', [ListsController::class, 'destroy'])->name('lists.destroy');
        });

        Route::middleware('can:edit email subscribe subscribers')->group(function () {
            Route::get('subscribers', [SubscribersController::class, 'index'])->name('subscribers.index');
            Route::get('subscribers/export', [SubscribersController::class, 'export'])->name('subscribers.export');
            Route::get('subscribers/{subscriber}', [SubscribersController::class, 'show'])->name('subscribers.show');
            Route::delete('subscribers/{subscriber}', [SubscribersController::class, 'destroy'])->name('subscribers.destroy');
        });

        Route::middleware('can:send email subscribe sendouts')->group(function () {
            Route::get('sendouts', [SendoutsController::class, 'index'])->name('sendouts.index');
            Route::get('sendouts/create', [SendoutsController::class, 'create'])->name('sendouts.create');
            Route::post('sendouts', [SendoutsController::class, 'store'])->name('sendouts.store');
            Route::get('sendouts/{sendout}', [SendoutsController::class, 'show'])->name('sendouts.show');
            Route::get('sendouts/{sendout}/edit', [SendoutsController::class, 'edit'])->name('sendouts.edit');
            Route::post('sendouts/{sendout}', [SendoutsController::class, 'update'])->name('sendouts.update');
            Route::delete('sendouts/{sendout}', [SendoutsController::class, 'destroy'])->name('sendouts.destroy');
            Route::get('sendouts/{sendout}/preview', [SendoutsController::class, 'preview'])->name('sendouts.preview');
            Route::post('sendouts/{sendout}/test', [SendoutsController::class, 'sendTest'])->name('sendouts.test');
            Route::post('sendouts/{sendout}/start', [SendoutsController::class, 'start'])->name('sendouts.start');
            Route::post('sendouts/{sendout}/pause', [SendoutsController::class, 'pause'])->name('sendouts.pause');
            Route::post('sendouts/{sendout}/resume', [SendoutsController::class, 'resume'])->name('sendouts.resume');
            Route::post('sendouts/{sendout}/cancel', [SendoutsController::class, 'cancel'])->name('sendouts.cancel');
        });
    });
