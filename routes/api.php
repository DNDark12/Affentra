<?php

declare(strict_types=1);

use App\Http\Controllers\CampaignController;
use App\Http\Controllers\ClickAnalyticsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\TrackingLinkController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All routes here are auto-prefixed with /api by Laravel.
| Auth required for all endpoints (session-based via Inertia CSRF).
|
*/

Route::middleware(['auth'])->group(function () {

    /*
    |----------------------------------------------------------------------
    | Dashboard
    |----------------------------------------------------------------------
    */
    Route::prefix('dashboard')->name('api.dashboard.')->group(function () {
        Route::get('/summary', [DashboardController::class, 'summary'])->name('summary');
    });

    /*
    |----------------------------------------------------------------------
    | Tracking Links
    |----------------------------------------------------------------------
    */
    Route::prefix('links')->name('api.links.')->group(function () {
        Route::get('/', [TrackingLinkController::class, 'list'])->name('list');
        Route::get('/{trackingLink}', [TrackingLinkController::class, 'showJson'])->name('show');
        Route::post('/', [TrackingLinkController::class, 'store'])
            ->name('store')
            ->middleware('throttle:20,1');
        Route::patch('/{trackingLink}', [TrackingLinkController::class, 'update'])
            ->name('update')
            ->middleware('throttle:30,1');
        Route::patch('/{trackingLink}/archive', [TrackingLinkController::class, 'archive'])
            ->name('archive')
            ->middleware('throttle:30,1');
    });

    /*
    |----------------------------------------------------------------------
    | Campaigns
    |----------------------------------------------------------------------
    */
    Route::prefix('campaigns')->name('api.campaigns.')->group(function () {
        Route::post('/', [CampaignController::class, 'store'])->name('store');
        Route::patch('/{campaign}', [CampaignController::class, 'update'])->name('update');
    });

    /*
    |----------------------------------------------------------------------
    | Orders & Commissions
    |----------------------------------------------------------------------
    */
    Route::prefix('orders')->name('api.orders.')->group(function () {
        Route::get('/', [OrderController::class, 'list'])->name('list');
        Route::post('/import', [OrderController::class, 'import'])
            ->name('import')
            ->middleware('throttle:3,1');
        Route::get('/import/{syncRunId}/status', [OrderController::class, 'importStatus'])
            ->name('import.status');
    });

    /*
    |----------------------------------------------------------------------
    | Partners / CTV
    |----------------------------------------------------------------------
    */
    Route::prefix('partners')->name('api.partners.')->group(function () {
        Route::post('/', [PartnerController::class, 'store'])->name('store');
    });

    /*
    |----------------------------------------------------------------------
    | Integrations & Sync
    |----------------------------------------------------------------------
    */
    Route::prefix('integrations')->name('api.integrations.')->group(function () {
        Route::post('/', [IntegrationController::class, 'store'])->name('store');
        Route::patch('/{connection}', [IntegrationController::class, 'update'])->name('update');
        Route::post('/{connection}/portal-export', [\App\Http\Controllers\Integrations\PortalExportController::class, 'upload'])
            ->name('portal-export.upload')
            ->middleware('throttle:10,1');
        Route::delete('/{connection}', [IntegrationController::class, 'destroy'])->name('destroy');
        Route::get('/{connection}/history', [IntegrationController::class, 'syncHistory'])->name('history');
        Route::post('/{connection}/sync', [IntegrationController::class, 'syncNow'])
            ->name('sync')
            ->middleware('throttle:3,1');
        Route::post('/{connection}/test', [IntegrationController::class, 'testConnection'])
            ->name('test')
            ->middleware('throttle:5,1');
    });

    /*
    |----------------------------------------------------------------------
    | Offers / Product Discovery
    |----------------------------------------------------------------------
    */
    Route::prefix('offers')->name('api.offers.')->group(function () {
        Route::get('/search', [OfferController::class, 'search'])
            ->name('search')
            ->middleware('throttle:30,1');
        Route::post('/get-link', [OfferController::class, 'getLink'])
            ->name('getLink')
            ->middleware('throttle:20,1');
        Route::get('/categories', [OfferController::class, 'categories'])
            ->name('categories');
    });

    /*
    |----------------------------------------------------------------------
    | Click Analytics
    |----------------------------------------------------------------------
    */
    Route::prefix('analytics/clicks')->name('api.analytics.clicks.')->group(function () {
        Route::get('/summary', [ClickAnalyticsController::class, 'summary'])->name('summary');
        Route::get('/report', [ClickAnalyticsController::class, 'reportData'])->name('report');
        Route::get('/conversion', [ClickAnalyticsController::class, 'conversionData'])->name('conversion');
        Route::post('/export', [ClickAnalyticsController::class, 'exportData'])->name('export')
            ->middleware('throttle:60,1');
    });
});
