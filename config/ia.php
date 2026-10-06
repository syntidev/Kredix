<?php

return [
    'base_url' => env('NVIDIA_BASE_URL', 'https://integrate.api.nvidia.com/v1'),
    'api_key' => env('NVIDIA_API_KEY'),
    'modelo_texto' => env('NVIDIA_MODELO_TEXTO'),
    'timeout' => (int) env('NVIDIA_TIMEOUT', 30),
];
