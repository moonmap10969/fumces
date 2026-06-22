<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradingItem extends Model
{
    protected $fillable = [
    'section_id',
    'component_id',
    'quarter',
    'item_name',
    'max_score',
    'subject',
    'date_administered',
    'is_released',
];

    public function component()
    {
        return $this->belongsTo(GradingComponent::class, 'component_id');
    }
}