<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('admin:create {email} {--name=Demo Administrator}', function () {
    $email = strtolower(trim($this->argument('email')));
    $password = $this->secret('Password (at least 12 characters)');
    $confirmation = $this->secret('Confirm password');
    $validator = \Illuminate\Support\Facades\Validator::make([
        'email' => $email, 'name' => $this->option('name'),
        'password' => $password, 'password_confirmation' => $confirmation,
    ], [
        'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        'name' => ['required', 'string', 'max:255'],
        'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
    ]);
    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) $this->error($error);
        return 1;
    }
    \App\Models\User::forceCreate([
        'email' => $email, 'name' => $this->option('name'), 'password' => $password, 'role' => 'admin',
    ]);
    $this->info('Administrator created. Sign in from the dashboard.');
    return 0;
})->purpose('Create an administrator using an interactive password prompt');
