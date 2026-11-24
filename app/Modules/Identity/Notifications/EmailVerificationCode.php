<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use App\Modules\Identity\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sign-up step 2: the one-time code for "Verify your email". */
final class EmailVerificationCode extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $code, public readonly int $minutes) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $brand = config()->string('fanfolio.brand.name');

        return (new MailMessage)
            ->subject("Your {$brand} code: {$this->code}")
            ->line("Your {$brand} code is **{$this->code}**.")
            ->line("It expires in {$this->minutes} minutes. Never share it: our team will never ask for it.")
            ->line('If you did not sign up, ignore this email.');
    }
}
