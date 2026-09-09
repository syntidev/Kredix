<?php

use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\KpiController;
use App\Http\Controllers\MovimientoCuentaController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'home' : 'login');
});

Route::get('/dashboard', function () {
    return redirect()->route('home');
})->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/home', [HomeController::class, 'index'])->name('home');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/clientes', [ClienteController::class, 'index'])->name('clientes.index');
    Route::post('/clientes', [ClienteController::class, 'store'])->name('clientes.store');
    Route::get('/cartera', [ClienteController::class, 'cartera'])->name('cartera');
    Route::get('/kpi', [KpiController::class, 'index'])->name('kpi');
    Route::get('/configuracion', [ConfiguracionController::class, 'index'])->name('configuracion.index');
    Route::put('/configuracion', [ConfiguracionController::class, 'update'])->name('configuracion.update');
    Route::put('/clientes/{cliente}', [ClienteController::class, 'update'])->name('clientes.update');
    Route::delete('/clientes/{cliente}', [ClienteController::class, 'destroy'])->name('clientes.destroy');

    Route::get('/clientes/{cliente}', [ClienteController::class, 'show'])->name('clientes.show');
    Route::post('/movimientos', [MovimientoCuentaController::class, 'store'])->name('movimientos.store');
    Route::put('/movimientos/{movimiento}', [MovimientoCuentaController::class, 'update'])->name('movimientos.update');
});

require __DIR__.'/auth.php';
