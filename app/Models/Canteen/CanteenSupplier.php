<?php

namespace App\Models\Canteen;

use App\Models\Concerns\BelongsToEstablishment;
use Illuminate\Database\Eloquent\Model;

class CanteenSupplier extends Model
{
    //
    use BelongsToEstablishment; 
     protected $fillable = ['company_name', 'contact_name', 'phone', 'address'];
}
