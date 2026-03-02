<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $adminEmail = (string) env('SEED_ADMIN_EMAIL', 'admin@affentra.com');
        $adminPassword = (string) env('SEED_ADMIN_PASSWORD', 'password');

        // Seed only minimum account for system access (no mock hierarchy/data).
        $owner = User::updateOrCreate(
            ['email' => $adminEmail],
            [
                'name'     => 'Admin Owner',
                'password' => Hash::make($adminPassword),
                'role'     => UserRole::Owner,
                'status'   => UserStatus::Active,
                'depth'    => 0,
                'path'     => '',
            ]
        );

        // Keep one integration connection for manual testing only.
        PlatformConnection::updateOrCreate(
            [
                'user_id' => $owner->id,
                'platform' => 'shopee',
                'method' => 'open_api',
            ],
            [
                'label' => 'Shopee Test Connection',
                'status' => 'inactive',
                'sync_mode' => 'manual',
                'cookie_source' => null,
            ]
        );
    }
}
