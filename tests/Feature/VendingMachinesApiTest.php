<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VendingMachinesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_vending_machine_list(): void
    {
        $this->getJson('/api/vending-machines')
            ->assertUnauthorized();
    }

    public function test_empty_vending_machine_list_returns_an_empty_array(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/vending-machines')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_vending_machines_return_expected_types_precision_and_distinct_product_counts_in_id_order(): void
    {
        $this->actingAs(User::factory()->create());

        $firstMachineId = $this->createVendingMachine(
            '開発用メーカー',
            '33.5903547',
            '130.4017155',
            '2026-10-18 06:00:00'
        );
        $secondMachineId = $this->createVendingMachine(
            null,
            '33.5890001',
            '130.3950009',
            '2026-10-18 07:15:30'
        );
        $firstProductId = $this->createProduct('開発用商品A');
        $secondProductId = $this->createProduct('開発用商品B');

        $this->attachProduct($firstMachineId, $firstProductId, 'hot');
        $this->attachProduct($firstMachineId, $firstProductId, 'cold');
        $this->attachProduct($firstMachineId, $secondProductId, null);

        $response = $this->getJson('/api/vending-machines')->assertOk();
        $machines = $response->json();

        $this->assertIsArray($machines);
        $this->assertCount(2, $machines);
        $this->assertSame([$firstMachineId, $secondMachineId], array_column($machines, 'id'));
        $this->assertSame(
            [
                'id',
                'latitude',
                'longitude',
                'maker',
                'product_count',
                'last_confirmed_at',
            ],
            array_keys($machines[0])
        );

        $this->assertIsInt($machines[0]['id']);
        $this->assertIsFloat($machines[0]['latitude']);
        $this->assertIsFloat($machines[0]['longitude']);
        $this->assertIsInt($machines[0]['product_count']);
        $this->assertSame(33.5903547, $machines[0]['latitude']);
        $this->assertSame(130.4017155, $machines[0]['longitude']);
        $this->assertSame('開発用メーカー', $machines[0]['maker']);
        $this->assertSame(2, $machines[0]['product_count']);
        $this->assertSame('2026-10-18T06:00:00.000000Z', $machines[0]['last_confirmed_at']);

        $this->assertNull($machines[1]['maker']);
        $this->assertSame(0, $machines[1]['product_count']);
        $this->assertSame('2026-10-18T07:15:30.000000Z', $machines[1]['last_confirmed_at']);
    }

    public function test_product_count_is_aggregated_without_n_plus_one_queries(): void
    {
        $this->actingAs(User::factory()->create());

        $machineId = $this->createVendingMachine(
            '開発用メーカー',
            '33.5903547',
            '130.4017155',
            '2026-10-18 06:00:00'
        );
        $productId = $this->createProduct('開発用商品');
        $this->attachProduct($machineId, $productId, 'hot');
        $this->attachProduct($machineId, $productId, 'cold');

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $response = $this->getJson('/api/vending-machines')->assertOk();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $selectQueries = array_values(array_filter(
            $queries,
            fn (array $query): bool => str_starts_with(
                strtolower(ltrim($query['query'])),
                'select'
            )
        ));

        $this->assertCount(1, $selectQueries);
        $this->assertStringContainsString(
            'count(distinct',
            strtolower($selectQueries[0]['query'])
        );
        $this->assertSame(1, $response->json('0.product_count'));
    }

    public function test_product_name_query_parameter_is_reserved_and_does_not_filter_results(): void
    {
        $this->actingAs(User::factory()->create());

        $machineId = $this->createVendingMachine(
            '開発用メーカー',
            '33.5903547',
            '130.4017155',
            '2026-10-18 06:00:00'
        );
        $productId = $this->createProduct('開発用商品');
        $this->attachProduct($machineId, $productId, null);

        $allMachines = $this->getJson('/api/vending-machines')
            ->assertOk()
            ->json();
        $reservedParameterResult = $this->getJson('/api/vending-machines?product_name=存在しない商品')
            ->assertOk()
            ->json();

        $this->assertSame($allMachines, $reservedParameterResult);
    }

    private function createVendingMachine(
        ?string $maker,
        string $latitude,
        string $longitude,
        string $lastConfirmedAt
    ): int {
        $now = now()->utc();

        return DB::table('vending_machines')->insertGetId([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'maker' => $maker,
            'photo_path' => 'vending-machines/development/sample.jpg',
            'user_id' => null,
            'last_confirmed_at' => $lastConfirmedAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createProduct(string $name): int
    {
        $now = now()->utc();

        return DB::table('products')->insertGetId([
            'name' => $name,
            'maker' => null,
            'volume_ml' => null,
            'category' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function attachProduct(int $machineId, int $productId, ?string $temperature): void
    {
        DB::table('machine_products')->insert([
            'vending_machine_id' => $machineId,
            'product_id' => $productId,
            'price' => 150,
            'temperature' => $temperature,
            'recorded_at' => '2026-10-18 06:00:00',
        ]);
    }
}
