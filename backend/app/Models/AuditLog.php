<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsesFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[UsesFactory(AuditLogFactory::class)]
#[Fillable(['user_id', 'action', 'auditable_type', 'auditable_id', 'metadata'])]
class AuditLog extends Model
{
    use HasFactory;

    public static function record(string $action, Model $auditable, array $metadata = []): self
    {
        return static::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => $auditable->getKey(),
            'metadata' => $metadata,
        ]);
    }

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
