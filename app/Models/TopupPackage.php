<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TopupPackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'topup_game_id',
        'name',
        'diamond_amount',
        'price',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'diamond_amount' => 'integer',
        'price' => 'decimal:2',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(TopupGame::class, 'topup_game_id');
    }
}
