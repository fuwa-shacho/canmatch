<?php

namespace Tests\Feature;

use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_at_least_twenty_researched_products(): void
    {
        $this->seed(ProductSeeder::class);

        $this->assertDatabaseCount('products', 25);
        $this->assertDatabaseHas('products', [
            'name' => '三ツ矢サイダー',
            'maker' => 'アサヒ飲料',
            'volume_ml' => 430,
            'category' => '炭酸飲料',
        ]);
        $this->assertDatabaseHas('products', [
            'name' => 'キリン 生茶',
            'maker' => 'キリンビバレッジ',
            'volume_ml' => 525,
            'category' => '茶系飲料',
        ]);
        $this->assertDatabaseHas('products', [
            'name' => '健康ミネラルむぎ茶',
            'maker' => '伊藤園',
            'volume_ml' => 600,
            'category' => '茶系飲料',
        ]);
    }

    public function test_it_can_be_run_again_without_duplicating_products(): void
    {
        $this->seed(ProductSeeder::class);
        $this->seed(ProductSeeder::class);

        $this->assertDatabaseCount('products', 25);
        $this->assertSame(
            1,
            DB::table('products')
                ->where('name', 'コカ・コーラ')
                ->where('maker', '日本コカ・コーラ')
                ->where('volume_ml', 500)
                ->count(),
        );
    }
}
