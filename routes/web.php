<?php

use App\Http\Controllers\CarteleraController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ConciliacionController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\KpiController;
use App\Http\Controllers\MovimientoCuentaController;
use App\Http\Controllers\PlanFinanciamientoController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProspectoController;
use App\Http\Controllers\TallerController;
use App\Http\Controllers\TasaBcvController;
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
    Route::get('/productos', [ProductoController::class, 'search'])->name('productos.search');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/clientes', [ClienteController::class, 'index'])->name('clientes.index');
    Route::get('/clientes/buscar', [ClienteController::class, 'buscar'])->name('clientes.buscar');
    Route::post('/clientes', [ClienteController::class, 'store'])->name('clientes.store');
    Route::post('/clientes/rapido', [ClienteController::class, 'storeRapido'])->name('clientes.store-rapido');
    Route::get('/cartera', [ClienteController::class, 'cartera'])->name('cartera');
    Route::get('/cartelera', [CarteleraController::class, 'index'])->name('cartelera');
    Route::get('/conciliacion', [ConciliacionController::class, 'index'])->middleware('acceso_conciliacion')->name('conciliacion.index');
    Route::get('/configuracion', [ConfiguracionController::class, 'index'])->name('configuracion.index');
    Route::put('/configuracion/whatsapp', [ConfiguracionController::class, 'updateWhatsapp'])->name('configuracion.whatsapp');
    Route::put('/configuracion/estado-cuenta', [ConfiguracionController::class, 'updateEstadoCuenta'])->name('configuracion.estado-cuenta');
    Route::put('/configuracion/empresa', [ConfiguracionController::class, 'updateEmpresa'])->name('configuracion.empresa');
    Route::put('/clientes/{cliente}', [ClienteController::class, 'update'])->name('clientes.update');
    Route::delete('/clientes/{cliente}', [ClienteController::class, 'destroy'])->name('clientes.destroy');

    Route::get('/clientes/{cliente}', [ClienteController::class, 'show'])->name('clientes.show');
    Route::get('/clientes/{cliente}/buscar-en-eventos', [ClienteController::class, 'buscarEnEventos'])->name('clientes.buscar-en-eventos');
    Route::post('/clientes/{cliente}/fusionar-prospecto', [ClienteController::class, 'fusionarProspecto'])->name('clientes.fusionar-prospecto');
    Route::post('/clientes/{cliente}/descartar-prospecto', [ClienteController::class, 'descartarProspecto'])->name('clientes.descartar-prospecto');

    Route::get('/prospectos', [ProspectoController::class, 'index'])->name('prospectos.index');
    Route::post('/prospectos/{prospecto}/promover', [ProspectoController::class, 'promover'])->name('prospectos.promover');
    // {nombreSlug} es puramente cosmetico -- Cliente $cliente (route model binding
    // por id) sigue siendo la unica fuente real para resolver el cliente; iOS/WebKit
    // ignora el filename= del Content-Disposition al guardar desde el visor nativo
    // y usa el ultimo segmento de la URL, de ahi que el nombre tenga que vivir aqui
    Route::get('/clientes/{cliente}/estado-cuenta-{nombreSlug}.pdf', [ClienteController::class, 'estadoCuenta'])->name('clientes.estado-cuenta');
    Route::patch('/clientes/{cliente}/mensaje-pdf', [ClienteController::class, 'actualizarMensajePdf'])->name('clientes.mensaje-pdf');
    Route::patch('/clientes/{cliente}/responsable', [ClienteController::class, 'actualizarResponsable'])->name('clientes.responsable');
    Route::get('/taller', [TallerController::class, 'index'])->name('taller.index');
    Route::get('/taller/nuevo', [TallerController::class, 'create'])->name('taller.create');
    Route::post('/taller', [TallerController::class, 'store'])->name('taller.store');
    Route::get('/taller/{ticket}', [TallerController::class, 'show'])->name('taller.show');
    Route::put('/taller/{ticket}', [TallerController::class, 'update'])->name('taller.update');
    Route::patch('/taller/{ticket}/marcar-atendido', [TallerController::class, 'marcarAtendido'])->name('taller.marcar-atendido');
    Route::patch('/taller/{ticket}/trabajo-realizado', [TallerController::class, 'guardarTrabajoRealizado'])->name('taller.trabajo-realizado');
    Route::post('/taller/{ticket}/fotos/{coleccion}', [TallerController::class, 'subirFotos'])->name('taller.fotos.store');
    Route::post('/taller/{ticket}/repuestos', [TallerController::class, 'agregarRepuesto'])->name('taller.repuestos.store');
    Route::delete('/taller/{ticket}/repuestos/{repuesto}', [TallerController::class, 'eliminarRepuesto'])->name('taller.repuestos.destroy');

    Route::post('/movimientos', [MovimientoCuentaController::class, 'store'])->name('movimientos.store');
    Route::put('/movimientos/{movimiento}', [MovimientoCuentaController::class, 'update'])->name('movimientos.update');
    Route::patch('/movimientos/{movimiento}/validacion', [MovimientoCuentaController::class, 'validar'])->name('movimientos.validar');
    Route::delete('/movimientos/{movimiento}', [MovimientoCuentaController::class, 'destroy'])->name('movimientos.destroy');
    Route::post('/planes-financiamiento/{plan}/aplicar-mora', [PlanFinanciamientoController::class, 'aplicarMora'])->name('planes-financiamiento.aplicar-mora');

    Route::middleware('es_admin')->group(function () {
        Route::get('/kpi', [KpiController::class, 'index'])->name('kpi');
        Route::get('/usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
        Route::post('/usuarios', [UsuarioController::class, 'store'])->name('usuarios.store');
        Route::patch('/usuarios/{usuario}/toggle-activo', [UsuarioController::class, 'toggleActivo'])->name('usuarios.toggle-activo');
        Route::patch('/usuarios/{usuario}/toggle-acceso-conciliacion', [UsuarioController::class, 'toggleAccesoConciliacion'])->name('usuarios.toggle-acceso-conciliacion');
        Route::patch('/usuarios/{usuario}/toggle-rol-taller', [UsuarioController::class, 'toggleRolTaller'])->name('usuarios.toggle-rol-taller');
        Route::patch('/usuarios/{usuario}/reset-password', [UsuarioController::class, 'resetPassword'])->name('usuarios.reset-password');
        Route::patch('/tasa-bcv', [TasaBcvController::class, 'update'])->name('tasa-bcv.update');
    });
});

require __DIR__.'/auth.php';
