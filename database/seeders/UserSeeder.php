<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Crear Super Admin
        $superAdmin = User::firstOrCreate(
            ['email' => 'superadmin@biodiversidad.local'],
            [
                'full_name' => 'Super Administrador',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );

        // Asignar rol de super_admin
        $superAdmin->assignRole('super_admin');

        $this->command->info('Super Admin creado:');
        $this->command->info('- Email: superadmin@biodiversidad.local');
        $this->command->info('- Contraseña: password');
        $this->command->info('- Rol: super_admin');
    }
}
