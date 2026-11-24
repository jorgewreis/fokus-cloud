<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LawBetaApplication extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'campaign_code',
        'name',
        'email',
        'organization',
        'profile',
        'modules',
        'feedback',
        'source_url',
        'privacy_version',
        'privacy_consented_at',
        'beta_program_version',
        'beta_terms_accepted_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'privacy_consented_at' => 'datetime',
            'beta_terms_accepted_at' => 'datetime',
        ];
    }
}
