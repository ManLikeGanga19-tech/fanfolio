<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Audit\Contracts\AuditHistory;
use App\Modules\Wallet\Contracts\Data\TopUpMethod;
use App\Modules\Wallet\Models\Dispute;
use App\Modules\Wallet\Models\TopUp;
use Illuminate\Support\Facades\DB;

use function number_format;

/**
 * Canvas AdminDisputes "Evidence to send", gathered from our own records: 3-D Secure as
 * the processor reported it, the top-up's IP against the fan's earlier sign-ins, whether
 * the device was already known, the credits spent after the top-up (the unlock log) and
 * the statement descriptor shown at checkout. Each line has a tone for the board; only
 * the text is sent to the processor.
 */
final readonly class DisputeEvidence
{
    public function __construct(private AuditHistory $history) {}

    /**
     * @return list<array{text: string, tone: 'ok'|'warning'|'danger'|'muted'}>
     */
    public function lines(Dispute $dispute): array
    {
        $topUp = TopUp::query()->findOrFail($dispute->topup_id);

        return [
            $this->threeDs($dispute, $topUp),
            $this->ip($topUp),
            match ($topUp->known_device) {
                true => ['text' => 'Paid from a device on the account for more than 7 days', 'tone' => 'ok'],
                false => ['text' => 'Paid from a device added in the last 7 days', 'tone' => 'warning'],
                null => ['text' => 'No device recorded for this top-up', 'tone' => 'muted'],
            },
            $this->spent($dispute, $topUp),
            ['text' => 'Statement descriptor '.config()->string('fanfolio.brand.statement_descriptor').' shown at checkout', 'tone' => 'ok'],
        ];
    }

    /** @return array{text: string, tone: 'ok'|'warning'|'danger'|'muted'} */
    private function threeDs(Dispute $dispute, TopUp $topUp): array
    {
        if ($topUp->method === TopUpMethod::Crypto) {
            return ['text' => 'Paid in USDT: no card was used', 'tone' => 'muted'];
        }

        return match ($dispute->three_ds) {
            'passed' => ['text' => '3-D Secure authentication passed', 'tone' => 'ok'],
            'failed' => ['text' => '3-D Secure authentication failed', 'tone' => 'danger'],
            'not_used' => ['text' => '3-D Secure was not used for this payment', 'tone' => 'warning'],
            default => ['text' => 'The processor did not report 3-D Secure', 'tone' => 'muted'],
        };
    }

    /** @return array{text: string, tone: 'ok'|'warning'|'danger'|'muted'} */
    private function ip(TopUp $topUp): array
    {
        if ($topUp->ip === null) {
            return ['text' => 'No IP address recorded for this top-up', 'tone' => 'muted'];
        }

        $signIns = $this->history->countFromIp('identity.login', $topUp->fan_id, $topUp->ip, $topUp->created_at);

        return $signIns === 0
            ? ['text' => 'IP address not seen at any earlier sign-in', 'tone' => 'warning']
            : ['text' => 'Same IP address as '.number_format($signIns).' earlier '.($signIns === 1 ? 'sign-in' : 'sign-ins'), 'tone' => 'ok'];
    }

    /** @return array{text: string, tone: 'ok'|'warning'|'danger'|'muted'} */
    private function spent(Dispute $dispute, TopUp $topUp): array
    {
        // Every sale in the unlock log between the top-up and the dispute, clawed back or not.
        $spent = (int) DB::table('earning_holds')
            ->where('fan_id', $dispute->fan_id)
            ->where('created_at', '>=', $topUp->paid_at)
            ->where('created_at', '<=', $dispute->received_at)
            ->sum('gross_minor');

        return $spent === 0
            ? ['text' => 'No credits spent after the top-up', 'tone' => 'muted']
            : ['text' => number_format($spent).' credits spent after the top-up (unlock log)', 'tone' => 'ok'];
    }
}
