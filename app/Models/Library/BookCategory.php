<?php

namespace App\Models\Library;

use App\Models\Concerns\BelongsToEstablishment;
use App\Models\Library\Book;
use Illuminate\Database\Eloquent\Model;

class BookCategory extends Model
{
    //
    use BelongsToEstablishment;
     protected $table = 'book_categories';
    protected $fillable = ['name', 'description'];

    public function books() { return $this->hasMany(Book::class, 'category_id'); }
}
