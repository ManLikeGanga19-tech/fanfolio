<?php

declare(strict_types=1);

namespace App\Modules\Creators\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Plan M8a use cases 11 and 24: the creator hears that their page was suspended, paused
 * or reinstated, with the reason category only, never the staff note. Sent through
 * Identity\Contracts\Accounts::notify. Dates are UTC in the email (no browser zone here).
 */
final class CreatorStatusNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const string SUSPENDED = 'suspended';

    public const string REINSTATED = 'reinstated';

    private function __construct(
        public readonly string $kind,
        public readonly ?string $category = null,
        public readonly ?CarbonImmutable $until = null,
    ) {}

    public static function suspended(?string $category, ?CarbonImmutable $until): self
    {
        return new self(self::SUSPENDED, $category, $until);
    }

    public static function reinstated(): self
    {
        return new self(self::REINSTATED);
    }

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

        if ($this->kind === self::REINSTATED) {
            return $mail
                ->subject("Your {$brand} creator page is active again")
                ->line('You can post and earn again.')
                ->action('Open Studio', url('/studio'));
        }

        $mail->subject("Your {$brand} creator page is paused")
            ->line($this->until instanceof CarbonImmutable
                ? 'Your creator page is paused until '.$this->until->utc()->format('j M Y, H:i').' UTC. You cannot post or earn until then.'
                : 'Your creator page is suspended. You cannot post or earn while we review it.');

        if ($this->category !== null) {
            $mail->line('Reason: '.$this->category.'.');
        }

        return $mail->line('If you think this is a mistake, contact support.');
    }
}
