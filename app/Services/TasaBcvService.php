<?php

namespace App\Services;

use App\Models\TasaBcv;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TasaBcvService
{
    private const CACHE_KEY = 'tasa_bcv_current';

    private const CACHE_TTL = 3600;

    private const MAX_RATE_CHANGE_WARN_PERCENT = 10.0;

    private const MAX_RATE_CHANGE_REJECT_PERCENT = 20.0;

    public function __construct(private TasaBcvFetcherService $fetcher) {}

    public function getCurrentRate(): float
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): float {
                $rate = TasaBcv::query()
                    ->where('is_active', true)
                    ->orderByDesc('effective_from')
                    ->first();

                if ($rate === null) {
                    $fallback = (float) env('TASA_BCV_FALLBACK_RATE', 40.00);
                    Log::info('TasaBcv source: env_fallback', ['rate' => $fallback]);

                    return $fallback;
                }

                Log::info('TasaBcv source: database', [
                    'rate' => $rate->rate,
                    'effective_from' => $rate->effective_from,
                ]);

                return (float) $rate->rate;
            });
        } catch (Throwable $e) {
            Log::warning('TasaBcvService: Failed to get current rate, using fallback', [
                'error' => $e->getMessage(),
            ]);

            return (float) env('TASA_BCV_FALLBACK_RATE', 40.00);
        }
    }

    /**
     * @return array{success: bool, rate?: float, message: string, source?: string}
     */
    public function fetchAndStore(): array
    {
        Log::info('TasaBcvService: Starting USD rate fetch');

        $fetched = $this->fetcher->fetchUSD();
        if (! $fetched['success']) {
            Log::error('TasaBcvService: All USD sources failed');

            return [
                'success' => false,
                'message' => 'Todas las fuentes fallaron. Se mantiene la ultima tasa registrada.',
            ];
        }

        $newRate = (float) $fetched['rate'];
        $sourceName = $fetched['source'];

        // ponytail: compara solo contra una tasa real previa, nunca contra el
        // fallback de getCurrentRate() (que inventaria un "cambio" falso en el primer fetch)
        $previousRow = TasaBcv::query()->where('is_active', true)->orderByDesc('effective_from')->first();
        $previousRate = $previousRow ? (float) $previousRow->rate : null;
        $changePercent = $previousRate !== null && $previousRate > 0
            ? abs(($newRate - $previousRate) / $previousRate) * 100
            : 0;

        if ($changePercent > self::MAX_RATE_CHANGE_WARN_PERCENT) {
            Log::warning('TasaBcvService: Unusual USD rate change detected', [
                'previous_rate' => $previousRate,
                'new_rate' => $newRate,
                'change_percent' => round($changePercent, 2),
                'source' => $sourceName,
            ]);
        }

        if ($changePercent > self::MAX_RATE_CHANGE_REJECT_PERCENT) {
            Log::critical('TasaBcv: rejected suspicious value', [
                'new' => $newRate,
                'current' => $previousRate,
            ]);

            return ['success' => false, 'message' => 'Cambio de tasa sospechoso (>20%), se rechazo.'];
        }

        try {
            $tasaBcv = DB::transaction(function () use ($newRate, $sourceName) {
                TasaBcv::query()
                    ->where('is_active', true)
                    ->update(['is_active' => false, 'effective_until' => Carbon::now()]);

                return TasaBcv::create([
                    'rate' => $newRate,
                    'source' => $sourceName,
                    'effective_from' => Carbon::now(),
                    'effective_until' => null,
                    'is_active' => true,
                ]);
            });

            Cache::forget(self::CACHE_KEY);

            Log::info('TasaBcvService: rate stored', [
                'rate' => $newRate,
                'source' => $sourceName,
                'rate_id' => $tasaBcv->id,
            ]);

            return [
                'success' => true,
                'rate' => $newRate,
                'source' => $sourceName,
                'message' => "Tasa BCV obtenida de {$sourceName}",
            ];
        } catch (Throwable $e) {
            Log::error('TasaBcvService: Failed to persist rate', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => "DB error: {$e->getMessage()}"];
        }
    }

    // Mismo flujo de persistencia que fetchAndStore() (desactiva la fila activa,
    // crea una nueva, limpia el cache) -- nunca escribe directo a la tabla. Un
    // admin corrigiendo la tasa a mano es una accion deliberada y confiable, asi
    // que se salta las guardas de "cambio sospechoso" pensadas para fuentes
    // automaticas no confiables.
    public function setManualRate(float $rate, string $adminName): array
    {
        try {
            $tasaBcv = DB::transaction(function () use ($rate, $adminName) {
                TasaBcv::query()
                    ->where('is_active', true)
                    ->update(['is_active' => false, 'effective_until' => Carbon::now()]);

                return TasaBcv::create([
                    'rate' => $rate,
                    'source' => 'manual: '.$adminName,
                    'effective_from' => Carbon::now(),
                    'effective_until' => null,
                    'is_active' => true,
                ]);
            });

            Cache::forget(self::CACHE_KEY);

            Log::info('TasaBcvService: manual rate set', [
                'rate' => $rate,
                'admin' => $adminName,
                'rate_id' => $tasaBcv->id,
            ]);

            return ['success' => true, 'rate' => $rate, 'message' => 'Tasa BCV actualizada manualmente'];
        } catch (Throwable $e) {
            Log::error('TasaBcvService: Failed to persist manual rate', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => "DB error: {$e->getMessage()}"];
        }
    }

    public function isStale(int $hoursThreshold = 26): bool
    {
        $row = TasaBcv::query()->orderByDesc('effective_from')->first();

        if ($row === null) {
            return true;
        }

        return $row->effective_from->lt(Carbon::now()->subHours($hoursThreshold));
    }

    /**
     * @return array{rate: float, source: string, fetched_at: string}|null
     */
    public function getLastUpdate(): ?array
    {
        $row = TasaBcv::query()->orderByDesc('effective_from')->first();

        if ($row === null) {
            return null;
        }

        return [
            'rate' => (float) $row->rate,
            'source' => $row->source,
            'fetched_at' => $row->effective_from->toDateTimeString(),
        ];
    }
}
