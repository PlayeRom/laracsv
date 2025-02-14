<?php

namespace Playerom\Laracsv\Tests\Laracsv\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $guarded = ['id'];

    protected $hidden = [
        'updated_at', 'created_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'float',
            'original_price' => 'float',
            'production_date' => 'date:Y-m-d', // cast to Carbon as date
            'type' => EnumType::class,
        ];
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class);
    }
}
