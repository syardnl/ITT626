<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): AccountResource
    {
        return new AccountResource($request->user());
    }
}
