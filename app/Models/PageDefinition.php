<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $name
 * @property string $slug
 * @property string $page_type
 * @property Collection<int, ControlDefinition> $controls
 */
class PageDefinition extends Model
{
    protected $fillable = ['build_iteration_id', 'model_definition_id', 'name', 'slug', 'page_type', 'layout', 'position'];

    /** @return BelongsTo<BuildIteration, $this> */
    public function iteration(): BelongsTo
    {
        return $this->belongsTo(BuildIteration::class, 'build_iteration_id');
    }

    /** @return BelongsTo<ModelDefinition, $this> */
    public function modelDefinition(): BelongsTo
    {
        return $this->belongsTo(ModelDefinition::class);
    }

    /** @return HasMany<ControlDefinition, $this> */
    public function controls(): HasMany
    {
        return $this->hasMany(ControlDefinition::class)->orderBy('position');
    }
}
