<?php

namespace App\Services\Locations;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A change that would leave the Location tree inconsistent; answered with 409. */
class LocationConflict extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
