<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevelopmentDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DevelopmentDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_seeder_does_not_add_development_only_data(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, DB::table('users')->where('email', 'like', 'demo%@example.com')->count());
        $this->assertSame(0, DB::table('vending_machines')->where('photo_path', 'like', 'vending-machines/development/demo-%')->count());
        $this->assertSame(0, DB::table('machine_products')->count());
        $this->assertSame(0, DB::table('point_logs')->count());
    }

    public function test_it_seeds_data_for_map_detail_search_and_ranking_development(): void
    {
        $this->seed(DevelopmentDemoSeeder::class);

        $developmentMachines = DB::table('vending_machines')
            ->where('photo_path', 'like', 'vending-machines/development/demo-%')
            ->get();

        $this->assertCount(5, $developmentMachines);
        $this->assertSame(3, DB::table('users')->where('email', 'like', 'demo%@example.com')->count());
        $this->assertSame(7, DB::table('point_logs')->count());

        foreach ($developmentMachines as $machine) {
            $this->assertGreaterThanOrEqual(
                3,
                DB::table('machine_products')->where('vending_machine_id', $machine->id)->count()
            );
        }

        $this->assertSame(
            8,
            DB::table('machine_products')
                ->whereIn('vending_machine_id', $developmentMachines->pluck('id'))
                ->distinct('product_id')
                ->count('product_id')
        );
    }

    public function test_it_can_be_run_again_without_duplicating_development_data(): void
    {
        $this->seed(DevelopmentDemoSeeder::class);
        $this->seed(DevelopmentDemoSeeder::class);

        $developmentMachineIds = DB::table('vending_machines')
            ->where('photo_path', 'like', 'vending-machines/development/demo-%')
            ->pluck('id');

        $this->assertCount(5, $developmentMachineIds);
        $this->assertSame(3, DB::table('users')->where('email', 'like', 'demo%@example.com')->count());
        $this->assertSame(
            18,
            DB::table('machine_products')->whereIn('vending_machine_id', $developmentMachineIds)->count()
        );
        $this->assertSame(
            7,
            DB::table('point_logs')->whereIn('vending_machine_id', $developmentMachineIds)->count()
        );
    }
}
