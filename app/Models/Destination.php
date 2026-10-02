<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Destination extends Model
{
    protected $fillable = [
        'code', 'name', 'entity_type', 'state',
        'manufacturer_model', 'responsible_position', 'observations',
        'installation_date', 'detailed', 'capacity', 'power',
        'physical_dimensions', 'fundamental_pieces', 'supplies',
        'repair_details', 'frequency_use', 'image',
    ];

    // Scope útil
    public function scopeDraft($query)
    {
        return $query->where('state', 'borrador');
    }
}
