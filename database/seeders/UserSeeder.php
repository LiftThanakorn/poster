<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'personnel.data@reru.ac.th',
            'password' => bcrypt('personnel101'),
            'role' => 'super_admin',
        ]);
    }
}
