<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ModerationPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['ViewAny:Report', 'View:Report', 'Update:Report', 'Delete:Report'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        Role::firstOrCreate(['name' => 'moderador', 'guard_name' => 'web'])
            ->givePermissionTo(['ViewAny:Report', 'View:Report', 'Update:Report']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
