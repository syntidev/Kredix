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
            've.dolarapi.com/*' => Http::response(['promedio' => 200.5]),
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
            've.dolarapi.com/*' => Http::response(['promedio' => 500]),
        ]);

        $result = app(TasaBcvService::class)->fetchAndStore();

        $this->assertFalse($result['success']);
        $this->assertDatabaseHas('tasas_bcv', ['rate' => 200.0000, 'is_active' => 1]);
        $this->assertDatabaseMissing('tasas_bcv', ['rate' => 500.0000]);
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
