<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Contracts\NetworkRiskCheck;
use App\Modules\Wallet\Contracts\Data\CheckoutRequest;
use App\Modules\Wallet\Contracts\Data\TopUpMethod;
use App\Modules\Wallet\Contracts\TopUpGateway;
use App\Modules\Wallet\Exceptions\WalletException;
use App\Modules\Wallet\Models\TopUp;
use App\Modules\Wallet\Support\TopUpPack;
use Illuminate\Support\Facades\DB;

use function filter_var;
use function in_array;
use function strlen;
use function trim;

/**
 * Spec "Top-up flow": the fan picks a pack and a method, and is sent to the gateway's
 * hosted checkout, so no card data touches our servers. Nothing is credited here: the
 * credits arrive with the gateway's signed webhook (ApplyGatewayEvent).
 *
 * The same idempotency key returns the same top-up and checkout (a double tap, a
 * reload). A frozen wallet (D-20) cannot top up, and neither can a VPN or proxy while
 * the D-16 rule for top-ups is on.
 */
final readonly class StartTopUp
{
    public function __construct(
        private TopUpGateway $gateway,
        private NetworkRiskCheck $network,
        private AuditLog $audit,
    ) {}

    /**
     * @param  bool|null  $knownDevice  started from a device on the account for more than 7 days (dispute evidence); null when unknown
     */
    public function handle(string $fanId, int $packIndex, TopUpMethod $method, string $idempotencyKey, string $ip, ?bool $knownDevice = null): TopUp
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 100) {
            throw new WalletException('Something went wrong. Reload the page and try again.', 'idempotency_key');
        }

        $existing = TopUp::query()->where('fan_id', $fanId)->where('idempotency_key', $idempotencyKey)->first();
        if ($existing instanceof TopUp) {
            return $existing;
        }

        $pack = TopUpPack::find($packIndex) ?? throw new WalletException('Choose a top-up amount.', 'pack');

        if (! in_array($method->value, config()->array('fanfolio.topup.methods'), true)) {
            throw new WalletException('That payment method is not available right now.', 'method');
        }

        if (DB::table('wallet_freezes')->where('fan_id', $fanId)->whereNull('lifted_at')->exists()) {
            throw new WalletException('Your wallet is on hold while we look into a payment. Contact support.', 'wallet');
        }

        if ($this->network->refuses('topups') && $this->network->isAnonymised($ip)) {
            throw new WalletException('Turn off your VPN or proxy to continue.', 'network');
        }

        $topUp = new TopUp;
        $topUp->forceFill([
            'fan_id' => $fanId,
            'idempotency_key' => $idempotencyKey,
            'gateway' => $this->gateway->name(),
            'method' => $method,
            'amount_minor' => $pack->price->amountMinor,
            'currency' => $pack->price->currency,
            'credits' => $pack->credits,
            'bonus_credits' => $pack->bonusCredits,
            // Evidence if the card holder later disputes it (canvas AdminDisputes).
            'ip' => filter_var($ip, FILTER_VALIDATE_IP) === false ? null : $ip,
            'known_device' => $knownDevice,
        ])->save();

        $session = $this->gateway->createCheckout(new CheckoutRequest(
            $topUp->id,
            $fanId,
            $pack->price,
            $method,
            route('wallet.topups.show', $topUp->id),
            route('wallet'),
        ));

        $topUp->forceFill(['gateway_ref' => $session->gatewayRef, 'checkout_url' => $session->redirectUrl])->save();

        $this->audit->record('wallet.topup_started', $fanId, 'topup', $topUp->id, [
            'amount_minor' => $pack->price->amountMinor,
            'method' => $method->value,
        ]);

        return $topUp;
    }
}
