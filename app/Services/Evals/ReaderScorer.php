<?php

namespace App\Services\Evals;

use Normalizer;

/**
 * AI の読み取り結果を、evals/answers.json の正解と比べて採点する(Issue 07)
 *
 * 正解の1行(商品名・価格・温冷)ごとに、次を数える。
 * - 商品名:読み取り結果に、同じ商品名の行がある
 * - 価格  :同じ商品名の行のどれかが、正解と同じ価格
 * - 温冷  :同じ商品名の行のどれかが、正解と同じ温冷(正解の温冷が null の行は数えない)
 * - すべて:同じ商品名の行のどれかが、価格と温冷の両方で正解と一致
 *
 * 商品名の比べ方は2通り。
 * - 厳しめ(strict):表記をそろえたうえで、name か aliases のどれかと完全に一致
 * - ゆるめ(loose) :厳しめに加えて、どちらかがもう一方を含む(2文字以上)
 */
class ReaderScorer
{
    public const MODES = ['strict', 'loose'];

    /**
     * @param  array<int, array{name: string, price: int, temperature: ?string, aliases?: string[]}>  $expected  正解の商品(answers.json の products)
     * @param  array<int, array{name: ?string, price: ?int, temperature: ?string}>  $predicted  読み取り結果(VendingMachineReader の result.products)
     * @return array{strict: array<string, mixed>, loose: array<string, mixed>, predicted: int, null_price: int, null_temperature: int}
     */
    public function score(array $expected, array $predicted): array
    {
        $predicted = $this->uniquePredictions($predicted);
        $expected = $this->shareAliases($expected);

        $result = [
            'predicted' => count($predicted),
            'null_price' => count(array_filter($predicted, fn (array $p): bool => $p['price'] === null)),
            'null_temperature' => count(array_filter($predicted, fn (array $p): bool => $p['temperature'] === null)),
        ];

        foreach (self::MODES as $mode) {
            $result[$mode] = $this->scoreMode($expected, $predicted, $mode);
        }

        return $result;
    }

    /**
     * 表記をそろえる(全角・半角、大文字・小文字、空白、記号の違いをなくす)
     */
    public function normalize(?string $name): string
    {
        if ($name === null) {
            return '';
        }

        $name = Normalizer::normalize($name, Normalizer::FORM_KC) ?: $name;
        $name = mb_strtolower($name);

        // 空白、句読点、記号(・ - . & ! など)を取り除く。長音「ー」は文字なので残る
        return preg_replace('/[\s\p{P}\p{S}]+/u', '', $name) ?? $name;
    }

    /**
     * 読み取った商品名が、正解の行(name か aliases)と同じ商品か
     */
    public function nameMatches(?string $predictedName, array $expectedRow, string $mode): bool
    {
        $predicted = $this->normalize($predictedName);
        if ($predicted === '') {
            return false;
        }

        foreach ([$expectedRow['name'], ...($expectedRow['aliases'] ?? [])] as $candidate) {
            $answer = $this->normalize($candidate);
            if ($answer === '') {
                continue;
            }

            if ($predicted === $answer) {
                return true;
            }

            if ($mode === 'loose'
                && min(mb_strlen($predicted), mb_strlen($answer)) >= 2
                && (str_contains($predicted, $answer) || str_contains($answer, $predicted))) {
                return true;
            }
        }

        return false;
    }

    private function scoreMode(array $expected, array $predicted, string $mode): array
    {
        $counts = ['rows' => 0, 'name' => 0, 'price' => 0, 'temperature_rows' => 0, 'temperature' => 0, 'all' => 0];
        $missed = [];

        foreach ($expected as $row) {
            $counts['rows']++;
            $hasTemperature = $row['temperature'] !== null;
            if ($hasTemperature) {
                $counts['temperature_rows']++;
            }

            $sameName = array_values(array_filter(
                $predicted,
                fn (array $p): bool => $this->nameMatches($p['name'], $row, $mode),
            ));

            if ($sameName === []) {
                $missed[] = $this->label($row);

                continue;
            }

            $counts['name']++;

            $priceOk = $this->any($sameName, fn (array $p): bool => $p['price'] === $row['price']);
            $temperatureOk = $hasTemperature
                && $this->any($sameName, fn (array $p): bool => $p['temperature'] === $row['temperature']);
            $allOk = $this->any($sameName, fn (array $p): bool => $p['price'] === $row['price']
                && (! $hasTemperature || $p['temperature'] === $row['temperature']));

            $counts['price'] += $priceOk ? 1 : 0;
            $counts['temperature'] += $temperatureOk ? 1 : 0;
            $counts['all'] += $allOk ? 1 : 0;

            if (! $allOk) {
                $missed[] = $this->label($row).' → 読み取り: '.implode(' / ', array_map(fn (array $p): string => $this->label($p), $sameName));
            }
        }

        // 正解のどの行とも商品名が合わない読み取り(読み間違い・正解にない商品)
        $extra = array_values(array_map(
            fn (array $p): string => $this->label($p),
            array_filter($predicted, fn (array $p): bool => ! $this->any(
                $expected,
                fn (array $row): bool => $this->nameMatches($p['name'], $row, $mode),
            )),
        ));

        return [...$counts, 'extra' => count($extra), 'missed_rows' => $missed, 'extra_rows' => $extra];
    }

    /**
     * 同じ商品名の行どうしで aliases を共有する
     * (例:コカ・コーラ 190円の行にだけ "Coca-Cola" があっても、100円の行でも使えるようにする)
     */
    private function shareAliases(array $expected): array
    {
        $aliasesByName = [];
        foreach ($expected as $row) {
            $key = $this->normalize($row['name']);
            $aliasesByName[$key] = [...($aliasesByName[$key] ?? []), ...($row['aliases'] ?? [])];
        }

        return array_map(function (array $row) use ($aliasesByName): array {
            $row['aliases'] = array_values(array_unique($aliasesByName[$this->normalize($row['name'])]));

            return $row;
        }, $expected);
    }

    /**
     * 同じ商品名・価格・温冷の読み取りは1つにまとめる
     */
    private function uniquePredictions(array $predicted): array
    {
        $unique = [];
        foreach ($predicted as $p) {
            $row = [
                'name' => isset($p['name']) ? (string) $p['name'] : null,
                'price' => is_int($p['price'] ?? null) ? $p['price'] : null,
                'temperature' => in_array($p['temperature'] ?? null, ['hot', 'cold'], true) ? $p['temperature'] : null,
            ];
            $unique[$this->normalize($row['name']).'|'.$row['price'].'|'.$row['temperature']] = $row;
        }

        return array_values($unique);
    }

    private function any(array $items, callable $test): bool
    {
        foreach ($items as $item) {
            if ($test($item)) {
                return true;
            }
        }

        return false;
    }

    private function label(array $row): string
    {
        $price = $row['price'] === null ? '価格なし' : "{$row['price']}円";
        $temperature = $row['temperature'] ?? '温冷なし';

        return ($row['name'] ?? '名前なし')."({$price}・{$temperature})";
    }
}
