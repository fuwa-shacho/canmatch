<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;

class ProductMatcher
{
    private const CANDIDATE_LIMIT = 5;

    private const MIN_PARTIAL_LENGTH = 2;

    /**
     * 完全一致が1件ならそれを返す(自動で紐づけ)。
     * 完全一致が複数、または部分一致のみの場合は候補として返すだけ。
     *
     * @return array{product: ?Product, candidates: Collection<int, Product>}
     */
    public function match(string $name): array
    {
        $normalized = $this->normalize($name);

        if ($normalized === '') {
            return $this->none();
        }

        $exact = Product::query()
            ->where('name', $normalized)
            ->orderBy('id')
            ->limit(self::CANDIDATE_LIMIT)
            ->get();

        if ($exact->count() === 1) {
            return ['product' => $exact->first(), 'candidates' => collect()];
        }

        // 同名で容量違いなどが複数ある場合は、自動で決めず選ばせる
        if ($exact->count() > 1) {
            return ['product' => null, 'candidates' => $exact];
        }

        if (mb_strlen($normalized) < self::MIN_PARTIAL_LENGTH) {
            return $this->none();
        }

        $partial = Product::query()
            ->whereRaw("name LIKE ? ESCAPE '!'", ['%'.$this->escapeLike($normalized).'%'])
            ->orderBy('name')
            ->limit(self::CANDIDATE_LIMIT)
            ->get();

        return ['product' => null, 'candidates' => $partial];
    }

    /** 全角英数・全角スペースを半角に、半角カナを全角に揃え、空白を整える */
    public function normalize(string $name): string
    {
        $name = mb_convert_kana($name, 'asKV');

        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private function none(): array
    {
        return ['product' => null, 'candidates' => collect()];
    }
}