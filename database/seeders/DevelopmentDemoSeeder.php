<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use App\Models\VendingMachine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DevelopmentDemoSeeder extends Seeder
{
    /**
     * Seed development-only data for the map, detail, search, and ranking screens.
     * Coordinates are centered around G's Fukuoka, but every vending machine is fictitious.
     */
    public function run(): void
    {
        $this->call(ProductSeeder::class);

        DB::transaction(function (): void {
            $now = CarbonImmutable::now('UTC')->startOfSecond();
            $users = $this->seedUsers();
            $products = $this->requiredProducts();
            $machines = $this->seedMachines($users, $now);

            $this->seedMachineProducts($machines, $products, $now);
            $this->seedPointLogs($machines, $users, $now);
        });
    }

    /**
     * @return array<string, User>
     */
    private function seedUsers(): array
    {
        $users = [];

        foreach ([
            ['key' => 'taro', 'name' => 'デモ太郎', 'email' => 'demo1@example.com'],
            ['key' => 'hanako', 'name' => 'デモ花子', 'email' => 'demo2@example.com'],
            ['key' => 'jiro', 'name' => 'デモ次郎', 'email' => 'demo3@example.com'],
        ] as $definition) {
            $users[$definition['key']] = User::query()->updateOrCreate(
                ['email' => $definition['email']],
                [
                    'name' => $definition['name'],
                    'email_verified_at' => now(),
                    'password' => Hash::make('password'),
                ]
            );
        }

        return $users;
    }

    /**
     * @return array<string, Product>
     */
    private function requiredProducts(): array
    {
        $products = [];

        foreach ([
            ['key' => 'coke', 'name' => 'コカ・コーラ', 'maker' => '日本コカ・コーラ', 'volume_ml' => 500],
            ['key' => 'coke_zero', 'name' => 'コカ・コーラ ゼロ', 'maker' => '日本コカ・コーラ', 'volume_ml' => 500],
            ['key' => 'ayataka', 'name' => '綾鷹', 'maker' => '日本コカ・コーラ', 'volume_ml' => 650],
            ['key' => 'aquarius', 'name' => 'アクエリアス', 'maker' => '日本コカ・コーラ', 'volume_ml' => 500],
            ['key' => 'mitsuya', 'name' => '三ツ矢サイダー', 'maker' => 'アサヒ飲料', 'volume_ml' => 500],
            ['key' => 'wilkinson', 'name' => 'ウィルキンソン タンサン', 'maker' => 'アサヒ飲料', 'volume_ml' => 500],
            ['key' => 'namacha', 'name' => 'キリン 生茶', 'maker' => 'キリンビバレッジ', 'volume_ml' => 525],
            ['key' => 'mugicha', 'name' => '健康ミネラルむぎ茶', 'maker' => '伊藤園', 'volume_ml' => 600],
        ] as $definition) {
            $product = Product::query()
                ->where('name', $definition['name'])
                ->where('maker', $definition['maker'])
                ->where('volume_ml', $definition['volume_ml'])
                ->first();

            if ($product === null) {
                throw new RuntimeException("開発用データに必要な商品がありません: {$definition['name']}");
            }

            $products[$definition['key']] = $product;
        }

        return $products;
    }

    /**
     * @param  array<string, User>  $users
     * @return array<string, VendingMachine>
     */
    private function seedMachines(array $users, CarbonImmutable $now): array
    {
        $machines = [];

        foreach ([
            [
                'key' => 'north_gate',
                'latitude' => '33.5903547',
                'longitude' => '130.3957155',
                'maker' => '開発用・北門',
                'photo_path' => 'vending-machines/development/demo-01.jpg',
                'user' => 'taro',
                'confirmed_days_ago' => 0,
            ],
            [
                'key' => 'library',
                'latitude' => '33.5898421',
                'longitude' => '130.3962814',
                'maker' => '開発用・図書館前',
                'photo_path' => 'vending-machines/development/demo-02.jpg',
                'user' => 'hanako',
                'confirmed_days_ago' => 2,
            ],
            [
                'key' => 'cafeteria',
                'latitude' => '33.5892076',
                'longitude' => '130.3946628',
                'maker' => '開発用・食堂横',
                'photo_path' => 'vending-machines/development/demo-03.jpg',
                'user' => 'jiro',
                'confirmed_days_ago' => 7,
            ],
            [
                'key' => 'gym',
                'latitude' => '33.5885312',
                'longitude' => '130.3954873',
                'maker' => '開発用・体育館前',
                'photo_path' => 'vending-machines/development/demo-04.jpg',
                'user' => 'taro',
                'confirmed_days_ago' => 30,
            ],
            [
                'key' => 'east_gate',
                'latitude' => '33.5891164',
                'longitude' => '130.3971032',
                'maker' => null,
                'photo_path' => 'vending-machines/development/demo-05.jpg',
                'user' => 'hanako',
                'confirmed_days_ago' => 45,
            ],
        ] as $definition) {
            $machines[$definition['key']] = VendingMachine::query()->updateOrCreate(
                ['photo_path' => $definition['photo_path']],
                [
                    'latitude' => $definition['latitude'],
                    'longitude' => $definition['longitude'],
                    'maker' => $definition['maker'],
                    'user_id' => $users[$definition['user']]->id,
                    'last_confirmed_at' => $now->subDays($definition['confirmed_days_ago']),
                ]
            );
        }

        return $machines;
    }

    /**
     * @param  array<string, VendingMachine>  $machines
     * @param  array<string, Product>  $products
     */
    private function seedMachineProducts(array $machines, array $products, CarbonImmutable $now): void
    {
        $offerings = [
            'north_gate' => [
                ['coke', 160, 'cold'],
                ['ayataka', 150, 'cold'],
                ['wilkinson', 120, 'cold'],
                ['mitsuya', 150, 'cold'],
            ],
            'library' => [
                ['coke', 150, 'cold'],
                ['coke_zero', 150, 'cold'],
                ['ayataka', 140, 'cold'],
                ['namacha', 150, 'cold'],
            ],
            'cafeteria' => [
                ['aquarius', 160, 'cold'],
                ['mitsuya', 140, 'cold'],
                ['mugicha', 130, 'cold'],
            ],
            'gym' => [
                ['aquarius', 150, 'cold'],
                ['wilkinson', 130, 'cold'],
                ['namacha', 140, 'hot'],
                ['namacha', 150, 'cold'],
            ],
            'east_gate' => [
                ['coke', 170, 'cold'],
                ['ayataka', 160, 'hot'],
                ['mugicha', 140, null],
            ],
        ];

        DB::table('machine_products')
            ->whereIn('vending_machine_id', collect($machines)->pluck('id'))
            ->delete();

        foreach ($offerings as $machineKey => $rows) {
            foreach ($rows as [$productKey, $price, $temperature]) {
                DB::table('machine_products')->insert([
                    'vending_machine_id' => $machines[$machineKey]->id,
                    'product_id' => $products[$productKey]->id,
                    'price' => $price,
                    'temperature' => $temperature,
                    'recorded_at' => $machines[$machineKey]->last_confirmed_at ?? $now,
                ]);
            }
        }
    }

    /**
     * @param  array<string, VendingMachine>  $machines
     * @param  array<string, User>  $users
     */
    private function seedPointLogs(array $machines, array $users, CarbonImmutable $now): void
    {
        DB::table('point_logs')
            ->whereIn('vending_machine_id', collect($machines)->pluck('id'))
            ->delete();

        foreach ([
            ['taro', 'registration', 10, 'north_gate', 0],
            ['taro', 'update', 5, 'gym', 2],
            ['taro', 'rare_bonus', 20, 'north_gate', 3],
            ['hanako', 'registration', 10, 'library', 1],
            ['hanako', 'registration', 10, 'east_gate', 10],
            ['jiro', 'registration', 10, 'cafeteria', 8],
            ['jiro', 'update', 5, 'cafeteria', 40],
        ] as [$userKey, $type, $points, $machineKey, $daysAgo]) {
            DB::table('point_logs')->insert([
                'user_id' => $users[$userKey]->id,
                'type' => $type,
                'points' => $points,
                'vending_machine_id' => $machines[$machineKey]->id,
                'created_at' => $now->subDays($daysAgo),
            ]);
        }
    }
}
