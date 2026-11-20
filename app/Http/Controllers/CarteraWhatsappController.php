<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\MovimientoCuenta;
use App\Services\CobranzaWhatsapp;
use App\Services\Taller\PlantillasWhatsapp;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;

// Cola de WhatsApp en lote sobre /cartera: un chat por toque (wa.me solo precarga el texto, la persona pulsa Enviar).
// El envio se registra con ClienteController::registrarWhatsappCobranza; aqui solo se arma la cola y se deshace.
class CarteraWhatsappController
{
    // mensajes que viajan ya armados en la carga; el resto se pide a mensajes() conforme avanza la cola
    private const MENSAJES_INICIALES = 6;

    private const MENSAJES_MAX = 12;

    public function index(Request $request)
    {
        $tipo = $this->tipoValido($request->query('tipo'));
        $desde = $this->letra($request->query('desde'), 'A');
        $hasta = $this->letra($request->query('hasta'), 'Z');
        $incluirEnviados = $request->boolean('incluir_enviados');
        // A-Z completo no acota: ahi tambien entran los nombres que no empiezan con letra
        $acotado = ! ($desde === 'A' && $hasta === 'Z');

        // mismo conjunto que /cartera sin filtros (ClienteController::cartera)
        $todos = collect((new ClienteController())->clientesConMetricasCartera())
            ->map(fn (array $c) => ['id' => $c['id'], 'nombre' => (string) $c['nombre'], 'telefono' => (string) $c['telefono'], 'clave' => $this->claveNombre((string) $c['nombre'])]);

        $sinLetra = $todos->filter(fn ($c) => ! preg_match('/^[a-z]/', $c['clave']))->count();
        $enRango = $todos
            ->filter(function ($c) use ($desde, $hasta, $acotado) {
                $l = Str::upper(substr($c['clave'], 0, 1));

                return preg_match('/^[a-z]/', $c['clave']) ? ($l >= $desde && $l <= $hasta) : ! $acotado;
            })
            ->sort(fn ($a, $b) => [$a['clave'], $a['id']] <=> [$b['clave'], $b['id']])
            ->values();

        $ids = $enRango->pluck('id')->all();
        $saldos = MovimientoCuenta::saldosPendientes($ids);
        $disponibles = CobranzaWhatsapp::disponibles($tipo, $ids);
        $enviados = $this->enviadosDelMes($tipo, $ids);

        $filas = $enRango->map(function ($c) use ($saldos, $disponibles, $enviados) {
            $tieneTelefono = preg_replace('/\D/', '', $c['telefono']) !== '';
            $estado = match (true) {
                ! $tieneTelefono => 'sin_telefono',
                ! ($disponibles[$c['id']] ?? false) => 'no_aplica',
                isset($enviados[$c['id']]) => 'enviado',
                default => 'pendiente',
            };

            return ['id' => $c['id'], 'nombre' => $c['nombre'], 'telefono' => $c['telefono'], 'saldo' => $saldos[$c['id']] ?? 0.0, 'estado' => $estado];
        });

        // los ya enviados solo vuelven a la cola si se pide reenviar; si no, se cuentan aparte
        $enviadosCount = $filas->where('estado', 'enviado')->count();
        $cola = $incluirEnviados ? $filas : $filas->reject(fn ($f) => $f['estado'] === 'enviado')->values();

        $iniciales = $cola->where('estado', 'pendiente')->take(self::MENSAJES_INICIALES)->pluck('id')->all();

        return Inertia::render('Cartera/WhatsappLote', [
            'filas' => $cola->values(),
            'enviadosMes' => $enviadosCount,
            'sinLetra' => $sinLetra,
            'tipo' => $tipo,
            'tipos' => collect(CobranzaWhatsapp::tipos())->map(fn ($t) => ['tipo' => $t, 'etiqueta' => PlantillasWhatsapp::COBRANZA[$t]['etiqueta']])->values(),
            'desde' => $desde,
            'hasta' => $hasta,
            'incluirEnviados' => $incluirEnviados,
            'mensajes' => $this->mensajesDe($iniciales, $tipo),
        ]);
    }

    // texto de cada cliente ya armado por el mismo motor de la ficha (CobranzaWhatsapp::paraCliente)
    public function mensajes(Request $request)
    {
        $data = $request->validate([
            'tipo' => ['required', 'in:'.implode(',', CobranzaWhatsapp::tipos())],
            'ids' => ['required', 'array', 'max:'.self::MENSAJES_MAX],
            'ids.*' => ['integer'],
        ]);

        return response()->json($this->mensajesDe($data['ids'], $data['tipo']));
    }

    // el evento de ActivityLog no se borra: se compensa con whatsapp_cobranza_deshecho (el conteo resta uno)
    public function deshacer(Request $request, Cliente $cliente)
    {
        $data = $request->validate(['tipo' => ['required', 'in:'.implode(',', CobranzaWhatsapp::tipos())]]);

        if (! isset($this->enviadosDelMes($data['tipo'], [$cliente->id])[$cliente->id])) {
            abort(422, 'Este cliente no tiene un envío de este mes para deshacer.');
        }

        activity()->causedBy($request->user())->performedOn($cliente)
            ->withProperties(['tipo' => $data['tipo']])->log('whatsapp_cobranza_deshecho');

        return response()->noContent();
    }

    /** @return array<int, string> clienteId => texto */
    private function mensajesDe(array $ids, string $tipo): array
    {
        $salida = [];
        foreach (Cliente::whereIn('id', $ids)->get() as $cliente) {
            $salida[$cliente->id] = collect(CobranzaWhatsapp::paraCliente($cliente)['tipos'])->firstWhere('tipo', $tipo)['texto'];
        }

        return $salida;
    }

    /** clienteId => true si en el mes calendario actual (America/Caracas) hay mas envios que deshechos de ese tipo */
    private function enviadosDelMes(string $tipo, array $ids): array
    {
        $inicio = Carbon::now('America/Caracas')->startOfMonth();
        $filas = Activity::where('subject_type', (new Cliente())->getMorphClass())
            ->whereIn('subject_id', $ids)
            ->whereIn('description', ['whatsapp_cobranza', 'whatsapp_cobranza_deshecho'])
            ->where('properties->tipo', $tipo)
            ->where('created_at', '>=', $inicio)->where('created_at', '<', $inicio->copy()->addMonth())
            ->get(['subject_id', 'description']);

        $neto = [];
        foreach ($filas as $f) {
            $neto[$f->subject_id] = ($neto[$f->subject_id] ?? 0) + ($f->description === 'whatsapp_cobranza' ? 1 : -1);
        }

        return array_filter($neto, fn (int $n) => $n > 0);
    }

    // nombre en minusculas y sin acentos: "Álvarez" -> "alvarez"
    private function claveNombre(string $nombre): string
    {
        return Str::lower(Str::ascii(trim($nombre)));
    }

    private function letra(mixed $valor, string $porDefecto): string
    {
        $l = Str::upper(Str::ascii((string) $valor));

        return preg_match('/^[A-Z]$/', $l) ? $l : $porDefecto;
    }

    private function tipoValido(mixed $tipo): string
    {
        return in_array($tipo, CobranzaWhatsapp::tipos(), true) ? $tipo : 'promocion';
    }
}
