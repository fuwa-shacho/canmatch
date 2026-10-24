<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class MachineProduct extends Pivot
{
    protected $table = 'machine_products';

    public $incrementing = true;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    public function pricePer100Ml(?int $volumeMl): ?float
    {
        if ($volumeMl === null || $volumeMl <= 0) {
            return null;
        }

        return round($this->price * 100 / $volumeMl, 1);
    }
}
