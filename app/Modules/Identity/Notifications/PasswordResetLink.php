<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use App\Modules\Identity\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Forgot password?" email. Queued, so the reply takes the same time whether or not
 * the email has an account.
 */
final class PasswordResetLink extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $minutes = config()->integer('auth.passwords.users.expire');
        $url = route('password.reset', ['token' => $this->token, 'email' => $notifiable->email]);

        return (new MailMessage)
            ->subject('Reset your '.config()->string('fanfolio.brand.name').' password')
            ->line('Someone asked to reset the password for your account. If it was you, choose a new password here.')
            ->action('Choose a new password', $url)
            ->line("The link works for {$minutes} minutes. Resetting signs you out on every device.")
            ->line('If you did not ask for this, ignore this email. Your password stays the same.');
    }
}
