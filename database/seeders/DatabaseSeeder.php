<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CampaignStatus;
use App\Enums\LinkStatus;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Campaign;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // ── Owner ──────────────────────────────────────────────────────────────
        $owner = User::updateOrCreate(
            ['email' => 'admin@affentra.com'],
            [
                'name'     => 'Admin Owner',
                'password' => Hash::make('password'),
                'role'     => UserRole::Owner,
                'status'   => UserStatus::Active,
                'depth'    => 0,
                'path'     => '',
            ]
        );

        // ── Leader ─────────────────────────────────────────────────────────────
        $leader = User::updateOrCreate(
            ['email' => 'leader@affentra.com'],
            [
                'name'      => 'Nguyễn Văn Leader',
                'password'  => Hash::make('password'),
                'role'      => UserRole::Leader,
                'status'    => UserStatus::Active,
                'parent_id' => $owner->id,
                'depth'     => 1,
                'path'      => (string) $owner->id,
            ]
        );

        // ── CTVs ───────────────────────────────────────────────────────────────
        $ctv1 = User::updateOrCreate(
            ['email' => 'ctv1@affentra.com'],
            [
                'name'      => 'Nguyễn Văn Hiếng',
                'password'  => Hash::make('password'),
                'role'      => UserRole::CTV,
                'status'    => UserStatus::Active,
                'parent_id' => $leader->id,
                'depth'     => 2,
                'path'      => $owner->id . '/' . $leader->id,
            ]
        );

        $ctv2 = User::updateOrCreate(
            ['email' => 'ctv2@affentra.com'],
            [
                'name'      => 'Trần Minh Đức',
                'password'  => Hash::make('password'),
                'role'      => UserRole::CTV,
                'status'    => UserStatus::Active,
                'parent_id' => $leader->id,
                'depth'     => 2,
                'path'      => $owner->id . '/' . $leader->id,
            ]
        );
    }
}
