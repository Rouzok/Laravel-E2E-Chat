<?php

use App\Http\Controllers\MessageController;
use App\Http\Controllers\PasswordRecoveryController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::post('password/key-backup', [PasswordRecoveryController::class, 'show'])
    ->middleware('throttle:5,1')
    ->name('password.key-backup');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('chat', 'pages::chat')->name('chat');
    Route::livewire('contacts/{user}', 'pages::contact-profile')->name('contacts.show');

    Route::post('messages', [MessageController::class, 'store'])->name('messages.store');
    Route::get('messages/{user}', [MessageController::class, 'history'])->name('messages.history');
    Route::put('user/public-key', [MessageController::class, 'storePublicKey'])->name('user.public-key.store');
    Route::get('users/{user}/public-key', [MessageController::class, 'publicKey'])->name('user.public-key');
    Route::get('user/key-backup', [MessageController::class, 'keyBackup'])->name('user.key-backup.show');
    Route::put('user/key-backup', [MessageController::class, 'storeKeyBackup'])->name('user.key-backup.store');
});

Route::middleware(['auth', 'verified', 'role:user'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

Route::middleware(['auth', 'verified', 'role:admin'])->group(function () {
    Route::view('admin/dashboard', 'admin.dashboard')->name('admin.dashboard');
    Route::livewire('admin/reports', 'pages::admin-reports')->name('admin.reports');
});

require __DIR__.'/settings.php';
