<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductInterest extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $table = 'product_interests';
    protected $fillable = [
        'id', 'name', 'email', 'products', 'profiles', 'organization', 'city', 'state',
        'team_size', 'desired_capacity', 'catalog_max_capacity', 'current_process', 'main_difficulties', 'modules', 'request_context', 'source_url',
        'privacy_version', 'status', 'consented_at', 'last_submitted_at',
    ];
    protected function casts(): array
    {
        return ['consented_at' => 'datetime', 'last_submitted_at' => 'datetime', 'products' => 'array', 'profiles' => 'array', 'modules' => 'array'];
    }
}
