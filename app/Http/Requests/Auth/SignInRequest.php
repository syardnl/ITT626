<?php

namespace App\Http\Requests\Auth;

use App\Enums\AuditAction;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SignInRequest extends FormRequest
{
    /** Failed sign-ins allowed per minute, separately for each account and each IP address. */
    private const MAX_FAILURES = 5;

    private const DECAY_SECONDS = 60;

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Sign in an active account on the session guard, or fail with one generic
     * message so the form cannot reveal which emails have accounts.
     *
     * @throws ValidationException|ThrottleRequestsException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotThrottled();

        if (! Auth::guard('web')->attempt([...$this->only('email', 'password'), 'active' => true])) {
            foreach ($this->throttleKeys() as $key) {
                RateLimiter::hit($key, self::DECAY_SECONDS);
            }

            $this->recordFailure();

            throw ValidationException::withMessages(['email' => 'These credentials are invalid.']);
        }

        app(AuditTrail::class)->record(AuditAction::SignedIn, $this->user(), actor: $this->user());

        // Only the account's counter resets; the IP counter keeps running so one
        // good account cannot be used to keep guessing at others.
        RateLimiter::clear($this->throttleKeys()['account']);
    }

    private function ensureIsNotThrottled(): void
    {
        foreach ($this->throttleKeys() as $key) {
            if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
                $this->recordFailure('throttled');
                $seconds = RateLimiter::availableIn($key);

                throw new ThrottleRequestsException(
                    "Too many sign-in attempts. Try again in {$seconds} seconds.",
                    headers: ['Retry-After' => $seconds],
                );
            }
        }
    }

    /** Malformed attempts (missing or invalid email/password) are failed sign-ins too. */
    protected function failedValidation(Validator $validator): void
    {
        $this->recordFailure('invalid_request');

        parent::failedValidation($validator);
    }

    /** Audit a failed attempt: the email as typed (if any) and the account it names, never the password. */
    private function recordFailure(?string $reason = null): void
    {
        $email = is_string($this->input('email')) && $this->input('email') !== '' ? $this->input('email') : null;

        app(AuditTrail::class)->record(
            AuditAction::SignInFailed,
            $email ? User::query()->where('email', $email)->first() : null,
            metadata: array_filter(['email' => $email, 'reason' => $reason]),
        );
    }

    /** @return array{account: string, ip: string} */
    private function throttleKeys(): array
    {
        return [
            'account' => 'sign-in:account:'.Str::lower($this->string('email')),
            'ip' => 'sign-in:ip:'.$this->ip(),
        ];
    }
}
