<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Sin WithoutModelEvents: al crear un tenant se crean sus categorías.
     */
    public function run(): void
    {
        $this->call(SuperAdminSeeder::class);
    }
}
