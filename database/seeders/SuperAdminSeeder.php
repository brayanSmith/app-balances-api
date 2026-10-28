<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    /**
     * Crea (o actualiza) el super-admin con su propio tenant.
     */
    public function run(): void
    {
        $user = User::firstOrNew(['email' => 'admin@admin.com']);

        if (! $user->exists) {
            $user->tenant()->associate(Tenant::create(['nombre' => 'Administración']));
        }

        $user->forceFill([
            'name' => 'Super Admin',
            'password' => 'password123',
            'is_super_admin' => true,
        ])->save();
    }
}
