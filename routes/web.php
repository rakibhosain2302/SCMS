<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Members\MemberController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'authorization'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::middleware('branch.assigned')->group(function () {
        Route::resource('members', MemberController::class);
        Route::post('members/{member}/status', [MemberController::class, 'status'])->name('members.status');
        Route::post('members/{member}/documents', [MemberController::class, 'storeDocument'])->name('members.documents.store');
        Route::get('members/{member}/documents/{document}', [MemberController::class, 'document'])->name('members.documents.show');
        Route::delete('members/{member}/documents/{document}', [MemberController::class, 'destroyDocument'])->name('members.documents.destroy');
    });
});

Route::redirect('/dashboard', '/');
