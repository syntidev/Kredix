<?php

namespace App\Services\Ia;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

// Cliente OpenAI-compatible para NVIDIA. Los errores se re-lanzan como RuntimeException
// con mensaje propio: nunca se propaga el request (headers con la key) a logs ni pantalla.
class ClienteIa
{
    /**
     * $opciones van al body tal cual, salvo 'timeout' (segundos, no se envía).
     *
     * @return array{texto: string, modelo: string, latencia_ms: int}
     */
    public function chat(array $mensajes, array $opciones = []): array
    {
        $timeout = (int) Arr::pull($opciones, 'timeout', config('ia.timeout'));
        $modelo = $opciones['model'] ?? config('ia.modelo_texto');
        if (! $modelo) {
            throw new RuntimeException('Falta el modelo: define NVIDIA_MODELO_TEXTO o pasa --modelo.');
        }

        $inicio = hrtime(true);
        $r = $this->enviar(fn (PendingRequest $h) => $h->timeout($timeout)->post('/chat/completions', [
            'model' => $modelo,
            'messages' => $mensajes,
            // Razonamiento apagado: cada familia usa su propia bandera; las que no la conocen la ignoran.
            'chat_template_kwargs' => ['thinking' => false, 'enable_thinking' => false],
            ...$opciones,
        ]));

        return [
            'texto' => (string) $r->json('choices.0.message.content'),
            'modelo' => (string) $r->json('model', $modelo),
            'latencia_ms' => intdiv(hrtime(true) - $inicio, 1_000_000),
        ];
    }

    /** @return list<string> */
    public function modelos(): array
    {
        return collect($this->enviar(fn (PendingRequest $h) => $h->get('/models'))->json('data', []))
            ->pluck('id')->sort()->values()->all();
    }

    /**
     * Algunos modelos envuelven el JSON en ```json``` o dejan basura alrededor: se toma del primer { al último }.
     *
     * @throws JsonException
     */
    public static function extraerJson(string $texto): array
    {
        preg_match('/\{.*\}/s', $texto, $m);
        $datos = json_decode($m[0] ?? '', true, 16, JSON_THROW_ON_ERROR);

        return is_array($datos) ? $datos : throw new JsonException('La respuesta no es un objeto JSON');
    }

    private function enviar(callable $llamada): Response
    {
        $key = config('ia.api_key');
        if (! $key) {
            throw new RuntimeException('Falta NVIDIA_API_KEY en .env.');
        }

        try {
            $r = $llamada(Http::baseUrl(rtrim(config('ia.base_url'), '/'))
                ->withToken($key)->acceptJson()->timeout(config('ia.timeout')));
        } catch (ConnectionException $e) {
            throw new RuntimeException('Sin conexión con la API de IA: '.str_replace($key, '***', $e->getMessage()));
        }

        if ($r->failed()) {
            throw new RuntimeException("API de IA respondió HTTP {$r->status()}: ".Str::limit(str_replace($key, '***', $r->body()), 300));
        }

        return $r;
    }
}
