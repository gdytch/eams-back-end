<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request as RequestFacade;

#[Fillable(['organization_id', 'user_id', 'action', 'auditable_type', 'auditable_id', 'changes', 'ip_address', 'user_agent'])]
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Record an audit entry for the current actor/request. Immutable: only ever inserted, never updated.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function record(string $action, ?Model $auditable = null, array $changes = []): self
    {
        $user = Auth::user();

        $organizationId = match (true) {
            $auditable instanceof Organization => $auditable->id,
            $auditable !== null && isset($auditable->organization_id) => $auditable->organization_id,
            default => $user?->organization_id,
        };

        return static::create([
            'organization_id' => $organizationId,
            'user_id' => $user?->id,
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'changes' => $changes,
            'ip_address' => RequestFacade::ip(),
            'user_agent' => RequestFacade::userAgent(),
        ]);
    }
}
