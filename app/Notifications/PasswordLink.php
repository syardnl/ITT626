<?php

namespace App\Notifications;

use App\Enums\PasswordTokenPurpose;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a single-use password link. Queued so a mail failure never blocks the
 * request, and encrypted so the token is not stored in plain text in the jobs table.
 */
class PasswordLink extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly PasswordTokenPurpose $purpose,
        #[\SensitiveParameter] private readonly string $token,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * The token travels in the URL fragment, which browsers never send to the
     * server, so it cannot end up in web-server access logs or Referer headers.
     */
    public function url(): string
    {
        return rtrim(config('app.url'), '/').$this->purpose->spaPath().'#token='.$this->token;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return match ($this->purpose) {
            PasswordTokenPurpose::Reset => (new MailMessage)
                ->subject('Reset your password')
                ->greeting("Hello {$notifiable->name},")
                ->line('We received a request to reset the password for your account.')
                ->action('Reset password', $this->url())
                ->line("This link works once and expires in {$this->purpose->lifetimeLabel()}. If you did not ask for it, you can ignore this email."),
            PasswordTokenPurpose::SetPassword => (new MailMessage)
                ->subject('Set your password')
                ->greeting("Hello {$notifiable->name},")
                ->line('The management office has created an account for you.')
                ->action('Choose your password', $this->url())
                ->line("This link works once and expires in {$this->purpose->lifetimeLabel()}."),
        };
    }
}
