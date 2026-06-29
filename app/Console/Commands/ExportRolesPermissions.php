<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\File;

class ExportRolesPermissions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:export-roles-permissions {--format=json : Formato de exportación (json, csv)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Exporta roles y permisos del sistema a un archivo';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $format = $this->option('format');

        $roles = Role::with('permissions')->get();
        $permissions = Permission::all();

        if ($format === 'json') {
            return $this->exportJson($roles, $permissions);
        } elseif ($format === 'csv') {
            return $this->exportCsv($roles, $permissions);
        } else {
            $this->error("Formato no soportado: {$format}");
            return 1;
        }
    }

    /**
     * Exportar como JSON
     */
    private function exportJson($roles, $permissions): int
    {
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
                    'permissions_count' => $role->permissions->count(),
                ];
            })->values()->toArray(),
        ];

        $exportDir = storage_path('exports');
        if (!File::exists($exportDir)) {
            File::makeDirectory($exportDir, 0755, true);
        }

        $fileName = 'roles_permissions_' . now()->format('Y-m-d_H-i-s') . '.json';
        $filePath = $exportDir . '/' . $fileName;

        File::put($filePath, json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info('✓ Exportación completada exitosamente');
        $this->line('');
        $this->info("📁 Archivo: {$fileName}");
        $this->info("📍 Ruta: {$filePath}");
        $this->line('');
        $this->info('📊 Estadísticas:');
        $this->info("  • Total Permisos: " . count($export['permissions']));
        $this->info("  • Total Roles: " . count($export['roles']));
        $this->line('');
        $this->info('📋 Detalle de Roles:');
        foreach ($export['roles'] as $role) {
            $perms = $role['permissions_count'];
            $this->info("  • {$role['name']}: {$perms} permisos");
        }

        return 0;
    }

    /**
     * Exportar como CSV
     */
    private function exportCsv($roles, $permissions): int
    {
        $exportDir = storage_path('exports');
        if (!File::exists($exportDir)) {
            File::makeDirectory($exportDir, 0755, true);
        }

        // CSV de Permisos
        $permFile = 'permissions_' . now()->format('Y-m-d_H-i-s') . '.csv';
        $permPath = $exportDir . '/' . $permFile;
        $permHandle = fopen($permPath, 'w');
        fputcsv($permHandle, ['ID', 'Nombre', 'Guard']);

        foreach ($permissions as $permission) {
            fputcsv($permHandle, [$permission->id, $permission->name, $permission->guard_name]);
        }
        fclose($permHandle);

        // CSV de Roles y sus Permisos
        $roleFile = 'roles_' . now()->format('Y-m-d_H-i-s') . '.csv';
        $rolePath = $exportDir . '/' . $roleFile;
        $roleHandle = fopen($rolePath, 'w');
        fputcsv($roleHandle, ['Rol ID', 'Nombre Rol', 'Guard', 'Permiso ID', 'Nombre Permiso']);

        foreach ($roles as $role) {
            if ($role->permissions->isEmpty()) {
                fputcsv($roleHandle, [$role->id, $role->name, $role->guard_name, '', '']);
            } else {
                foreach ($role->permissions as $permission) {
                    fputcsv($roleHandle, [
                        $role->id,
                        $role->name,
                        $role->guard_name,
                        $permission->id,
                        $permission->name
                    ]);
                }
            }
        }
        fclose($roleHandle);

        $this->info('✓ Exportación CSV completada exitosamente');
        $this->line('');
        $this->info("📁 Archivos creados:");
        $this->info("  • {$permFile}");
        $this->info("  • {$roleFile}");
        $this->line('');
        $this->info("📍 Ruta: {$exportDir}");

        return 0;
    }
}
