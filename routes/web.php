<?php

use App\Http\Controllers\ClienteController;
use App\Http\Controllers\VentaCreditoController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
});

Route::get('/clientes', [ClienteController::class, 'index'])->name('clientes.index');
Route::post('/clientes', [ClienteController::class, 'store'])->name('clientes.store');

Route::get('/ventas-credito', [VentaCreditoController::class, 'index'])->name('ventas-credito.index');
Route::post('/ventas-credito', [VentaCreditoController::class, 'store'])->name('ventas-credito.store');
