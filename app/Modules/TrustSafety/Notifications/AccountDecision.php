<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Notifications;

use App\Modules\TrustSafety\Contracts\RestrictionReason;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Plan M8a use case 24: the user hears the outcome and the reason category, never the
 * staff note. Sent through Identity\Contracts\Accounts::notify, so this module never
 * sees the email address.
 */
final class AccountDecision extends Notification implements ShouldQueue
{
    use Queueable;

    public const string SUSPENDED = 'suspended';

    public const string BANNED = 'banned';

    public const string LIFTED = 'lifted';

    public const string STRIKE = 'strike';

    /**
     * @param  self::SUSPENDED|self::BANNED|self::LIFTED|self::STRIKE  $kind
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?RestrictionReason $reason = null,
        public readonly ?CarbonImmutable $until = null,
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
        $why = $this->reason instanceof RestrictionReason ? 'Reason: '.$this->reason->label().'.' : null;
        $mail = new MailMessage;

        switch ($this->kind) {
            case self::SUSPENDED:
                // D-15: in the user's own time zone, named.
                $zone = $notifiable instanceof Model ? $notifiable->getAttribute('timezone') : null;
                $until = $this->until?->setTimezone(is_string($zone) && $zone !== '' ? $zone : config()->string('app.timezone'))->format('j M Y, H:i T') ?? '';
                $mail->subject("Your {$brand} account is suspended")
                    ->line("We have suspended your account until {$until}. You cannot sign in until then.");
                break;
            case self::BANNED:
                $mail->subject("Your {$brand} account is closed")
                    ->line('We have closed your account for breaking our rules. You can no longer sign in.');
                break;
            case self::LIFTED:
                $mail->subject("Your {$brand} account is open again")
                    ->line('We have lifted the restriction on your account. You can sign in again.');
                break;
            default:
                $mail->subject("A warning on your {$brand} account")
                    ->line('We have given your account a strike for breaking our rules. Repeated strikes can lead to the account being closed.');
        }

        if ($why !== null && $this->kind !== self::LIFTED) {
            $mail->line($why);
        }

        return $mail->line('If you think this is a mistake, reply to this email or contact '.config()->string('fanfolio.brand.support_email').'.');
    }
}
