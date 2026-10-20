<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model
{
    protected $fillable = [
        'name',
        'maker',
        'volume_ml',
        'category',
    ];

    protected function casts(): array
    {
        return [
            'volume_ml' => 'integer',
        ];
    }

    public function vendingMachines(): BelongsToMany
    {
        return $this->belongsToMany(
            VendingMachine::class,
            'machine_products',
            'product_id',
            'vending_machine_id'
        )
            ->withPivot(['id', 'price', 'temperature', 'recorded_at'])
            ->using(MachineProduct::class);
    }
}
