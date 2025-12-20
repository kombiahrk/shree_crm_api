<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Traits\BelongsToOrganization;

class Estimate extends Model
{
    use HasFactory, BelongsToOrganization;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'customer_id',
        'estimate_date',
        'expiry_date',
        'subtotal',
        'cgst_amount',
        'sgst_amount',
        'igst_amount',
        'total_amount',
        'status',
        'organization_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estimate_date' => 'date',
            'expiry_date' => 'date',
            'subtotal' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    /**
     * Get the customer that owns the estimate.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the organization that owns the estimate.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the estimate items for the estimate.
     */
    public function items(): HasMany
    {
        return $this->hasMany(EstimateItem::class);
    }
}
