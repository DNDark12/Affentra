<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\SyncRun;
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
                if (!$request->user()) return null;
                $latest = SyncRun::latest('started_at')->first();
                if (!$latest) return ['status' => 'fresh', 'lastSyncAt' => null];
                if ($latest->status === 'failed') return ['status' => 'failed', 'lastSyncAt' => $latest->started_at->diffForHumans()];
                if ($latest->status === 'completed' && $latest->started_at->diffInMinutes(now()) > 60) return ['status' => 'delayed', 'nextSyncIn' => 'soon'];
                return ['status' => 'fresh', 'lastSyncAt' => $latest->started_at->diffForHumans()];
            },
        ]);
    }
}
