<?php

namespace App\Models;

use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsesFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UsesFactory(ServiceFactory::class)]
#[Fillable(['name', 'description', 'duration_minutes', 'price', 'is_active'])]
class Service extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'is_active' => 'boolean', 'duration_minutes' => 'integer'];
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
