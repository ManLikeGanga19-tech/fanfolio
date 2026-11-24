<?php

declare(strict_types=1);

namespace App\Modules\Creators\Notifications;

use App\Modules\Creators\Enums\FixKind;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Board 7: "We'll notify you ... as soon as you're approved." Sent through
 * Identity\Contracts\Accounts::notify, so this module never sees the email address.
 * Email only until the Notifications module adds push.
 */
final class ApplicationDecision extends Notification implements ShouldQueue
{
    use Queueable;

    public const string APPROVED = 'approved';

    public const string FIX = 'fix';

    public const string REJECTED = 'rejected';

    /**
     * @param  string|null  $message  replaces the fix kind's standard message (no personal data).
     */
    public function __construct(
        public readonly string $decision,
        public readonly ?FixKind $fix = null,
        public readonly ?string $message = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = config()->string('fanfolio.brand.name');
        $mail = new MailMessage;

        return match ($this->decision) {
            self::APPROVED => $mail
                ->subject("You're approved on {$brand}")
                ->line('Your creator account is approved. You can start posting and earning.')
                ->action('Open Studio', url('/studio')),
            self::FIX => $mail
                ->subject("One thing to fix on your {$brand} application")
                ->line($this->message ?? ($this->fix ?? FixKind::Profile)->message())
                ->action(($this->fix ?? FixKind::Profile)->label(), route('creator.status')),
            default => $mail
                ->subject("Your {$brand} creator application")
                ->line('We could not approve your creator account.')
                ->line('If you think this is a mistake, contact support.'),
        };
    }
}
