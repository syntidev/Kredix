<?php

namespace Tests\Feature;

use App\Models\TasaBcv;
use App\Services\TasaBcvService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TasaBcvServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fetch_and_store_saves_rate_from_dolarapi(): void
    {
        Http::fake([
            've.dolarapi.com/*' => Http::response(['promedio' => 200.5, 'fechaActualizacion' => now('America/Caracas')->toIso8601String()]),
        ]);

        $result = app(TasaBcvService::class)->fetchAndStore();

        $this->assertTrue($result['success']);
        $this->assertSame('dolarapi', $result['source']);
        $this->assertDatabaseHas('tasas_bcv', ['rate' => 200.5000, 'source' => 'dolarapi', 'is_active' => 1]);
    }

    public function test_fetch_and_store_rejects_change_over_20_percent_and_keeps_previous(): void
    {
        TasaBcv::create(['rate' => 200, 'source' => 'dolarapi', 'effective_from' => now(), 'is_active' => true]);

        Http::fake([
            've.dolarapi.com/*' => Http::response(['promedio' => 500, 'fechaActualizacion' => now('America/Caracas')->toIso8601String()]),
        ]);

        $result = app(TasaBcvService::class)->fetchAndStore();

        $this->assertFalse($result['success']);
        $this->assertSame('Cambio de tasa sospechoso (>20%), se rechazo.', $result['message']);
        $this->assertDatabaseHas('tasas_bcv', ['rate' => 200.0000, 'is_active' => 1]);
        $this->assertDatabaseMissing('tasas_bcv', ['rate' => 500.0000]);
    }

    public function test_fetch_and_store_accepts_lower_rate_when_within_20_percent(): void
    {
        // baja del dolar (nunca sube) -- confirma que la validacion de fecha es
        // independiente de la direccion del cambio, solo valida staleness
        TasaBcv::create(['rate' => 200, 'source' => 'dolarapi', 'effective_from' => now(), 'is_active' => true]);

        Http::fake([
            've.dolarapi.com/*' => Http::response(['promedio' => 190, 'fechaActualizacion' => now('America/Caracas')->toIso8601String()]),
        ]);

        $result = app(TasaBcvService::class)->fetchAndStore();

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('tasas_bcv', ['rate' => 190.0000, 'source' => 'dolarapi', 'is_active' => 1]);
    }

    public function test_fetch_and_store_rejects_stale_date_and_falls_through_to_next_source(): void
    {
        Http::fake([
            've.dolarapi.com/*' => Http::response(['promedio' => 200.5, 'fechaActualizacion' => now('America/Caracas')->subDay()->toIso8601String()]),
            'brecha-cambiaria.com/*' => Http::response(['bcv_usd' => 199.9, 'timestamp' => now('UTC')->toIso8601String()]),
        ]);

        $result = app(TasaBcvService::class)->fetchAndStore();

        $this->assertTrue($result['success']);
        $this->assertSame('brecha-cambiaria', $result['source']);
        $this->assertDatabaseHas('tasas_bcv', ['rate' => 199.9000, 'source' => 'brecha-cambiaria', 'is_active' => 1]);
        $this->assertDatabaseMissing('tasas_bcv', ['rate' => 200.5000]);
    }

    public function test_fetch_and_store_fails_when_both_sources_have_stale_dates(): void
    {
        TasaBcv::create(['rate' => 200, 'source' => 'dolarapi', 'effective_from' => now(), 'is_active' => true]);

        Http::fake([
            've.dolarapi.com/*' => Http::response(['promedio' => 205, 'fechaActualizacion' => now('America/Caracas')->subDay()->toIso8601String()]),
            'brecha-cambiaria.com/*' => Http::response(['bcv_usd' => 206, 'timestamp' => now('UTC')->subDay()->toIso8601String()]),
        ]);

        $result = app(TasaBcvService::class)->fetchAndStore();

        $this->assertFalse($result['success']);
        $this->assertDatabaseHas('tasas_bcv', ['rate' => 200.0000, 'is_active' => 1]);
        $this->assertDatabaseMissing('tasas_bcv', ['rate' => 205.0000]);
        $this->assertDatabaseMissing('tasas_bcv', ['rate' => 206.0000]);
    }

    public function test_fetch_and_store_keeps_previous_rate_when_both_sources_fail(): void
    {
        TasaBcv::create(['rate' => 200, 'source' => 'dolarapi', 'effective_from' => now(), 'is_active' => true]);

        Http::fake([
            've.dolarapi.com/*' => Http::response([], 500),
            'brecha-cambiaria.com/*' => Http::response([], 500),
        ]);

        $result = app(TasaBcvService::class)->fetchAndStore();

        $this->assertFalse($result['success']);
        $this->assertDatabaseHas('tasas_bcv', ['rate' => 200.0000, 'is_active' => 1]);
    }
}
