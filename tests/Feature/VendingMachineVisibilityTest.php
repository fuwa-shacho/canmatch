<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\VendingMachine;
use App\Services\Rag\EvidenceRetriever;
use App\Services\RareProductDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VendingMachineVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_report_removed_machine(): void
    {
        $machine = $this->machine();

        $this->post(route('vending-machines.removal-reports.store', $machine))
            ->assertRedirect(route('login'));

        $this->assertNull($machine->fresh()->hidden_at);
        $this->assertDatabaseCount('vending_machine_removal_reports', 0);
    }

    public function test_authenticated_user_report_hides_machine_without_deleting_data(): void
    {
        $reporter = User::factory()->create();
        $machine = $this->machine();
        $product = $this->product();
        $this->sell($machine, $product);

        $this->actingAs($reporter)
            ->post(route('vending-machines.removal-reports.store', $machine))
            ->assertRedirect(route('vending-machines.show', $machine))
            ->assertSessionHas('status');

        $this->assertNotNull($machine->fresh()->hidden_at);
        $this->assertDatabaseHas('vending_machine_removal_reports', [
            'vending_machine_id' => $machine->id,
            'user_id' => $reporter->id,
        ]);
        $this->assertDatabaseHas('vending_machines', ['id' => $machine->id]);
        $this->assertDatabaseHas('machine_products', [
            'vending_machine_id' => $machine->id,
            'product_id' => $product->id,
        ]);

        $this->get(route('vending-machines.show', $machine))
            ->assertOk()
            ->assertSee('撤去済みとして非表示です')
            ->assertDontSee('なくなっていたと報告する');
    }

    public function test_repeated_report_by_same_user_is_recorded_once_and_keeps_first_hidden_time(): void
    {
        $reporter = User::factory()->create();
        $machine = $this->machine();

        $this->travelTo('2026-10-25 01:00:00');
        $this->actingAs($reporter)->post(route('vending-machines.removal-reports.store', $machine));
        $hiddenAt = $machine->fresh()->hidden_at;

        $this->travel(1)->day();
        $this->post(route('vending-machines.removal-reports.store', $machine));

        $this->assertTrue($machine->fresh()->hidden_at->equalTo($hiddenAt));
        $this->assertDatabaseCount('vending_machine_removal_reports', 1);
    }

    public function test_hidden_machine_is_excluded_from_map_search_rare_detection_and_rag_evidence(): void
    {
        config()->set('services.cloudflare.account_id', 'acc');
        config()->set('services.cloudflare.api_token', 'token');
        config()->set('services.cloudflare.embedding_model', '@cf/baai/bge-m3');
        config()->set('services.cloudflare.vectorize_index', 'canmatch-products');
        config()->set('services.cloudflare.rag_score_threshold', 0.45);
        config()->set('services.cloudflare.rag_top_k', 10);
        config()->set('services.cloudflare.rag_max_evidence', 20);

        $product = $this->product();
        $visible = $this->machine();
        $hidden = $this->machine(['hidden_at' => now()]);
        $this->sell($visible, $product, 130);
        $this->sell($hidden, $product, 100);

        $this->actingAs(User::factory()->create());

        $machines = $this->getJson('/api/vending-machines?product_name='.urlencode($product->name))
            ->assertOk()
            ->json();

        $this->assertSame([$visible->id], array_column($machines, 'id'));
        $this->assertSame(1, app(RareProductDetector::class)->machineCount($product->id));

        $this->get(route('rare-products.index'))
            ->assertOk()
            ->assertSee('自販機 #'.$visible->id)
            ->assertDontSee('自販機 #'.$hidden->id);

        Http::fake(function (Request $request) use ($product) {
            if (str_contains($request->url(), '/ai/run/')) {
                return Http::response(['result' => ['data' => [[0.1, 0.2]]]]);
            }

            return Http::response(['result' => ['matches' => [
                ['id' => (string) $product->id, 'score' => 0.8],
            ]]]);
        });

        $evidence = app(EvidenceRetriever::class)->retrieve('開発用商品');

        $this->assertSame([$visible->id], array_column($evidence['evidence'], 'machine_id'));
    }

    public function test_hidden_machine_command_lists_report_count(): void
    {
        $machine = $this->machine(['maker' => '開発用非表示メーカー', 'hidden_at' => '2026-10-25 01:00:00']);
        $machine->removalReports()->create(['user_id' => User::factory()->create()->id]);

        $this->artisan('machines:hidden')
            ->expectsOutputToContain('開発用非表示メーカー')
            ->assertSuccessful();
    }

    private function machine(array $attributes = []): VendingMachine
    {
        return VendingMachine::query()->create(array_merge([
            'latitude' => '33.5890000',
            'longitude' => '130.3950000',
            'maker' => '開発用メーカー',
            'photo_path' => 'vending-machines/development/visibility.jpg',
            'last_confirmed_at' => '2026-10-25 00:00:00',
        ], $attributes));
    }

    private function product(): Product
    {
        return Product::query()->create([
            'name' => '開発用商品',
            'volume_ml' => 500,
        ]);
    }

    private function sell(VendingMachine $machine, Product $product, int $price = 120): void
    {
        $machine->products()->attach($product->id, [
            'price' => $price,
            'temperature' => 'cold',
            'recorded_at' => '2026-10-25 00:00:00',
        ]);
    }
}
