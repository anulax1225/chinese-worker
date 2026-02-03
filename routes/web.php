<?php

use Illuminate\Support\Facades\Route;

// Dashboard routes will be added here
Route::get('/', function () {
    return auth()->check() ? redirect('/dashboard') : redirect('/login');
})->name('home');
