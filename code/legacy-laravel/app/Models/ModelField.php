<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $name
 * @property string $type
 * @property string|null $default_value
 * @property bool $nullable
 * @property bool $indexed
 * @property bool $unique
 * @property list<string>|null $validation_rules
 */
class ModelField extends Model
{
    protected $fillable = [
        'model_definition_id', 'name', 'label', 'type', 'nullable', 'indexed',
        'unique', 'default_value', 'validation_rules', 'position',
    ];

    protected function casts(): array
    {
        return [
            'nullable' => 'boolean',
            'indexed' => 'boolean',
            'unique' => 'boolean',
            'validation_rules' => 'array',
        ];
    }

    /** @return BelongsTo<ModelDefinition, $this> */
    public function modelDefinition(): BelongsTo
    {
        return $this->belongsTo(ModelDefinition::class);
    }
}
