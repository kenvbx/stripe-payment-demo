<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => 'Stripe Payment Demo API',
    'status' => 'ok',
    'test_mode_only' => true,
]));
