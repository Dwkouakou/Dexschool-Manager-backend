<?php

namespace App\Models\Library;

use App\Models\Concerns\BelongsToEstablishment;
use App\Models\Library\Book;
use App\Models\Library\BookLoan;
use Illuminate\Database\Eloquent\Model;

class BookCopy extends Model
{
    //
    use BelongsToEstablishment;
     protected $fillable = ['book_id', 'inventory_number', 'status'];

    public function book() { return $this->belongsTo(Book::class); }
    public function loans() { return $this->hasMany(BookLoan::class, 'book_copy_id'); }
}
