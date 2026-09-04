<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $name
 * @property string $table_name
 * @property bool $soft_deletes
 * @property bool $timestamps
 * @property Collection<int, ModelField> $fields
 */
class ModelDefinition extends Model
{
    protected $fillable = ['build_iteration_id', 'name', 'table_name', 'soft_deletes', 'timestamps'];

    protected function casts(): array
    {
        return ['soft_deletes' => 'boolean', 'timestamps' => 'boolean'];
    }

    /** @return BelongsTo<BuildIteration, $this> */
    public function iteration(): BelongsTo
    {
        return $this->belongsTo(BuildIteration::class);
    }

    /** @return HasMany<ModelField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(ModelField::class)->orderBy('position');
    }
}
