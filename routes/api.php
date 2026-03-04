<?php

declare(strict_types=1);

use App\Http\Controllers\AlertController;
use App\Http\Controllers\API\AiSettingController;
use App\Http\Controllers\API\ContentGenerationController;
use App\Http\Controllers\API\ImageUploadController;
use App\Http\Controllers\API\ScraperController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\ClickAnalyticsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\Integrations\PortalExportController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\PayoutApprovalController;
use App\Http\Controllers\PayoutBatchController;
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
        Route::post('/{trackingLink}/refresh-product', [TrackingLinkController::class, 'refreshProduct'])
            ->name('refresh-product')
            ->middleware('throttle:30,1');
    });

    /*
    |----------------------------------------------------------------------
    | Campaigns
    |----------------------------------------------------------------------
    */
    Route::prefix('campaigns')->name('api.campaigns.')->group(function () {
        Route::post('/sync', [CampaignController::class, 'syncFromShopee'])
            ->name('sync')
            ->middleware('throttle:3,1');
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
        Route::post('/{connection}/portal-export', [PortalExportController::class, 'upload'])
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
        Route::get('/{offerId}', [OfferController::class, 'show'])
            ->name('show')
            ->middleware('throttle:30,1');
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

    Route::prefix('alerts')->name('api.alerts.')->group(function () {
        Route::post('/evaluate', [AlertController::class, 'evaluate'])
            ->name('evaluate')
            ->middleware('throttle:30,1');
        Route::get('/templates', [AlertController::class, 'getTemplates'])
            ->name('templates.get')
            ->middleware('throttle:60,1');
        Route::put('/templates', [AlertController::class, 'updateTemplates'])
            ->name('templates.updateAll')
            ->middleware('throttle:30,1');
        Route::put('/templates/{metric}', [AlertController::class, 'updateTemplate'])
            ->name('templates.update')
            ->middleware('throttle:30,1');
        Route::post('/templates/{metric}/restore', [AlertController::class, 'restoreTemplate'])
            ->name('templates.restore')
            ->middleware('throttle:30,1');
        Route::get('/telegram-config', [AlertController::class, 'getTelegramConfig'])
            ->name('telegram-config.get')
            ->middleware('throttle:60,1');
        Route::put('/telegram-config', [AlertController::class, 'updateTelegramConfig'])
            ->name('telegram-config.update')
            ->middleware('throttle:30,1');
        Route::post('/telegram-config/test', [AlertController::class, 'testTelegramConfig'])
            ->name('telegram-config.test')
            ->middleware('throttle:30,1');
        Route::delete('/telegram-config', [AlertController::class, 'deleteTelegramConfig'])
            ->name('telegram-config.delete')
            ->middleware('throttle:30,1');
        Route::post('/rules', [AlertController::class, 'storeRule'])
            ->name('rules.store')
            ->middleware('throttle:30,1');
        Route::patch('/rules/{alertRule}', [AlertController::class, 'updateRule'])
            ->name('rules.update')
            ->middleware('throttle:60,1');
        Route::delete('/rules/{alertRule}', [AlertController::class, 'destroyRule'])
            ->name('rules.destroy')
            ->middleware('throttle:30,1');
        Route::post('/rules/{alertRule}/toggle', [AlertController::class, 'toggleRule'])
            ->name('rules.toggle')
            ->middleware('throttle:60,1');
        Route::post('/incidents/{incident}/seen', [AlertController::class, 'markSeen'])
            ->name('incidents.seen')
            ->middleware('throttle:60,1');
        Route::post('/incidents/{incident}/resolve', [AlertController::class, 'resolve'])
            ->name('incidents.resolve')
            ->middleware('throttle:60,1');
        Route::post('/incidents/{incident}/comment', [AlertController::class, 'addComment'])
            ->name('incidents.comment')
            ->middleware('throttle:60,1');
    });

    /*
    |----------------------------------------------------------------------
    | Finance
    |----------------------------------------------------------------------
    */
    Route::prefix('finance')->name('api.finance.')->group(function () {
        Route::post('/sync', [FinanceController::class, 'sync'])
            ->name('sync')
            ->middleware('throttle:3,1');
    });

    Route::prefix('payout-batches')->name('api.payout-batches.')->group(function () {
        Route::post('/', [PayoutBatchController::class, 'store'])
            ->name('store')
            ->middleware('throttle:20,1');
        Route::post('/{payoutBatch}/finalize', [PayoutBatchController::class, 'finalize'])
            ->name('finalize')
            ->middleware('throttle:20,1');
    });

    /*
    |----------------------------------------------------------------------
    | Payout Approvals
    |----------------------------------------------------------------------
    */
    Route::prefix('payout-approvals')->name('api.payout-approvals.')->group(function () {
        Route::get('/', [PayoutApprovalController::class, 'index'])->name('index');
        Route::post('/{userId}/approve', [PayoutApprovalController::class, 'approve'])
            ->name('approve')
            ->middleware('throttle:30,1');
        Route::post('/{userId}/reject', [PayoutApprovalController::class, 'reject'])
            ->name('reject')
            ->middleware('throttle:30,1');
    });

    /*
    |----------------------------------------------------------------------
    | AI Content Generation
    |----------------------------------------------------------------------
    */
    Route::prefix('links/{trackingLink}/content')->name('api.content.')->group(function () {
        Route::post('/generate', [ContentGenerationController::class, 'generate'])
            ->name('generate')
            ->middleware('throttle:10,1');
        Route::get('/history', [ContentGenerationController::class, 'history'])
            ->name('history');
        Route::get('/statistics', [ContentGenerationController::class, 'statistics'])
            ->name('statistics');
    });

    Route::post('images/upload', [ImageUploadController::class, 'upload'])
        ->name('api.images.upload')
        ->middleware('throttle:20,1');

    Route::get('ai/images/{filename}', [ImageUploadController::class, 'serve'])
        ->name('api.ai.image.serve')
        ->where('filename', '[a-zA-Z0-9_.-]+');

    Route::get('content-generations/{id}', [ContentGenerationController::class, 'show'])
        ->name('api.content-generations.show');

    Route::get('content-generations/{id}/status', [ContentGenerationController::class, 'status'])
        ->name('api.content-generations.status')
        ->middleware('throttle:120,1');

    /*
    |----------------------------------------------------------------------
    | AI Provider Settings (per-user)
    |----------------------------------------------------------------------
    */
    Route::prefix('ai/settings')->name('api.ai.settings.')->group(function () {
        Route::get('/',              [AiSettingController::class, 'index'])   ->name('index');
        Route::get('/providers',     [AiSettingController::class, 'providers'])->name('providers');
        Route::post('/',             [AiSettingController::class, 'upsert'])  ->name('upsert');
        Route::post('/test',         [AiSettingController::class, 'test'])    ->name('test')->middleware('throttle:5,1');
        Route::delete('/{key}',      [AiSettingController::class, 'destroy']) ->name('destroy');
    });

    /*
    |----------------------------------------------------------------------
    | AI Tools & Utilities
    |----------------------------------------------------------------------
    */
    Route::prefix('tools')->name('api.tools.')->group(function () {
        Route::post('/scrape-product', [ScraperController::class, 'scrapeProduct'])
            ->name('scrape-product')
            ->middleware('throttle:10,1');

    });
});
