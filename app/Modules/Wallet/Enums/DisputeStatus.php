<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Enums;

enum DisputeStatus: string
{
    /** Received; waiting for an admin to send evidence or accept it. */
    case Open = 'open';
    /** Evidence sent; waiting for the card network's decision. */
    case EvidenceSent = 'evidence_sent';
    /** The network sided with us; what D-20 took back has been returned. */
    case Won = 'won';
    /** The network sided with the card holder. */
    case Lost = 'lost';
    /** We did not contest it: the card holder keeps the refund. */
    case Accepted = 'accepted';

    /** Open disputes still need us or the network. */
    public const array UNDECIDED = [self::Open, self::EvidenceSent];

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::EvidenceSent => 'Evidence sent',
            self::Won => 'Won',
            self::Lost => 'Lost',
            self::Accepted => 'Accepted',
        };
    }

    public function isDecided(): bool
    {
        return ! in_array($this, self::UNDECIDED, true);
    }
}
