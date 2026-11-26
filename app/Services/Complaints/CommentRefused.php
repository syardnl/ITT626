<?php

namespace App\Services\Complaints;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A comment the Complaint's current state does not allow; answered with 409. */
class CommentRefused extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
