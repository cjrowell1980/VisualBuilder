<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property BuilderProject $project
 * @property Collection<int, ModelDefinition> $models
 * @property Collection<int, PluginRequirement> $plugins
 * @property Collection<int, PageDefinition> $pages
 * @property Collection<int, BuildRun> $runs
 * @property Collection<int, BuildPackage> $packages
 */
class BuildIteration extends Model
{
    protected $fillable = ['builder_project_id', 'number', 'name', 'status', 'configuration', 'generated_at'];

    protected function casts(): array
    {
        return ['configuration' => 'array', 'generated_at' => 'datetime'];
    }

    /** @return BelongsTo<BuilderProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(BuilderProject::class, 'builder_project_id');
    }

    /** @return HasMany<ModelDefinition, $this> */
    public function models(): HasMany
    {
        return $this->hasMany(ModelDefinition::class);
    }

    /** @return HasMany<PluginRequirement, $this> */
    public function plugins(): HasMany
    {
        return $this->hasMany(PluginRequirement::class);
    }

    /** @return HasMany<PageDefinition, $this> */
    public function pages(): HasMany
    {
        return $this->hasMany(PageDefinition::class)->orderBy('position');
    }

    /** @return HasMany<BuildRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(BuildRun::class)->latest();
    }

    /** @return HasMany<BuildPackage, $this> */
    public function packages(): HasMany
    {
        return $this->hasMany(BuildPackage::class)->latest();
    }
}
