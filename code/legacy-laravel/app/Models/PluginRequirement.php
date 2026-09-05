<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PluginRequirement extends Model
{
    protected $fillable = ['build_iteration_id', 'package', 'constraint', 'type', 'approved'];

    protected function casts(): array
    {
        return ['approved' => 'boolean'];
    }

    /** @return BelongsTo<BuildIteration, $this> */
    public function iteration(): BelongsTo
    {
        return $this->belongsTo(BuildIteration::class);
    }
}
