<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Crear rol de Educador
        $educador = Role::firstOrCreate(['name' => 'educador']);
        
        // Crear rol de Editor
        $editor = Role::firstOrCreate(['name' => 'editor']);
        
        // Crear permisos básicos para recursos educativos
        $permissions = [
            // Permisos para Cursos
            'view:Course',
            'create:Course',
            'update:Course',
            'delete:Course',
            
            // Permisos para Lecciones
            'view:Lesson',
            'create:Lesson',
            'update:Lesson',
            'delete:Lesson',
            
            // Permisos para Recursos Educativos
            'view:EducationalResource',
            'create:EducationalResource',
            'update:EducationalResource',
            'delete:EducationalResource',
            
            // Permisos para Observaciones
            'view:Observation',
            'update:Observation',
            'delete:Observation',
            
            // Permisos para Comentarios
            'view:Comment',
            'update:Comment',
            'delete:Comment',
            
            // Permisos para Usuarios (solo visualización)
            'view:User',
        ];
        
        // Crear todos los permisos
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
        
        // Asignar permisos al rol Educador
        $educador->syncPermissions([
            'view:Course',
            'create:Course',
            'update:Course',
            'view:Lesson',
            'create:Lesson',
            'update:Lesson',
            'view:EducationalResource',
            'create:EducationalResource',
            'update:EducationalResource',
            'view:User',
        ]);
        
        // Asignar permisos al rol Editor (más permisos que educador)
        $editor->syncPermissions([
            'view:Course',
            'create:Course',
            'update:Course',
            'delete:Course',
            'view:Lesson',
            'create:Lesson',
            'update:Lesson',
            'delete:Lesson',
            'view:EducationalResource',
            'create:EducationalResource',
            'update:EducationalResource',
            'delete:EducationalResource',
            'view:Observation',
            'update:Observation',
            'delete:Observation',
            'view:Comment',
            'update:Comment',
            'delete:Comment',
            'view:User',
        ]);
        
        $this->command->info('Roles y permisos creados exitosamente:');
        $this->command->info('- Educador: ' . $educador->permissions->count() . ' permisos');
        $this->command->info('- Editor: ' . $editor->permissions->count() . ' permisos');
    }
}
