<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuildPackage extends Model
{
    protected $fillable = ['build_iteration_id', 'format', 'path', 'checksum', 'bytes', 'packaged_at'];

    protected function casts(): array
    {
        return ['packaged_at' => 'datetime'];
    }

    /** @return BelongsTo<BuildIteration, $this> */
    public function iteration(): BelongsTo
    {
        return $this->belongsTo(BuildIteration::class);
    }
}
