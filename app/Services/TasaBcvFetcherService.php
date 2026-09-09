<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TasaBcvFetcherService
{
    private const TIMEOUT = 8;

    private const CONNECT_TIMEOUT = 5;

    /**
     * Fetch USD (BCV oficial) from all available sources in priority order.
     *
     * @return array{success: bool, rate: float|null, source: string}
     */
    public function fetchUSD(): array
    {
        try {
            $r = Http::timeout(self::TIMEOUT)
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->acceptJson()
                ->get('https://ve.dolarapi.com/v1/dolares/oficial');

            if ($r->successful()) {
                $rate = (float) ($r->json('promedio') ?? 0);
                if ($rate > 10 && $rate < 10000) {
                    Log::info('[TasaBcvFetcher] USD OK via dolarapi', ['rate' => $rate]);

                    return ['success' => true, 'rate' => $rate, 'source' => 'dolarapi'];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[TasaBcvFetcher] USD FAIL dolarapi: '.$e->getMessage());
        }

        try {
            $r = Http::timeout(self::TIMEOUT)
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->acceptJson()
                ->get('https://brecha-cambiaria.com/api/prices');

            if ($r->successful()) {
                $rate = (float) ($r->json('bcv_usd') ?? 0);
                if ($rate > 10 && $rate < 10000) {
                    Log::info('[TasaBcvFetcher] USD OK via brecha-cambiaria', ['rate' => $rate]);

                    return ['success' => true, 'rate' => $rate, 'source' => 'brecha-cambiaria'];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[TasaBcvFetcher] USD FAIL brecha-cambiaria: '.$e->getMessage());
        }

        Log::error('[TasaBcvFetcher] USD FAIL todas las fuentes — se requiere tasa manual');

        return ['success' => false, 'rate' => null, 'source' => 'all_failed'];
    }
}
