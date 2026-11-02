<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // No users: the first one is created on the setup page (/setup).
        $this->call(DeviceSeeder::class);
    }
}
