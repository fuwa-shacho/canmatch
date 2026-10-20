<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VendingMachinesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_vending_machine_list_returns_an_empty_array(): void
    {
        $this->getJson('/api/vending-machines')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_vending_machines_return_expected_types_precision_and_distinct_product_counts_in_id_order(): void
    {
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

    public function test_product_name_query_parameter_filters_machines_by_partial_product_name(): void
    {
        $matchingMachineId = $this->createVendingMachine(
            '検索対象メーカー',
            '33.5903547',
            '130.4017155',
            '2026-10-18 06:00:00'
        );
        $otherMachineId = $this->createVendingMachine(
            '検索対象外メーカー',
            '33.5890001',
            '130.3950009',
            '2026-10-18 07:15:30'
        );
        $matchingProductId = $this->createProduct('開発用ドクターペッパー');
        $otherProductId = $this->createProduct('開発用サイダー');
        $this->attachProduct($matchingMachineId, $matchingProductId, 'hot');
        $this->attachProduct($matchingMachineId, $matchingProductId, 'cold');
        $this->attachProduct($otherMachineId, $otherProductId, null);

        $machines = $this->getJson('/api/vending-machines?product_name='.urlencode('ドクターペッパー'))
            ->assertOk()
            ->json();

        $this->assertCount(1, $machines);
        $this->assertSame($matchingMachineId, $machines[0]['id']);
    }

    public function test_product_name_query_returns_an_empty_array_when_no_product_matches(): void
    {
        $machineId = $this->createVendingMachine(
            '開発用メーカー',
            '33.5903547',
            '130.4017155',
            '2026-10-18 06:00:00'
        );
        $productId = $this->createProduct('開発用サイダー');
        $this->attachProduct($machineId, $productId, null);

        $this->getJson('/api/vending-machines?product_name='.urlencode('ドクターペッパー'))
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_product_name_query_must_be_a_string_within_the_length_limit(): void
    {
        $this->getJson('/api/vending-machines?product_name[]=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_name');

        $this->getJson('/api/vending-machines?product_name='.str_repeat('a', 101))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_name');
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
