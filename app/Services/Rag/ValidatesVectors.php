<?php

namespace App\Services\Rag;

trait ValidatesVectors
{
    /**
     * 空でない、数値だけの並び(ベクトル)かどうか
     */
    private function isNumericVector(mixed $vector): bool
    {
        if (! is_array($vector) || $vector === [] || ! array_is_list($vector)) {
            return false;
        }

        foreach ($vector as $value) {
            if (! is_int($value) && ! is_float($value)) {
                return false;
            }
        }

        return true;
    }
}