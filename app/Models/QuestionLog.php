<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 質問の記録(Issue 68)
 *
 * ある回答が、どの商品とどの自販機のデータから作られたかを後から追えるようにする。
 * 質問を受け付けた時点(Cloudflare を呼ぶ前)に status=pending で作り、答えが出たら1回だけ結果を書き込む。
 * それ以降は書き換えない(updated_at は持たない)。
 */
class QuestionLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'question',
        'status',
        'product_ids',
        'evidence',
        'citations',
        'answer',
        'error_reason',
        'model',
        'prompt_version',
        'top_score',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'product_ids' => 'array',
            'evidence' => 'array',
            'citations' => 'array',
            'top_score' => 'float',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
