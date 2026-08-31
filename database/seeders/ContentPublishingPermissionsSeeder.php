<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ContentPublishingPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $announcementPermissions = [
            'ViewAny:Announcement',
            'View:Announcement',
            'Create:Announcement',
            'Update:Announcement',
            'Delete:Announcement',
        ];
        $workflowPermissions = [
            'SubmitForReview:EducationalContent',
            'Review:EducationalContent',
            'Publish:EducationalContent',
        ];

        foreach ([...$announcementPermissions, ...$workflowPermissions] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        Role::where('name', 'educador')->first()?->givePermissionTo('SubmitForReview:EducationalContent');
        Role::where('name', 'editor')->first()?->givePermissionTo([
            ...$announcementPermissions,
            ...$workflowPermissions,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
