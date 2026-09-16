<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Prospecto200k extends Model
{
    protected $table = 'prospectos_200k';

    protected $fillable = [
        'nombre',
        'ci',
        'telefono',
        'correo',
        'lote',
        'estado',
        'cliente_id',
    ];

    private const UMBRAL_SIMILITUD_NOMBRE = 85.0;

    public function descartes()
    {
        return $this->hasMany(ProspectoDescarte::class, 'prospecto_id');
    }

    // mismo criterio E.164 que clientes.telefono (ver migracion
    // normalize_clientes_telefono_a_e164) -- sin esto el match de Nivel 2
    // nunca compara igual aunque sea el mismo numero
    public static function normalizarTelefono(?string $telefono): ?string
    {
        if (! $telefono) {
            return null;
        }

        $digitos = preg_replace('/\D/', '', $telefono);
        if ($digitos === '') {
            return null;
        }

        if (str_starts_with($digitos, '0')) {
            $digitos = '58'.substr($digitos, 1);
        } elseif (! str_starts_with($digitos, '58') && strlen($digitos) === 10) {
            $digitos = '58'.$digitos;
        }

        return '+'.$digitos;
    }

    // clientes.cedula solo guarda digitos (ver Cliente::validarFormatoCedula) --
    // el prefijo de nacionalidad V-/E- NO se conserva, se descarta junto con
    // puntos/comas/espacios: mismo criterio que ya aplica esa validacion (la
    // cedula se identifica solo por sus digitos en todo Kredix, nunca por
    // nacionalidad), asi que "V-12345678" y "E-12345678" normalizan igual
    public static function normalizarCi(?string $ci): ?string
    {
        if (! $ci) {
            return null;
        }

        $digitos = preg_replace('/\D/', '', $ci);

        return $digitos !== '' ? $digitos : null;
    }

    // Nivel 1 CI, Nivel 2 telefono, Nivel 3 similitud de nombre (umbral 85%,
    // similar_text() nativo de PHP) -- para en el primer nivel con resultado.
    // ponytail: scan lineal en Nivel 3 sobre prospectos pendientes, correcto
    // mientras la tabla se mida en miles (caso actual: ~690); si el acumulado de
    // eventos crece a decenas de miles, mover a busqueda por indice de trigramas.
    public static function buscarMatchParaCliente(Cliente $cliente): ?self
    {
        $base = static::where('estado', 'pendiente')
            ->whereDoesntHave('descartes', fn ($q) => $q->where('cliente_id', $cliente->id));

        // clientes.cedula puede traer formato legacy sin normalizar (importados
        // via app:importar-lote-clientes, CSV crudo) -- ej "8.390.140" -- se
        // normaliza aqui igual que el dato del prospecto, no solo al importar
        $ciCliente = self::normalizarCi($cliente->cedula);
        if ($ciCliente) {
            $match = (clone $base)->where('ci', $ciCliente)->first();
            if ($match) {
                return $match;
            }
        }

        if ($cliente->telefono) {
            $match = (clone $base)->where('telefono', $cliente->telefono)->first();
            if ($match) {
                return $match;
            }
        }

        $nombreCliente = mb_strtoupper(trim($cliente->nombre), 'UTF-8');
        $mejor = null;
        $mejorPct = 0.0;

        foreach ((clone $base)->get() as $candidato) {
            similar_text($nombreCliente, mb_strtoupper(trim($candidato->nombre), 'UTF-8'), $pct);
            if ($pct > $mejorPct) {
                $mejorPct = $pct;
                $mejor = $candidato;
            }
        }

        return ($mejor && $mejorPct >= self::UMBRAL_SIMILITUD_NOMBRE) ? $mejor : null;
    }
}
