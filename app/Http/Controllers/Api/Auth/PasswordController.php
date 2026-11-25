<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\PasswordTokenPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Services\Auth\PasswordLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    public function __construct(private readonly PasswordLinks $links) {}

    /** Same answer for every email, so the form cannot reveal which emails have accounts. */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->string('email'))->where('active', true)->first();

        if ($user) {
            $this->links->send($user, PasswordTokenPurpose::Reset);
        }

        return response()->json([
            'message' => 'If an account exists for that email, we have sent a link to reset its password.',
        ], 202);
    }

    /** Completes both "forgot password" resets and new-account set-password links. */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        if (! $this->links->redeem($request->string('token'), $request->string('password'))) {
            throw ValidationException::withMessages([
                'token' => 'This link is invalid or has expired. Request a new one.',
            ]);
        }

        return response()->json(['message' => 'Your password has been set. You can now sign in.']);
    }
}
