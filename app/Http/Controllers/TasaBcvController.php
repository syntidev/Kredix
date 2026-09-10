<?php

namespace App\Http\Controllers;

use App\Services\TasaBcvService;
use Illuminate\Http\Request;

class TasaBcvController extends Controller
{
    public function update(Request $request, TasaBcvService $tasaBcvService)
    {
        $validated = $request->validate([
            'rate' => ['required', 'numeric', 'min:0.0001', 'max:100000'],
        ], [
            'rate.required' => 'tasa requerida',
            'rate.numeric' => 'tasa invalida',
        ]);

        $tasaBcvService->setManualRate((float) $validated['rate'], $request->user()->name);

        return redirect()->back();
    }
}
