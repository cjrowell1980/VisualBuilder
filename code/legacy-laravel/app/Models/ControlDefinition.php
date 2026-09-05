<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $control_type
 * @property string|null $label
 * @property string $width
 * @property int|null $model_field_id
 * @property array{options?: list<array{value: string, label: string}>}|null $configuration
 * @property ModelField|null $field
 */
class ControlDefinition extends Model
{
    protected $fillable = ['page_definition_id', 'model_field_id', 'control_type', 'label', 'width', 'configuration', 'position'];

    protected function casts(): array
    {
        return ['configuration' => 'array'];
    }

    /** @return BelongsTo<PageDefinition, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(PageDefinition::class, 'page_definition_id');
    }

    /** @return BelongsTo<ModelField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(ModelField::class, 'model_field_id');
    }
}
