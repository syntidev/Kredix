<?php

use App\Http\Controllers\ClienteController;
use App\Http\Controllers\MovimientoCuentaController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'clientes.index' : 'login');
});

Route::get('/dashboard', function () {
    return redirect()->route('clientes.index');
})->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/clientes', [ClienteController::class, 'index'])->name('clientes.index');
    Route::post('/clientes', [ClienteController::class, 'store'])->name('clientes.store');
    Route::put('/clientes/{cliente}', [ClienteController::class, 'update'])->name('clientes.update');
    Route::delete('/clientes/{cliente}', [ClienteController::class, 'destroy'])->name('clientes.destroy');

    Route::get('/clientes/{cliente}', [ClienteController::class, 'show'])->name('clientes.show');
    Route::post('/movimientos', [MovimientoCuentaController::class, 'store'])->name('movimientos.store');
    Route::put('/movimientos/{movimiento}', [MovimientoCuentaController::class, 'update'])->name('movimientos.update');
});

require __DIR__.'/auth.php';
