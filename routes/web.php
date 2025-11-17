<?php

use App\Livewire\Pages\Profile\ShowPage;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::livewire('/dashboard', 'dashboard')->name('dashboard');
});

Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    /* Route::livewire('/user-profile', ShowPage::class)->name('user-profile'); */

});
