<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_with_unknown_volume_can_be_registered(): void
    {
        $now = now()->utc();

        $id = DB::table('products')->insertGetId([
            'name' => '開発用商品',
            'maker' => null,
            'volume_ml' => null,
            'category' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $id,
            'name' => '開発用商品',
            'maker' => null,
            'volume_ml' => null,
            'category' => null,
        ]);
    }

    public function test_different_volumes_can_be_registered_as_separate_products(): void
    {
        $now = now()->utc();

        DB::table('products')->insert([
            [
                'name' => '開発用飲料',
                'maker' => '開発用メーカー',
                'volume_ml' => 350,
                'category' => '開発用カテゴリ',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => '開発用飲料',
                'maker' => '開発用メーカー',
                'volume_ml' => 500,
                'category' => '開発用カテゴリ',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $this->assertSame(2, DB::table('products')->where('name', '開発用飲料')->count());
    }

    public function test_mysql_rejects_zero_volume(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('容量のCHECK制約は対象環境のMySQL 8で確認する。');
        }

        $this->expectException(QueryException::class);

        DB::table('products')->insert([
            'name' => '開発用ゼロ容量商品',
            'maker' => null,
            'volume_ml' => 0,
            'category' => null,
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);
    }

    public function test_mysql_rejects_a_whitespace_only_name(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('商品名のCHECK制約は対象環境のMySQL 8で確認する。');
        }

        $this->expectException(QueryException::class);

        DB::table('products')->insert([
            'name' => '   ',
            'maker' => null,
            'volume_ml' => null,
            'category' => null,
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);
    }
}
