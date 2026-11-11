<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'price_cents', 'currency', 'is_active'];
    protected $casts = ['price_cents' => 'integer', 'is_active' => 'boolean'];
}
