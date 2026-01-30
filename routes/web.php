<?php

use App\Http\Controllers\GoogleAuthController;
use App\Livewire\Pages\Profile\ShowPage;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Route to redirect to Google's OAuth page
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');

// Route to handle the callback from Google
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    /* Route::livewire('/dashboard', 'dashboard')->name('dashboard'); */

    Route::livewire('/roadmap', 'roadmap.calendar')->name('roadmap');
    Route::livewire('/roadmap/timeline', 'roadmap.timeline')->name('roadmap.timeline');

    Route::livewire('/squads', 'squads.index')->name('squads.index');
    Route::livewire('/squads/create', 'squads.create')->name('squads.create');
    Route::livewire('/squads/{squad}/edit', 'squads.edit')->name('squads.edit');

    Route::livewire('/categories', 'categories.index')->name('categories.index');
    Route::livewire('/categories/create', 'categories.create')->name('categories.create');
    Route::livewire('/categories/{category}/edit', 'categories.edit')->name('categories.edit');

    Route::livewire('/epics', 'epics.index')->name('epics.index');
    Route::livewire('/epics/create', 'epics.create')->name('epics.create');
    Route::livewire('/epics/{epic}/edit', 'epics.edit')->name('epics.edit');

    Route::livewire('/epics/{epic}/stories/create', 'stories.create')->name('stories.create');
    Route::livewire('/stories/{story}/edit', 'stories.edit')->name('stories.edit');

    Route::livewire('/planning', 'planning.index')->name('planning.index');
    Route::livewire('/planning/create', 'planning.show')->name('planning.create');
    Route::livewire('/planning/{plan}', 'planning.show')->name('planning.show');
});

Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    /* Route::livewire('/user-profile', ShowPage::class)->name('user-profile'); */

});
