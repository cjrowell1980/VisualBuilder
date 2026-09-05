<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BuilderProject extends Model
{
    protected $fillable = [
        'user_id', 'name', 'slug', 'description', 'template', 'database_driver',
        'docker_enabled', 'output_path', 'status', 'github_repository',
    ];

    protected function casts(): array
    {
        return ['docker_enabled' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<BuildIteration, $this> */
    public function iterations(): HasMany
    {
        return $this->hasMany(BuildIteration::class);
    }
}
