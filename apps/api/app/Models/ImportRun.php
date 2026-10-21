<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bulk import launched from the admin and run in the background.
 *
 * The counters are the live progress the UI polls: a per-product job increments
 * `created`/`skipped` and the failure hook increments `failed`, all atomically,
 * so many workers can advance one run safely. `total` is set by the prepare job
 * once it has streamed the index. Terminal state is set by the batch's finally
 * callback, so a run always reaches a resting status even if the launcher closed
 * the tab.
 *
 * @property int $id
 * @property string $uuid
 * @property string $status
 * @property string|null $batch_id
 * @property array<int, string> $brands
 * @property array<string, mixed> $options
 * @property int $total
 * @property int $created
 * @property int $skipped
 * @property int $failed
 */
final class ImportRun extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'brands' => 'array',
            'options' => 'array',
            'total' => 'integer',
            'created' => 'integer',
            'skipped' => 'integer',
            'failed' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    /** Products the jobs have resolved so far (created + skipped + failed). */
    public function processed(): int
    {
        return $this->created + $this->skipped + $this->failed;
    }

    /** 0–100. While preparing (total still 0) it reads as 0, not divide-by-zero. */
    public function progressPercent(): int
    {
        if ($this->total <= 0) {
            return $this->status === 'completed' ? 100 : 0;
        }

        return (int) min(100, round($this->processed() / $this->total * 100));
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['preparing', 'running'], true);
    }
}
