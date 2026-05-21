<?php

use App\Http\Controllers\ProfileController;
use App\Livewire\ApiSettings;
use App\Livewire\OrderTransfers;
use App\Livewire\ProductManualSync;
use App\Livewire\SyncLogs;
use App\Livewire\SyncSettings;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');

    Route::get('/ayarlar/api', ApiSettings::class)->name('ayarlar.api');
    Route::get('/ayarlar/sync', SyncSettings::class)->name('ayarlar.sync');
    Route::get('/urunler', ProductManualSync::class)->name('urunler');
    Route::get('/siparisler', OrderTransfers::class)->name('siparisler');
    Route::get('/loglar', SyncLogs::class)->name('loglar');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
