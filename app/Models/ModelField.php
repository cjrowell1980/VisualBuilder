<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $name
 * @property string $type
 * @property bool $nullable
 * @property bool $indexed
 */
class ModelField extends Model
{
    protected $fillable = ['model_definition_id', 'name', 'type', 'nullable', 'indexed', 'default_value', 'position'];

    protected function casts(): array
    {
        return ['nullable' => 'boolean', 'indexed' => 'boolean'];
    }

    /** @return BelongsTo<ModelDefinition, $this> */
    public function modelDefinition(): BelongsTo
    {
        return $this->belongsTo(ModelDefinition::class);
    }
}
