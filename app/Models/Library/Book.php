<?php

namespace App\Models\Library;

use App\Models\Concerns\BelongsToEstablishment;
use App\Models\Library\BookCategory;
use App\Models\Library\BookCopy;
use Illuminate\Database\Eloquent\Model;

class Book extends Model
{
    //
    use BelongsToEstablishment;
      protected $fillable = ['category_id', 'title', 'author', 'publisher', 'isbn', 'publication_year', 'description', 'cover', 'is_active'];
    protected $casts = ['publication_year' => 'integer', 'is_active' => 'boolean'];

    public function category() { return $this->belongsTo(BookCategory::class, 'category_id'); }
    public function copies() { return $this->hasMany(BookCopy::class); }
}
