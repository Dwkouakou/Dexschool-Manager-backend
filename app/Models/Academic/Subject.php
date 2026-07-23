<?php

namespace App\Models\Academic;

use App\Models\Concerns\BelongsToEstablishment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    //
     use HasFactory, BelongsToEstablishment;

    protected $table = 'subjects';

    protected $fillable = [
        'name',
        'code',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
