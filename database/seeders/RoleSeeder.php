<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Limpiar cache de permisos
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Crear rol de Super Admin
        $superAdmin = Role::firstOrCreate(['name' => config('filament-shield.super_admin.name', 'super_admin')]);

        // Definir todos los permisos necesarios (Formato Shield: PascalCase)
        $permissions = [
            // Contenido Educativo
            'ViewAny:EducationalContent',
            'View:EducationalContent',
            'Create:EducationalContent',
            'Update:EducationalContent',
            'Delete:EducationalContent',
            'SubmitForReview:EducationalContent',
            'Review:EducationalContent',
            'Publish:EducationalContent',

            // Anuncios
            'ViewAny:Announcement',
            'View:Announcement',
            'Create:Announcement',
            'Update:Announcement',
            'Delete:Announcement',

            // Dashboards
            'View:SuperAdminDashboard',
            'View:EditorialDashboard',
            'View:EducatorDashboard',
            'View:ModerationDashboard',

            // Moderación
            'ViewAny:Report',
            'View:Report',
            'Update:Report',
            'Delete:Report',

            // Categorías
            'ViewAny:ContentCategory',
            'View:ContentCategory',
            'Create:ContentCategory',
            'Update:ContentCategory',
            'Delete:ContentCategory',

            // Widgets
            'View:PublishedContentsWidget',
            'View:TotalContentsWidget',
            'View:TotalViewsWidget',
        ];

        // Crear permisos si no existen
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Crear rol de Educador
        $educador = Role::firstOrCreate(['name' => 'educador']);

        // Crear rol de Editor
        $editor = Role::firstOrCreate(['name' => 'editor']);

        // Crear rol de Moderador
        $moderador = Role::firstOrCreate(['name' => 'moderador']);

        // Asignar permisos al rol Educador
        $educador->syncPermissions([
            // Contenido Educativo
            'ViewAny:EducationalContent',
            'View:EducationalContent',
            'Create:EducationalContent',
            'Update:EducationalContent',
            'Delete:EducationalContent',
            'SubmitForReview:EducationalContent',
            'View:EducatorDashboard',

            // Categorías (Solo crear para el modal inline)
            'Create:ContentCategory',

            // Widgets
            'View:PublishedContentsWidget',
            'View:TotalContentsWidget',
            'View:TotalViewsWidget',
        ]);

        // Asignar permisos al rol Editor
        $editor->syncPermissions([
            // Contenido Educativo
            'ViewAny:EducationalContent',
            'View:EducationalContent',
            'Create:EducationalContent',
            'Update:EducationalContent',
            'Delete:EducationalContent',
            'SubmitForReview:EducationalContent',
            'Review:EducationalContent',
            'Publish:EducationalContent',

            // Anuncios
            'ViewAny:Announcement',
            'View:Announcement',
            'Create:Announcement',
            'Update:Announcement',
            'Delete:Announcement',
            'View:EditorialDashboard',

            // Categorías (Acceso total)
            'ViewAny:ContentCategory',
            'View:ContentCategory',
            'Create:ContentCategory',
            'Update:ContentCategory',
            'Delete:ContentCategory',

            // Widgets
            'View:PublishedContentsWidget',
            'View:TotalContentsWidget',
            'View:TotalViewsWidget',
        ]);

        $moderador->syncPermissions([
            'View:ModerationDashboard',
            'ViewAny:Report',
            'View:Report',
            'Update:Report',
        ]);

        $this->command->info('Roles y permisos creados exitosamente:');
        $this->command->info('- Educador: '.$educador->permissions->count().' permisos');
        $this->command->info('- Editor: '.$editor->permissions->count().' permisos');
        $this->command->info('- Moderador: '.$moderador->permissions->count().' permisos');
    }
}
