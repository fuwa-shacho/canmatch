<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PointLogMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_point_log_can_be_saved(): void
    {
        $user = User::factory()->create();
        $machineId = $this->createMachine();
        $createdAt = '2026-10-18 06:00:00';

        $id = DB::table('point_logs')->insertGetId([
            'user_id' => $user->id,
            'type' => 'registration',
            'points' => 10,
            'vending_machine_id' => $machineId,
            'created_at' => $createdAt,
        ]);

        $this->assertDatabaseHas('point_logs', [
            'id' => $id,
            'user_id' => $user->id,
            'type' => 'registration',
            'points' => 10,
            'vending_machine_id' => $machineId,
            'created_at' => $createdAt,
        ]);
    }

    public function test_point_log_can_be_saved_without_a_vending_machine(): void
    {
        $user = User::factory()->create();

        $id = DB::table('point_logs')->insertGetId([
            'user_id' => $user->id,
            'type' => 'rare_bonus',
            'points' => 25,
            'vending_machine_id' => null,
            'created_at' => '2026-10-18 06:00:00',
        ]);

        $this->assertDatabaseHas('point_logs', [
            'id' => $id,
            'vending_machine_id' => null,
        ]);
    }

    public function test_each_point_log_type_can_be_saved(): void
    {
        $user = User::factory()->create();
        $createdAt = '2026-10-18 06:00:00';

        DB::table('point_logs')->insert([
            [
                'user_id' => $user->id,
                'type' => 'registration',
                'points' => 10,
                'vending_machine_id' => null,
                'created_at' => $createdAt,
            ],
            [
                'user_id' => $user->id,
                'type' => 'update',
                'points' => 5,
                'vending_machine_id' => null,
                'created_at' => $createdAt,
            ],
            [
                'user_id' => $user->id,
                'type' => 'rare_bonus',
                'points' => 25,
                'vending_machine_id' => null,
                'created_at' => $createdAt,
            ],
        ]);

        $this->assertSame(
            ['rare_bonus', 'registration', 'update'],
            DB::table('point_logs')->orderBy('type')->pluck('type')->all()
        );
    }

    public function test_mysql_rejects_an_invalid_point_log_type(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('typeのCHECK制約は対象環境のMySQL 8で確認する。');
        }

        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('point_logs')->insert([
            'user_id' => $user->id,
            'type' => 'invalid',
            'points' => 10,
            'vending_machine_id' => null,
            'created_at' => '2026-10-18 06:00:00',
        ]);
    }

    public function test_nonexistent_user_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        DB::table('point_logs')->insert($this->pointLogAttributes([
            'user_id' => 999999,
        ]));
    }

    public function test_nonexistent_vending_machine_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('point_logs')->insert($this->pointLogAttributes([
            'user_id' => $user->id,
            'vending_machine_id' => 999999,
        ]));
    }

    public function test_nonexistent_product_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('point_logs')->insert($this->pointLogAttributes([
            'user_id' => $user->id,
            'type' => 'rare_bonus',
            'product_id' => 999999,
        ]));
    }

    public function test_retroactive_rare_bonus_is_unique_per_product(): void
    {
        $user = User::factory()->create();
        $product = Product::create(['name' => '一意制約確認商品']);
        $attributes = $this->pointLogAttributes([
            'user_id' => $user->id,
            'type' => 'rare_bonus',
            'product_id' => $product->id,
        ]);

        DB::table('point_logs')->insert($attributes);

        $this->expectException(QueryException::class);
        DB::table('point_logs')->insert($attributes);
    }

    public function test_deleting_a_user_cascades_to_point_logs(): void
    {
        $user = User::factory()->create();
        $id = DB::table('point_logs')->insertGetId($this->pointLogAttributes([
            'user_id' => $user->id,
        ]));

        DB::table('users')->where('id', $user->id)->delete();

        $this->assertDatabaseMissing('point_logs', ['id' => $id]);
    }

    public function test_deleting_a_vending_machine_preserves_point_log_and_clears_reference(): void
    {
        $user = User::factory()->create();
        $machineId = $this->createMachine();
        $id = DB::table('point_logs')->insertGetId($this->pointLogAttributes([
            'user_id' => $user->id,
            'vending_machine_id' => $machineId,
        ]));

        DB::table('vending_machines')->where('id', $machineId)->delete();

        $this->assertDatabaseHas('point_logs', [
            'id' => $id,
            'user_id' => $user->id,
            'vending_machine_id' => null,
        ]);
    }

    public function test_deleting_a_product_preserves_point_log_and_clears_reference(): void
    {
        $user = User::factory()->create();
        $product = Product::create(['name' => '削除確認商品']);
        $id = DB::table('point_logs')->insertGetId($this->pointLogAttributes([
            'user_id' => $user->id,
            'type' => 'rare_bonus',
            'product_id' => $product->id,
        ]));

        $product->delete();

        $this->assertDatabaseHas('point_logs', [
            'id' => $id,
            'user_id' => $user->id,
            'product_id' => null,
        ]);
    }

    public function test_required_columns_reject_null_values(): void
    {
        $user = User::factory()->create();

        foreach (['user_id', 'type', 'points', 'created_at'] as $column) {
            try {
                DB::table('point_logs')->insert($this->pointLogAttributes([
                    'user_id' => $user->id,
                    $column => null,
                ]));
                $this->fail("The {$column} column should reject NULL.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function pointLogAttributes(array $overrides = []): array
    {
        return array_merge([
            'user_id' => 1,
            'type' => 'registration',
            'points' => 10,
            'vending_machine_id' => null,
            'created_at' => '2026-10-18 06:00:00',
        ], $overrides);
    }

    private function createMachine(): int
    {
        $now = now()->utc();

        return DB::table('vending_machines')->insertGetId([
            'latitude' => 33.5903547,
            'longitude' => 130.4017155,
            'maker' => null,
            'photo_path' => 'vending-machines/development/sample.jpg',
            'user_id' => null,
            'last_confirmed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
