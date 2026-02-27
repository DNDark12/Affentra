<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Auth\ConfirmPartnerController;
use App\Http\Controllers\ClickAnalyticsController;
use App\Http\Controllers\RedirectController;
use App\Http\Controllers\TrackingLinkController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\FinanceController;
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
    Route::get('/integrations', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::get('/offers', [OfferController::class, 'index'])->name('offers.index');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile/settings', [ProfileController::class, 'updateSettings'])->name('profile.settings.update');
    Route::put('/profile/payout', [ProfileController::class, 'updatePayout'])->name('profile.payout.update');

    // Click Analysis
    Route::get('/clicks', [ClickAnalyticsController::class, 'index'])->name('clicks.index');
    Route::get('/clicks/report', [ClickAnalyticsController::class, 'report'])->name('clicks.report');
    Route::get('/clicks/conversion', [ClickAnalyticsController::class, 'conversion'])->name('clicks.conversion');

    // Finance
    Route::get('/finance', [FinanceController::class, 'index'])->name('finance.index');
    Route::post('/finance/sync', [FinanceController::class, 'sync'])->name('finance.sync');
});

/*
|--------------------------------------------------------------------------
| API Routes (Session-based for Inertia)
|--------------------------------------------------------------------------
| Enforces 'web' middleware to guarantee CSRF protection and session state.
*/
Route::prefix('api')->middleware(['web'])->group(base_path('routes/api.php'));
