<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradingComponent extends Model
{
    protected $fillable = ['category', 'percentage', 'code', 'computation', 'subject', 'section_id'];

    // Add this to connect to GradingItem
    public function items()
    {
        return $this->hasMany(GradingItem::class, 'component_id');
    }
}