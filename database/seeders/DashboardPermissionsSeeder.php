<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DashboardPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'View:SuperAdminDashboard',
            'View:EditorialDashboard',
            'View:EducatorDashboard',
            'View:ModerationDashboard',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        Role::firstOrCreate(['name' => 'moderador', 'guard_name' => 'web'])
            ->givePermissionTo('View:ModerationDashboard');
        Role::where('name', 'editor')->first()?->givePermissionTo('View:EditorialDashboard');
        Role::where('name', 'educador')->first()?->givePermissionTo('View:EducatorDashboard');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
