<?php

use App\Http\Controllers\CarteleraController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\KpiController;
use App\Http\Controllers\MovimientoCuentaController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UsuarioController;
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
    Route::get('/cartelera', [CarteleraController::class, 'index'])->name('cartelera');
    Route::get('/configuracion', [ConfiguracionController::class, 'index'])->name('configuracion.index');
    Route::put('/configuracion', [ConfiguracionController::class, 'update'])->name('configuracion.update');
    Route::put('/clientes/{cliente}', [ClienteController::class, 'update'])->name('clientes.update');
    Route::delete('/clientes/{cliente}', [ClienteController::class, 'destroy'])->name('clientes.destroy');

    Route::get('/clientes/{cliente}', [ClienteController::class, 'show'])->name('clientes.show');
    Route::get('/clientes/{cliente}/estado-cuenta', [ClienteController::class, 'estadoCuenta'])->name('clientes.estado-cuenta');
    Route::patch('/clientes/{cliente}/mensaje-pdf', [ClienteController::class, 'actualizarMensajePdf'])->name('clientes.mensaje-pdf');
    Route::post('/movimientos', [MovimientoCuentaController::class, 'store'])->name('movimientos.store');
    Route::put('/movimientos/{movimiento}', [MovimientoCuentaController::class, 'update'])->name('movimientos.update');

    Route::middleware('es_admin')->group(function () {
        Route::get('/kpi', [KpiController::class, 'index'])->name('kpi');
        Route::get('/usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
        Route::post('/usuarios', [UsuarioController::class, 'store'])->name('usuarios.store');
        Route::patch('/usuarios/{usuario}/toggle-activo', [UsuarioController::class, 'toggleActivo'])->name('usuarios.toggle-activo');
    });
});

require __DIR__.'/auth.php';
