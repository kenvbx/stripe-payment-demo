<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower(trim((string) config('admin.email')));
        $password = (string) config('admin.password');
        if ($email === '' && $password === '') return;
        Validator::make(['email' => $email, 'password' => $password], [
            'email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'min:12', 'max:255'],
        ])->validate();

        $existing = User::where('email', $email)->first();
        if ($existing) {
            if ($existing->role !== 'admin') throw new \RuntimeException('The bootstrap admin email already belongs to a non-admin account.');
            return;
        }
        (new User)->forceFill([
            'name' => config('admin.name'), 'email' => $email, 'password' => $password, 'role' => 'admin',
        ])->save();
    }
}
