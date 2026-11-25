<?php

namespace App\Services\Accounts;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** An account change the current state does not allow; answered with 409. */
class AccountConflict extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
