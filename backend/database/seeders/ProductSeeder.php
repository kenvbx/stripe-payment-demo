<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'Starter plan', 'slug' => 'starter-plan', 'description' => 'For trying the flow', 'price_cents' => 2900],
            ['name' => 'Pro workspace', 'slug' => 'pro-workspace', 'description' => 'For growing teams', 'price_cents' => 8900],
            ['name' => 'Team plan', 'slug' => 'team-plan', 'description' => 'For larger operations', 'price_cents' => 14900],
        ] as $product) {
            Product::query()->updateOrCreate(['slug' => $product['slug']], $product + ['currency' => 'usd', 'is_active' => true]);
        }
    }
}
