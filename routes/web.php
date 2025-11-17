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

    Route::livewire('/roadmap', 'roadmap.calendar')->name('roadmap');
    Route::livewire('/roadmap/timeline', 'roadmap.timeline')->name('roadmap.timeline');

    Route::livewire('/squads', 'squads.index')->name('squads.index');
    Route::livewire('/squads/create', 'squads.create')->name('squads.create');
    Route::livewire('/squads/{squad}/edit', 'squads.edit')->name('squads.edit');

    Route::livewire('/epics', 'epics.index')->name('epics.index');
    Route::livewire('/epics/create', 'epics.create')->name('epics.create');
    Route::livewire('/epics/{epic}/edit', 'epics.edit')->name('epics.edit');

    Route::livewire('/epics/{epic}/stories/create', 'stories.create')->name('stories.create');
    Route::livewire('/stories/{story}/edit', 'stories.edit')->name('stories.edit');
});

Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    /* Route::livewire('/user-profile', ShowPage::class)->name('user-profile'); */

});
