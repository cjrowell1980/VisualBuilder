<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelRelationship extends Model
{
    protected $fillable = ['source_model_id', 'target_model_id', 'name', 'type', 'foreign_key'];

    /** @return BelongsTo<ModelDefinition, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(ModelDefinition::class, 'source_model_id');
    }

    /** @return BelongsTo<ModelDefinition, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(ModelDefinition::class, 'target_model_id');
    }
}
