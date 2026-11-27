<?php

namespace App\Services\Complaints;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A workflow action the Complaint's current state no longer allows, or one made on a stale copy; answered with 409. */
class WorkflowConflict extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
