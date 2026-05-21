<?php

namespace Database\Seeders;

use App\Models\SyncSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@karavankids.local'],
            ['name' => 'Admin', 'password' => Hash::make('admin123')]
        );

        $defaults = [
            'interval_minutes' => '15',
            'otomatik_aktif' => '0',
            'last_run_at' => '',
        ];
        foreach ($defaults as $k => $v) {
            SyncSetting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
    }
}
