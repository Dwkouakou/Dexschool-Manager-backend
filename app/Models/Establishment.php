<?php

namespace App\Models;

use App\Models\Academic\AcademicYears;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Establishment extends Model
{
    //
    protected $fillable = ['name', 'code', 'is_active'];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function academicYears()
    {
        return $this->hasMany(AcademicYears::class, 'establishment_id');
    }
}
