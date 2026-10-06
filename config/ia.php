<?php

return [
    // Interruptor general: apagado no se encola ningún job de IA.
    'activa' => (bool) env('IA_ACTIVA', false),
    // Trato al cliente en los textos redactados por IA: tu | usted (GUIA_VOZ_ONBIKE.md)
    'trato' => env('IA_TRATO', 'tu'),
    'base_url' => env('NVIDIA_BASE_URL', 'https://integrate.api.nvidia.com/v1'),
    'api_key' => env('NVIDIA_API_KEY'),
    'modelo_texto' => env('NVIDIA_MODELO_TEXTO'),
    'timeout' => (int) env('NVIDIA_TIMEOUT', 30),
];
