<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Safe to run on every deploy: all seeders here are idempotent and never
     * overwrite what workspaces have edited.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            UserSeeder::class,
            EmailTemplateSeeder::class,
        ]);
    }
}
