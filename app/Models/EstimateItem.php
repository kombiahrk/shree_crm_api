<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateItem extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'estimate_id',
        'product_id',
        'item_name',
        'unit_price',
        'quantity',
        'item_total',
        'gst_rate',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'item_total' => 'decimal:2',
            'gst_rate' => 'decimal:2',
        ];
    }

    /**
     * Get the estimate that owns the estimate item.
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /**
     * Get the product associated with the estimate item.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
