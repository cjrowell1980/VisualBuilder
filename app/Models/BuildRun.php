<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $status
 * @property array<int, array{level: string, label: string, message: string}>|null $checks
 */
class BuildRun extends Model
{
    protected $fillable = ['build_iteration_id', 'type', 'status', 'checks', 'output', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['checks' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /** @return BelongsTo<BuildIteration, $this> */
    public function iteration(): BelongsTo
    {
        return $this->belongsTo(BuildIteration::class);
    }
}
