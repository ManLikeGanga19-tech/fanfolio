<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Notifications;

use App\Modules\Wallet\Actions\WalletDisplayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a creator what happened to a withdrawal. Sent through Identity's Accounts::notify,
 * so Wallet never sees the email address. Never includes the staff note.
 */
final class PayoutUpdate extends Notification implements ShouldQueue
{
    use Queueable;

    public const string SENT = 'sent';

    public const string FAILED = 'failed';

    public const string HELD = 'held';

    public const string DECLINED = 'declined';

    public function __construct(
        public readonly string $kind,
        public readonly int $amountMinor,
        public readonly string $destination,
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
        $amount = WalletDisplayService::dollars($this->amountMinor);
        $mail = new MailMessage;

        return match ($this->kind) {
            self::SENT => $mail->subject("Your {$brand} withdrawal is on its way")
                ->line("We sent {$amount} to {$this->destination}."),
            self::FAILED => $mail->subject("Your {$brand} withdrawal did not go through")
                ->line("The payout of {$amount} to {$this->destination} was refused by the provider. The money is back in your available earnings.")
                ->line('Check your payout details in Studio, then try again.'),
            self::HELD => $mail->subject("We need a little more information about your {$brand} withdrawal")
                ->line("Your withdrawal of {$amount} is on hold while we check a few details. The money stays reserved for you.")
                ->line('Reply to this email or contact '.config()->string('fanfolio.brand.support_email').'.'),
            default => $mail->subject("Your {$brand} withdrawal was not approved")
                ->line("We could not approve your withdrawal of {$amount}. The money is back in your available earnings.")
                ->line('Contact '.config()->string('fanfolio.brand.support_email').' if you have questions.'),
        };
    }
}
