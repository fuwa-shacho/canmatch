<?php

namespace Tests\Unit;

use App\Services\Evals\ReaderScorer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReaderScorerTest extends TestCase
{
    private ReaderScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new ReaderScorer;
    }

    /** 開発用の正解(answers.json の products と同じ形) */
    private function expected(): array
    {
        return [
            ['name' => 'コカ・コーラ', 'price' => 190, 'temperature' => 'cold', 'count' => 2, 'aliases' => ['Coca-Cola']],
            ['name' => 'コカ・コーラ', 'price' => 100, 'temperature' => 'cold'],
            ['name' => 'クラフトボス ブラック', 'price' => 180, 'temperature' => 'cold', 'aliases' => ['CRAFT BOSS BLACK']],
            ['name' => 'チップスター', 'price' => 170, 'temperature' => null],
        ];
    }

    private function p(?string $name, ?int $price, ?string $temperature = 'cold'): array
    {
        return ['name' => $name, 'price' => $price, 'temperature' => $temperature];
    }

    public function test_全部正しく読めたら100パーセントになる(): void
    {
        $r = $this->scorer->score($this->expected(), [
            $this->p('コカ・コーラ', 190),
            $this->p('コカ・コーラ', 100),
            $this->p('クラフトボス ブラック', 180),
            $this->p('チップスター', 170, null),
        ]);

        $this->assertSame(4, $r['strict']['rows']);
        $this->assertSame(4, $r['strict']['name']);
        $this->assertSame(4, $r['strict']['price']);
        $this->assertSame(3, $r['strict']['temperature_rows'], '温冷が null の正解は温冷の分母に入れない');
        $this->assertSame(3, $r['strict']['temperature']);
        $this->assertSame(4, $r['strict']['all']);
        $this->assertSame(0, $r['strict']['extra']);
    }

    public function test_商品名と価格を別々に数える(): void
    {
        // コカ・コーラは1つだけ(190円)。100円の行は商品名は合うが価格は外れ
        $r = $this->scorer->score($this->expected(), [
            $this->p('コカ・コーラ', 190),
            $this->p('クラフトボス ブラック', 160),
        ]);

        $this->assertSame(3, $r['strict']['name']);
        $this->assertSame(1, $r['strict']['price']);
        $this->assertSame(1, $r['strict']['all']);
    }

    public function test_温冷が違えば価格は合ってもすべて一致にならない(): void
    {
        $r = $this->scorer->score([['name' => '綾鷹', 'price' => 160, 'temperature' => 'cold']], [
            $this->p('綾鷹', 160, 'hot'),
        ]);

        $this->assertSame(1, $r['strict']['price']);
        $this->assertSame(0, $r['strict']['temperature']);
        $this->assertSame(0, $r['strict']['all']);
    }

    public function test_別名と英語表記でも商品名が合う(): void
    {
        $r = $this->scorer->score($this->expected(), [
            $this->p('Coca-Cola', 190),
            $this->p('CRAFT BOSS BLACK', 180),
        ]);

        // Coca-Cola は 190円の行にだけ書いてあるが、同じ商品名の 100円の行でも使う
        $this->assertSame(3, $r['strict']['name']);
        $this->assertSame(2, $r['strict']['price']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function sameNames(): array
    {
        return [
            '全角英字' => ['ＣＲＡＦＴ ＢＯＳＳ ＢＬＡＣＫ', 'クラフトボス ブラック'],
            '大文字小文字' => ['craft boss black', 'クラフトボス ブラック'],
            '空白なし' => ['クラフトボスブラック', 'クラフトボス ブラック'],
            '中黒の有無' => ['コカコーラ', 'コカ・コーラ'],
            '半角カナ' => ['ｺｶ･ｺｰﾗ', 'コカ・コーラ'],
        ];
    }

    #[DataProvider('sameNames')]
    public function test_表記ゆれは厳しめでも同じ商品とみなす(string $read, string $answerName): void
    {
        $row = array_values(array_filter($this->expected(), fn ($r) => $r['name'] === $answerName))[0];

        $this->assertTrue($this->scorer->nameMatches($read, $row, 'strict'));
    }

    public function test_一部だけ合う商品名はゆるめでだけ合う(): void
    {
        $row = ['name' => 'ダイドーブレンド 絶品微糖', 'price' => 110, 'temperature' => 'cold'];

        $this->assertFalse($this->scorer->nameMatches('絶品微糖', $row, 'strict'));
        $this->assertTrue($this->scorer->nameMatches('絶品微糖', $row, 'loose'));
    }

    public function test_1文字だけの一致はゆるめでも合わない(): void
    {
        $row = ['name' => '綾鷹', 'price' => 160, 'temperature' => 'cold'];

        $this->assertFalse($this->scorer->nameMatches('鷹', $row, 'loose'));
    }

    public function test_正解にない読み取りと価格を空にした数を数える(): void
    {
        $r = $this->scorer->score($this->expected(), [
            $this->p('コカ・コーラ', 190),
            $this->p('ペプシ', 150),
            $this->p('チップスター', null, null),
            $this->p(null, 120),
        ]);

        $this->assertSame(4, $r['predicted']);
        $this->assertSame(1, $r['null_price']);
        $this->assertSame(1, $r['null_temperature'], 'チップスターの行だけ');
        $this->assertSame(2, $r['strict']['extra'], 'ペプシと名前なしの行');
        $this->assertContains('ペプシ(150円・cold)', $r['strict']['extra_rows']);
    }

    public function test_同じ読み取りが重複しても1つとして数える(): void
    {
        $r = $this->scorer->score($this->expected(), [
            $this->p('ペプシ', 150),
            $this->p('ペプシ', 150),
        ]);

        $this->assertSame(1, $r['predicted']);
        $this->assertSame(1, $r['strict']['extra']);
    }

    public function test_外れた行は理由が分かる形で残す(): void
    {
        $r = $this->scorer->score($this->expected(), [
            $this->p('コカ・コーラ', 160),
        ]);

        $this->assertContains('チップスター(170円・温冷なし)', $r['strict']['missed_rows']);
        $this->assertContains('コカ・コーラ(190円・cold) → 読み取り: コカ・コーラ(160円・cold)', $r['strict']['missed_rows']);
    }

    public function test_何も読めなければ0になる(): void
    {
        $r = $this->scorer->score($this->expected(), []);

        $this->assertSame(0, $r['strict']['name']);
        $this->assertSame(0, $r['loose']['name']);
        $this->assertSame(0, $r['predicted']);
        $this->assertCount(4, $r['strict']['missed_rows']);
    }
}
