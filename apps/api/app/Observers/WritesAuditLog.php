<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * Records every mutation: who, what, when, before and after
 * (MASTER BUILD PROMPT section 18).
 *
 * Bound to models rather than to controllers so that Filament, the CSV
 * importer, Icecat ingestion and tinker are all covered without each
 * remembering to log. The same reasoning as InvalidatesRenderedRoutes.
 *
 * Only CHANGED attributes are stored. Recording whole rows makes the log
 * enormous and buries the one field that actually moved — which is the field
 * anyone reading an audit log is looking for.
 */
final class WritesAuditLog
{
    /**
     * Never written to the log.
     *
     * Secrets, obviously. Also the noisy ones: timestamps change on every
     * save, and search_vector is derived, so logging them would make every
     * entry look like a change even when nothing meaningful moved.
     *
     * @var list<string>
     */
    private const REDACTED = [
        'password',
        'remember_token',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
        'session_token_hash',
        'public_token_hash',
        'updated_at',
        'created_at',
        'search_vector',
    ];

    public function created(Model $model): void
    {
        $this->record($model, 'created', [], $this->filter($model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $changed = $this->filter($model->getChanges());

        if ($changed === []) {
            return;
        }

        $before = [];

        foreach (array_keys($changed) as $key) {
            $before[$key] = $model->getOriginal($key);
        }

        $this->record($model, 'updated', $before, $changed);
    }

    public function deleted(Model $model): void
    {
        $this->record($model, 'deleted', $this->filter($model->getAttributes()), []);
    }

    public function restored(Model $model): void
    {
        $this->record($model, 'restored', [], $this->filter($model->getAttributes()));
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function record(Model $model, string $event, array $before, array $after): void
    {
        $user = Auth::user();
        $traceId = Context::get('trace_id');

        /*
         * The actor id is dropped when the actor is the record being deleted.
         *
         * Deleting a user fires this observer, which would insert an audit row
         * referencing the user row that is disappearing in the same
         * transaction — a foreign key violation that aborts the deletion
         * itself. An audit system that prevents deletions is worse than one
         * that loses an id, and `user_label` preserves who it was regardless.
         */
        $actorId = $user?->getAuthIdentifier();

        if ($actorId !== null && $event === 'deleted' && $model instanceof User
            && (string) $model->getKey() === (string) $actorId) {
            $actorId = null;
        }

        AuditLogEntry::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $actorId,
            // Denormalised so the trail survives the user being deleted.
            'user_label' => $user?->getAttribute('email') ?? 'system',
            'event' => $event,
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'auditable_label' => $this->label($model),
            'before' => $before,
            'after' => $after,
            'ip' => Request::ip(),
            'trace_id' => is_string($traceId) ? $traceId : null,
        ]);
    }

    /**
     * A human-readable handle, so the log reads as "Executive Chairs" rather
     * than "Category #47".
     */
    private function label(Model $model): ?string
    {
        foreach (['name', 'title', 'reference', 'slug', 'email', 'path'] as $attribute) {
            $value = $model->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return Str::limit($value, 120);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function filter(array $attributes): array
    {
        return array_diff_key($attributes, array_flip(self::REDACTED));
    }
}
