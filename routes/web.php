<?php

declare(strict_types=1);

use App\Http\Controllers\AiSettingController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\ConfirmPartnerController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\API\TikTokAuthController;
use App\Http\Controllers\ContentStudioController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\ClickAnalyticsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\PayoutBatchController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RedirectController;
use App\Http\Controllers\TrackingLinkController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

Route::get('/go/{code}', [RedirectController::class, 'handle'])
    ->name('redirect')
    ->middleware(['throttle:' . config('integrations.rate_limits.redirect', 60) . ',1']);

Route::get('/partner/confirm/{id}', [ConfirmPartnerController::class, 'confirm'])
    ->name('partner.confirm')
    ->middleware('signed');

/*
|--------------------------------------------------------------------------
| Auth Routes (guest only)
|--------------------------------------------------------------------------
*/

Route::middleware(['guest'])->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.post')->middleware('throttle:5,1');
    Route::get('/auth/google/redirect', [SocialAuthController::class, 'redirectToGoogle'])->name('auth.google.redirect');
    Route::get('/auth/google/callback', [SocialAuthController::class, 'handleGoogleCallback'])->name('auth.google.callback');

    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->name('register.post')->middleware('throttle:5,1');

    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])
        ->name('password.email')
        ->middleware('throttle:5,1');

    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [ResetPasswordController::class, 'reset'])
        ->name('password.update')
        ->middleware('throttle:5,1');
});

/*
|--------------------------------------------------------------------------
| Authenticated Page Routes (Inertia)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', HandleInertiaRequests::class])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Root redirect
    Route::get('/', fn () => redirect()->route('dashboard'));

    // Pages
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/links', [TrackingLinkController::class, 'index'])->name('links.index');
    Route::get('/links/{trackingLink}', [TrackingLinkController::class, 'show'])->name('links.show');
    Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/partners', [PartnerController::class, 'index'])->name('partners.index');
    Route::get('/partners/{partner}', [PartnerController::class, 'show'])->name('partners.show');
    Route::get('/integrations', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::get('/integrations/{connection}', [IntegrationController::class, 'show'])->name('integrations.show');
    Route::get('/offers', [OfferController::class, 'index'])->name('offers.index');
    Route::get('/offers/{offerId}', [OfferController::class, 'showPage'])->name('offers.show');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile/settings', [ProfileController::class, 'updateSettings'])->name('profile.settings.update');
    Route::put('/profile/payout', [ProfileController::class, 'updatePayout'])->name('profile.payout.update');

    // AI Settings
    Route::get('/settings/ai', [AiSettingController::class, 'index'])->name('settings.ai');

    // AI Content Studio
    Route::get('/ai/content', [ContentStudioController::class, 'index'])->name('ai.content.index');

    // Click Analysis
    Route::get('/clicks', [ClickAnalyticsController::class, 'index'])->name('clicks.index');
    Route::get('/clicks/report', [ClickAnalyticsController::class, 'report'])->name('clicks.report');
    Route::get('/clicks/conversion', [ClickAnalyticsController::class, 'conversion'])->name('clicks.conversion');

    // Alerts
    Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::get('/alerts/rules', [AlertController::class, 'rules'])->name('alerts.rules');
    Route::get('/alerts/telegram-rules', static function (Request $request) {
        return redirect()->route('alerts.rules', array_filter([
            'channel' => $request->query('channel', 'telegram'),
        ]));
    })->name('alerts.telegram-rules');
    Route::get('/alerts/templates', [AlertController::class, 'templates'])->name('alerts.templates');
    Route::get('/alerts/telegram-config', [AlertController::class, 'telegramConfig'])->name('alerts.telegram-config');
    Route::get('/alerts/incidents/{incident}', [AlertController::class, 'showIncident'])->name('alerts.incidents.show');

    // Finance
    Route::get('/finance', [FinanceController::class, 'index'])->name('finance.index');
    Route::post('/finance/sync', [FinanceController::class, 'sync'])
        ->name('finance.sync')
        ->middleware('throttle:3,1');
    Route::get('/finance/payout-batches', [PayoutBatchController::class, 'index'])
        ->name('finance.payout-batches.index');
    Route::get('/finance/payout-batches/{payoutBatch}', [PayoutBatchController::class, 'show'])
        ->name('finance.payout-batches.show');

    // ─── TikTok OAuth (needs session for state) ───
    Route::prefix('integrations/tiktok')->name('integrations.tiktok.')->group(function () {
        Route::get('/authorize', [TikTokAuthController::class, 'redirectToTikTok'])->name('authorize');
        Route::get('/callback', [TikTokAuthController::class, 'callback'])->name('callback');
    });
});

/*
|--------------------------------------------------------------------------
| API Routes (Session-based for Inertia)
|--------------------------------------------------------------------------
| Enforces 'web' middleware to guarantee CSRF protection and session state.
*/
Route::prefix('api')->middleware(['web'])->group(base_path('routes/api.php'));
