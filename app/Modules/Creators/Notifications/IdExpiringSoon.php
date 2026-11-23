<?php

declare(strict_types=1);

namespace App\Modules\Creators\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "We'll remind you 30 days before" (Studio, Verification & tax). */
final class IdExpiringSoon extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly CarbonImmutable $expiresAt) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $date = $this->expiresAt->format('j M Y');

        return (new MailMessage)
            ->subject('Your ID expires on '.$date)
            ->line("The ID you verified with expires on {$date}. After that you cannot publish or earn until you verify a new one.")
            ->action('Verify a new ID', route('creator.kyc'))
            ->line('It takes about 5 minutes.');
    }
}
