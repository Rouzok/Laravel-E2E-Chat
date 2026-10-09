<?php

use App\Http\Controllers\MessageController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('chat', 'pages::chat')->name('chat');

    // Message API routes (JSON)
    Route::post('messages', [MessageController::class, 'store'])->name('messages.store');
    Route::get('messages/{user}', [MessageController::class, 'history'])->name('messages.history');

    // Public key routes (E2EE key exchange)
    Route::put('user/public-key', [MessageController::class, 'storePublicKey'])->name('user.public-key.store');
    Route::get('users/{user}/public-key', [MessageController::class, 'publicKey'])->name('user.public-key');
});

Route::middleware(['auth', 'verified', 'role:user'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

Route::middleware(['auth', 'verified', 'role:admin'])->group(function () {
    Route::view('admin/dashboard', 'admin.dashboard')->name('admin.dashboard');
});

require __DIR__.'/settings.php';
