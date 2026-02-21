<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'wallet_id',
        'user_id',
        'risk_score',
        'risk_level',
        'risk_factors',
        'decision',
        'reviewed_by',
        'review_notes',
        'metadata',
    ];

    protected $casts = [
        'risk_score' => 'decimal:4',
        'risk_factors' => 'array',
        'metadata' => 'array',
    ];

    const DECISION_APPROVED = 'approved';
    const DECISION_REJECTED = 'rejected';
    const DECISION_REVIEW = 'review';
    const DECISION_AUTO_APPROVED = 'auto_approved';

    const LEVEL_LOW = 'low';
    const LEVEL_MEDIUM = 'medium';
    const LEVEL_HIGH = 'high';
    const LEVEL_CRITICAL = 'critical';

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeLevel($query, string $level)
    {
        return $query->where('risk_level', $level);
    }

    public function scopeDecision($query, string $decision)
    {
        return $query->where('decision', $decision);
    }
}
