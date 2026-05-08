<?php

namespace App\Models;

use App\Enums\StockUsageStatus;
use App\Enums\StockUsageType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockUsage extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'usage_date' => 'date',
        'usage_type' => StockUsageType::class,
        'status' => StockUsageStatus::class,
        'estimated_total_cost' => 'decimal:2',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockUsageItem::class);
    }
}
