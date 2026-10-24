<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiReadLog extends Model
{
    protected $fillable = [
        'user_id', 'vending_machine_id', 'photo_path', 'model',
        'prompt_version', 'status', 'raw_response', 'predicted_products', 'gateway_log_id',
        'error_reason', 'correction_count',
    ];

    protected function casts(): array
    {
        return [
            'correction_count' => 'integer',
            'predicted_products' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vendingMachine(): BelongsTo
    {
        return $this->belongsTo(VendingMachine::class);
    }
}
