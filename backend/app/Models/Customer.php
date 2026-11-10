<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsesFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[UsesFactory(CustomerFactory::class)]
#[Fillable(['name', 'email', 'phone', 'notes'])]
class Customer extends Model
{
    use HasFactory, SoftDeletes;

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
