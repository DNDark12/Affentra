<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user ? [
                    'id'     => $user->id,
                    'name'   => $user->name,
                    'email'  => $user->email,
                    'avatar' => $user->avatar,
                    'phone'  => $user->phone,
                    'role'   => $user->role,
                    'status' => $user->status,
                ] : null,
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error'   => $request->session()->get('error'),
                'status'  => $request->session()->get('status'),
            ],
            'ziggy' => function () use ($request) {
                return array_merge((new Ziggy())->toArray(), [
                    'location' => $request->url(),
                ]);
            },
            'constants' => config('affentra', []),
            'sync_status' => function () use ($request) {
                /** @var User|null $user */
                $user = $request->user();
                if (! $user) {
                    return null;
                }

                $connections = PlatformConnection::query()
                    ->where('user_id', $user->id)
                    ->get([
                        'id',
                        'status',
                        'sync_mode',
                        'last_sync_at',
                        'last_sync_status',
                        'last_error_at',
                    ]);

                if ($connections->isEmpty()) {
                    return ['status' => 'fresh', 'lastSyncAt' => null];
                }

                $latestFailureAt = $connections
                    ->filter(static function (PlatformConnection $connection): bool {
                        return $connection->status === 'error'
                            || (
                                str_starts_with((string) $connection->last_sync_status, 'failed')
                                && $connection->last_error_at !== null
                            );
                    })
                    ->map(static function (PlatformConnection $connection) {
                        return $connection->last_error_at ?? $connection->last_sync_at;
                    })
                    ->filter()
                    ->sortDesc()
                    ->first();

                if ($latestFailureAt !== null && $latestFailureAt->greaterThan(now()->subMinutes(10))) {
                    return [
                        'status' => 'failed',
                        'lastSyncAt' => $latestFailureAt->diffForHumans(),
                    ];
                }

                // Delayed warning only applies to active scheduled connections.
                $scheduledConnections = $connections->filter(static function (PlatformConnection $connection): bool {
                    return $connection->status === 'active' && $connection->sync_mode === 'scheduled';
                });

                if ($scheduledConnections->isNotEmpty()) {
                    $latestScheduledSyncAt = $scheduledConnections
                        ->pluck('last_sync_at')
                        ->filter()
                        ->sortDesc()
                        ->first();

                    if ($latestScheduledSyncAt === null || $latestScheduledSyncAt->diffInMinutes(now()) > 120) {
                        return ['status' => 'delayed', 'nextSyncIn' => 'soon'];
                    }
                }

                $latestSyncAt = $connections
                    ->pluck('last_sync_at')
                    ->filter()
                    ->sortDesc()
                    ->first();

                return [
                    'status' => 'fresh',
                    'lastSyncAt' => $latestSyncAt?->diffForHumans(),
                ];
            },
        ]);
    }
}
