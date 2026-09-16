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
        'procesado',
        'cliente_id',
    ];

    protected $casts = [
        'procesado' => 'boolean',
    ];

    private const UMBRAL_SIMILITUD_NOMBRE = 85.0;

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

    // clientes.cedula solo guarda digitos (ver Cliente::validarFormatoCedula),
    // el excel de eventos trae "V-12345678", puntos, espacios -- se limpia igual
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
    // ponytail: scan lineal en Nivel 3 sobre prospectos no procesados, correcto
    // mientras la tabla se mida en miles (caso actual: ~722); si el acumulado de
    // eventos crece a decenas de miles, mover a busqueda por indice de trigramas.
    public static function buscarMatchParaCliente(Cliente $cliente): ?self
    {
        $base = static::where('procesado', false);

        if ($cliente->cedula) {
            $match = (clone $base)->where('ci', $cliente->cedula)->first();
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
