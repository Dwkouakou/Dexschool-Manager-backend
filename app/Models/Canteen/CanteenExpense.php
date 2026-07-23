<?php

namespace App\Models\Canteen;

use App\Models\Concerns\BelongsToEstablishment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CanteenExpense extends Model
{
    //
    use BelongsToEstablishment;
     protected $fillable = ['title', 'amount', 'expense_date', 'category', 'description', 'receipt', 'created_by'];
    protected $casts = ['expense_date' => 'date', 'amount' => 'integer'];

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
