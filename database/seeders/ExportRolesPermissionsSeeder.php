<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\File;

class ExportRolesPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Obtener todos los roles y permisos
        $roles = Role::with('permissions')->get();
        $permissions = Permission::all();

        $export = [
            'timestamp' => now()->toIso8601String(),
            'permissions' => $permissions->map(function ($permission) {
                return [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'guard_name' => $permission->guard_name,
                ];
            })->values()->toArray(),
            'roles' => $roles->map(function ($role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'guard_name' => $role->guard_name,
                    'permissions' => $role->permissions->map(function ($permission) {
                        return [
                            'id' => $permission->id,
                            'name' => $permission->name,
                        ];
                    })->values()->toArray(),
                ];
            })->values()->toArray(),
        ];

        // Crear directorio si no existe
        $exportDir = storage_path('exports');
        if (!File::exists($exportDir)) {
            File::makeDirectory($exportDir, 0755, true);
        }

        // Guardar como JSON
        $fileName = 'roles_permissions_' . now()->format('Y-m-d_H-i-s') . '.json';
        $filePath = $exportDir . '/' . $fileName;
        
        File::put($filePath, json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->command->info('✓ Roles y Permisos exportados exitosamente');
        $this->command->info('Archivo: ' . $filePath);
        $this->command->info('Total Permisos: ' . count($export['permissions']));
        $this->command->info('Total Roles: ' . count($export['roles']));
        $this->command->line('');
        $this->command->info('Detalle de Roles:');
        foreach ($export['roles'] as $role) {
            $permCount = count($role['permissions']);
            $this->command->info("  - {$role['name']}: {$permCount} permisos");
        }
    }
}
