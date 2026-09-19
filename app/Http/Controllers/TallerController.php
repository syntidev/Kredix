<?php

namespace App\Http\Controllers;

use App\Models\TicketRepuesto;
use App\Models\TicketTaller;
use App\Models\User;
use App\Services\ImagenUploadService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TallerController extends Controller
{
    public function __construct(private ImagenUploadService $imagenUploadService)
    {
    }

    private function fotosUrls(TicketTaller $ticket, string $coleccion): array
    {
        return $ticket->getMedia($coleccion)->map(fn ($media) => [
            'id' => $media->id,
            'url' => $media->getUrl(),
            'thumb_url' => $media->getUrl('thumb'),
        ])->all();
    }

    private function ticketResumen(TicketTaller $ticket): array
    {
        return [
            'id' => $ticket->id,
            'fecha' => $ticket->created_at->format('Y-m-d'),
            'tipo' => $ticket->tipo,
            'cliente' => $ticket->cliente?->nombre,
            'bici_marca_modelo' => $ticket->bici_marca_modelo,
            'tipo_servicio' => $ticket->tipo_servicio,
            'mecanico' => $ticket->mecanico?->name,
            'estado' => $ticket->estado,
        ];
    }

    public function index(Request $request)
    {
        $estado = $request->query('estado');

        $tickets = TicketTaller::query()
            ->with(['cliente:id,nombre', 'mecanico:id,name'])
            ->when(in_array($estado, ['en_proceso', 'atendido'], true), fn ($query) => $query->where('estado', $estado))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($ticket) => $this->ticketResumen($ticket));

        return Inertia::render('Taller/Index', [
            'tickets' => $tickets,
            'estado' => $estado,
        ]);
    }

    public function create()
    {
        return Inertia::render('Taller/Nuevo', [
            'mecanicos' => User::where('es_oculto', false)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $esServicioCliente = $request->input('tipo') === 'servicio_cliente';

        $validated = $request->validate([
            'tipo' => ['required', 'in:servicio_cliente,armado_interno'],
            'cliente_id' => [Rule::requiredIf($esServicioCliente), 'nullable', 'exists:clientes,id'],
            'bici_marca_modelo' => ['required', 'string', 'max:255'],
            'talla_rin' => ['required', 'string', 'max:255'],
            'tipo_servicio' => [Rule::requiredIf($esServicioCliente), 'nullable', 'in:basico,full,vip,otro'],
            'monto_servicio' => [Rule::requiredIf($esServicioCliente), 'nullable', 'numeric', 'min:0'],
            'mecanico_id' => ['required', 'exists:users,id'],
            'diagnostico' => ['nullable', 'array'],
            'diagnostico.*.item' => ['required_with:diagnostico', 'string', 'max:255'],
            'diagnostico.*.estado' => ['required_with:diagnostico', 'in:bien,atencion'],
            'diagnostico.*.nota' => ['nullable', 'string', 'max:1000'],
            'fotos_entrada.*' => ['nullable', 'image', 'max:5120'],
        ]);

        $ticket = TicketTaller::create([
            'tipo' => $validated['tipo'],
            'cliente_id' => $esServicioCliente ? $validated['cliente_id'] : null,
            'bici_marca_modelo' => $validated['bici_marca_modelo'],
            'talla_rin' => $validated['talla_rin'],
            'tipo_servicio' => $esServicioCliente ? $validated['tipo_servicio'] : null,
            'monto_servicio' => $esServicioCliente ? $validated['monto_servicio'] : null,
            'diagnostico' => $validated['diagnostico'] ?? [],
            'mecanico_id' => $validated['mecanico_id'],
            'registrado_por' => $request->user()->id,
        ]);

        foreach ($request->file('fotos_entrada', []) as $foto) {
            $rutaComprimida = $this->imagenUploadService->comprimir($foto);
            $ticket->addMedia($rutaComprimida)->usingFileName($foto->getClientOriginalName())->toMediaCollection('entrada');
        }

        return redirect()->route('taller.show', $ticket->id);
    }

    public function show(TicketTaller $ticket)
    {
        $ticket->load(['cliente:id,nombre,telefono', 'mecanico:id,name', 'registradoPor:id,name', 'repuestos']);

        return Inertia::render('Taller/Show', [
            'ticket' => [
                ...$ticket->only([
                    'id', 'tipo', 'bici_marca_modelo', 'talla_rin', 'tipo_servicio',
                    'monto_servicio', 'diagnostico', 'estado', 'mecanico_id',
                ]),
                'cliente' => $ticket->cliente,
                'mecanico' => $ticket->mecanico,
                'registrado_por' => $ticket->registradoPor,
                'created_at' => $ticket->created_at->format('Y-m-d'),
                'repuestos' => $ticket->repuestos,
                'fotos_entrada' => $this->fotosUrls($ticket, 'entrada'),
                'fotos_salida' => $this->fotosUrls($ticket, 'salida'),
            ],
            'mecanicos' => User::where('es_oculto', false)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, TicketTaller $ticket)
    {
        $esServicioCliente = $ticket->tipo === 'servicio_cliente';

        $validated = $request->validate([
            'bici_marca_modelo' => ['required', 'string', 'max:255'],
            'talla_rin' => ['required', 'string', 'max:255'],
            'tipo_servicio' => [Rule::requiredIf($esServicioCliente), 'nullable', 'in:basico,full,vip,otro'],
            'monto_servicio' => [Rule::requiredIf($esServicioCliente), 'nullable', 'numeric', 'min:0'],
            'mecanico_id' => ['required', 'exists:users,id'],
            'diagnostico' => ['nullable', 'array'],
            'diagnostico.*.item' => ['required_with:diagnostico', 'string', 'max:255'],
            'diagnostico.*.estado' => ['required_with:diagnostico', 'in:bien,atencion'],
            'diagnostico.*.nota' => ['nullable', 'string', 'max:1000'],
        ]);

        $ticket->update([
            'bici_marca_modelo' => $validated['bici_marca_modelo'],
            'talla_rin' => $validated['talla_rin'],
            'tipo_servicio' => $esServicioCliente ? $validated['tipo_servicio'] : null,
            'monto_servicio' => $esServicioCliente ? $validated['monto_servicio'] : null,
            'mecanico_id' => $validated['mecanico_id'],
            'diagnostico' => $validated['diagnostico'] ?? [],
        ]);

        return redirect()->route('taller.show', $ticket->id);
    }

    public function subirFotos(Request $request, TicketTaller $ticket, string $coleccion)
    {
        abort_unless(in_array($coleccion, ['entrada', 'salida'], true), 404);

        $request->validate([
            'fotos.*' => ['required', 'image', 'max:5120'],
        ]);

        foreach ($request->file('fotos', []) as $foto) {
            $rutaComprimida = $this->imagenUploadService->comprimir($foto);
            $ticket->addMedia($rutaComprimida)->usingFileName($foto->getClientOriginalName())->toMediaCollection($coleccion);
        }

        return redirect()->route('taller.show', $ticket->id);
    }

    public function agregarRepuesto(Request $request, TicketTaller $ticket)
    {
        $validated = $request->validate([
            'producto' => ['required', 'string', 'max:255'],
            'cantidad' => ['required', 'integer', 'min:1'],
            'precio' => ['required', 'numeric', 'min:0'],
        ]);

        $ticket->repuestos()->create($validated);

        return redirect()->route('taller.show', $ticket->id);
    }

    public function eliminarRepuesto(TicketTaller $ticket, TicketRepuesto $repuesto)
    {
        abort_unless($repuesto->ticket_id === $ticket->id, 404);

        $repuesto->delete();

        return redirect()->route('taller.show', $ticket->id);
    }

    public function marcarAtendido(TicketTaller $ticket)
    {
        if ($ticket->getMedia('salida')->isEmpty()) {
            return back()->withErrors(['fotos_salida' => 'Falta al menos 1 foto de salida para poder marcar el ticket como atendido.']);
        }

        $ticket->update(['estado' => 'atendido']);

        return redirect()->route('taller.show', $ticket->id);
    }
}
