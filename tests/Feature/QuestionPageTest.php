<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VendingMachine;
use App\Services\Rag\QuestionAnswerer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Mockery;
use Tests\TestCase;

class QuestionPageTest extends TestCase
{
    use RefreshDatabase;

    private function machine(string $maker = '開発用メーカー'): VendingMachine
    {
        return VendingMachine::create([
            'latitude' => '33.5890000',
            'longitude' => '130.3950000',
            'maker' => $maker,
            'photo_path' => 'vending-machines/development/question.jpg',
            'last_confirmed_at' => '2026-10-20 00:00:00',
        ]);
    }

    private function row(int $machineId, int $price = 120, int $daysAgo = 2): array
    {
        return [
            'machine_id' => $machineId,
            'product_id' => 1,
            'product_name' => '開発用無糖炭酸',
            'volume_ml' => 500,
            'description' => '無糖の炭酸水',
            'price' => $price,
            'temperature' => 'cold',
            'recorded_at' => '2026-10-20',
            'days_ago' => $daysAgo,
        ];
    }

    /**
     * QuestionAnswerer の代わりに $result を返す(Cloudflare は呼ばない)
     */
    private function fakeAnswerer(array $result, ?string $expectedQuestion = null): void
    {
        $result += [
            'notice' => null,
            'rows' => [],
            'product_ids' => [],
            'evidence' => [],
            'citations' => [],
            'top_score' => null,
            'model' => null,
            'prompt_version' => null,
            'error_reason' => null,
        ];

        $mock = Mockery::mock(QuestionAnswerer::class);
        $expectation = $mock->shouldReceive('answer')->once();
        if ($expectedQuestion !== null) {
            $expectation->with($expectedQuestion);
        }
        $expectation->andReturn($result);

        $this->app->instance(QuestionAnswerer::class, $mock);
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('ask'))->assertRedirect('/login');
    }

    public function test_page_shows_the_form_examples_and_navigation(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('ask'))
            ->assertOk()
            ->assertSee('質問して探す')
            ->assertSee('甘くない炭酸を安く買える場所は?')
            ->assertSee('href="'.route('ask').'"', false);
    }

    public function test_answer_evidence_links_and_notice_are_displayed(): void
    {
        $this->actingAs(User::factory()->create());
        $machine = $this->machine();

        $this->fakeAnswerer([
            'status' => 'answered',
            'answer' => "条件に合う商品は次のとおりです。\n・開発用無糖炭酸:自販機{$machine->id}で120円(2日前の情報)",
            'rows' => [$this->row($machine->id)],
        ], '甘くない炭酸');

        Volt::test('questions.ask')
            ->set('question', '  甘くない炭酸  ')
            ->call('ask')
            ->assertHasNoErrors()
            ->assertSet('asked', '甘くない炭酸')
            ->assertSeeHtml('<p>条件に合う商品は次のとおりです。</p>')
            ->assertSeeHtml('<p>・開発用無糖炭酸:自販機'.$machine->id.'で120円(2日前の情報)</p>')
            ->assertSee('根拠')
            ->assertSee('120円')
            ->assertSee('2日前の情報')
            ->assertSee('冷たい')
            ->assertSee('/vending-machines/'.$machine->id, false)
            ->assertSee('開発用メーカー')
            ->assertSee('evidence-map-', false)
            ->assertSee('AI が選んだ内容です。価格は変わっている場合があります。');
    }

    public function test_example_button_asks_the_example(): void
    {
        $this->actingAs(User::factory()->create());

        $this->fakeAnswerer([
            'status' => 'no_evidence',
            'answer' => QuestionAnswerer::NO_EVIDENCE,
        ], '冷たいお茶はある?');

        Volt::test('questions.ask')
            ->assertSeeHtml('wire:click="askExample(1)"')
            ->call('askExample', 1)
            ->assertSet('question', '冷たいお茶はある?')
            ->assertSee(QuestionAnswerer::NO_EVIDENCE)
            ->assertDontSee('AI が選んだ内容です');
    }

    public function test_fallback_shows_the_notice_and_all_evidence(): void
    {
        $this->actingAs(User::factory()->create());
        $first = $this->machine();
        $second = $this->machine();

        $this->fakeAnswerer([
            'status' => 'fallback',
            'answer' => QuestionAnswerer::FALLBACK_NOTICE,
            'notice' => QuestionAnswerer::FALLBACK_NOTICE,
            'rows' => [$this->row($first->id, 110), $this->row($second->id, 150, 0)],
        ]);

        Volt::test('questions.ask')
            ->set('question', '甘くない炭酸')
            ->call('ask')
            ->assertSee(QuestionAnswerer::FALLBACK_NOTICE)
            ->assertSee('110円')
            ->assertSee('150円')
            ->assertSee('今日の情報');
    }

    public function test_unavailable_shows_only_the_message(): void
    {
        $this->actingAs(User::factory()->create());

        $this->fakeAnswerer([
            'status' => 'unavailable',
            'answer' => QuestionAnswerer::UNAVAILABLE,
        ]);

        Volt::test('questions.ask')
            ->set('question', '甘くない炭酸')
            ->call('ask')
            ->assertSee(QuestionAnswerer::UNAVAILABLE)
            ->assertDontSee('evidence-map-', false);
    }

    public function test_unknown_example_number_does_nothing(): void
    {
        $this->actingAs(User::factory()->create());

        $mock = Mockery::mock(QuestionAnswerer::class);
        $mock->shouldNotReceive('answer');
        $this->app->instance(QuestionAnswerer::class, $mock);

        Volt::test('questions.ask')
            ->call('askExample', 99)
            ->assertSet('question', '')
            ->assertSet('result', null);
    }

    public function test_empty_or_too_long_questions_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        $mock = Mockery::mock(QuestionAnswerer::class);
        $mock->shouldNotReceive('answer');
        $this->app->instance(QuestionAnswerer::class, $mock);

        Volt::test('questions.ask')
            ->set('question', '   ')
            ->call('ask')
            ->assertHasErrors(['question' => 'required']);

        Volt::test('questions.ask')
            ->set('question', str_repeat('あ', 201))
            ->call('ask')
            ->assertHasErrors(['question' => 'max']);
    }
}
