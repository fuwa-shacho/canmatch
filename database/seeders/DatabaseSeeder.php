<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $names = ['デモ太郎', 'デモ花子', 'デモ次郎'];

        foreach ($names as $i => $name) {
            User::factory()->create([
                'name' => $name,
                'email' => 'demo'.($i + 1).'@example.com',
                'password' => bcrypt('password'),
            ]);
        }
    }
};