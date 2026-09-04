<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BuilderProject extends Model
{
    protected $fillable = ['user_id', 'name', 'slug', 'description', 'database_driver', 'github_repository'];

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
