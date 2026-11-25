<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Filament\Studio;

use App\Modules\Creators\Contracts\Data\PayoutDestination;
use App\Modules\Creators\Contracts\PayoutDestinations;
use App\Modules\Wallet\Actions\RequestPayout;
use App\Modules\Wallet\Actions\WalletDisplayService;
use App\Modules\Wallet\Contracts\Data\AccountRef;
use App\Modules\Wallet\Contracts\Data\AccountType;
use App\Modules\Wallet\Contracts\Exceptions\LedgerException;
use App\Modules\Wallet\Contracts\Ledger;
use App\Modules\Wallet\Enums\PayoutState;
use App\Modules\Wallet\Exceptions\WalletException;
use App\Modules\Wallet\Models\Payout;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Canvas StudioEarnings (M3 PR 4): available, pending with the next release, paid out
 * all time; the withdrawal form; the history. "Paid to collaborators" arrives with
 * collaboration splits (M7). Amounts are integer cents; the form takes dollars.
 */
final class EarningsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Earnings';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Earnings & withdrawals';

    protected static ?string $slug = 'earnings';

    protected string $view = 'wallet::studio.earnings';

    public string $amount = '';

    public string $method = '';

    /** The withdrawal waiting for the second-factor step, in the session. */
    public const string DRAFT_KEY = 'wallet.payout_draft';

    /** One per form: a double click or a resend asks for one withdrawal. */
    public string $requestKey = '';

    public function mount(RequestPayout $request): void
    {
        $this->requestKey = (string) Str::ulid();
        $this->method = $this->destinations()[0]->id ?? '';

        // Back from the second-factor step (D-13): finish the withdrawal the creator asked
        // for, with the same key, so they do not type it again and nothing is asked twice.
        // Pulled, so a failed confirmation never loops.
        $draft = app(Session::class)->pull(self::DRAFT_KEY);
        if (is_array($draft) && is_string($draft['amount'] ?? null) && is_string($draft['method'] ?? null) && is_string($draft['key'] ?? null)) {
            $this->amount = $draft['amount'];
            $this->method = $draft['method'];
            $this->requestKey = $draft['key'];
            $this->requestWithdrawal($request);
        }
    }

    public function getSubheading(): string
    {
        return 'Earnings unlock '.config()->integer('fanfolio.money.earnings_hold_days').' days after each sale.';
    }

    public function fillMax(): void
    {
        $this->amount = $this->dollarsInput(max(0, $this->available()));
    }

    public function requestWithdrawal(RequestPayout $request): void
    {
        $this->resetErrorBag();
        $cents = self::toCents($this->amount);

        if ($cents === null) {
            $this->addError('amount', 'Enter an amount in dollars, for example 250 or 250.50.');

            return;
        }

        try {
            $payout = $request->handle($this->creatorId(), $this->method, $cents, $this->requestKey, app(Session::class), (string) request()->ip());
        } catch (WalletException $e) {
            if ($e->field === 'confirm') {
                // D-13: confirm with the passkey or authenticator app, then come straight back
                // and finish this request (kept server-side, never in the URL).
                app(Session::class)->put(self::DRAFT_KEY, ['amount' => $this->amount, 'method' => $this->method, 'key' => $this->requestKey]);
                $this->redirectRoute('wallet.payouts.confirm');

                return;
            }

            $this->addError($e->field === 'network' || $e->field === 'account' ? 'amount' : $e->field, $e->getMessage());

            return;
        } catch (LedgerException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        $this->amount = '';
        $this->requestKey = (string) Str::ulid();

        Notification::make()
            ->title($payout->status === PayoutState::Review ? 'Withdrawal requested. We review it within 24 hours.' : 'Withdrawal requested. It is on its way.')
            ->success()
            ->send();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $creator = $this->creatorId();
        $ledger = app(Ledger::class);
        $nextRelease = DB::table('earning_holds')->where('creator_id', $creator)->whereNull('released_at')->whereNull('refunded_at')->min('release_at');
        $firstSent = Payout::query()->where('creator_id', $creator)->where('status', PayoutState::Sent)->min('sent_at');

        return [
            'available' => $this->available(),
            'pending' => $ledger->balance(AccountRef::of(AccountType::CreatorPending, $creator))->amountMinor,
            'nextRelease' => is_string($nextRelease) ? CarbonImmutable::parse($nextRelease, 'UTC') : null,
            'paidOut' => (int) Payout::query()->where('creator_id', $creator)->where('status', PayoutState::Sent)->sum('amount_minor'),
            'firstSent' => is_string($firstSent) ? CarbonImmutable::parse($firstSent, 'UTC') : null,
            'destinations' => $this->destinations(),
            'history' => Payout::query()->where('creator_id', $creator)->latest('requested_at')->limit(20)->get(),
            'minimum' => config()->integer('fanfolio.money.payout_min_minor'),
            'reviewAbove' => config()->integer('fanfolio.money.payout_manual_review_above_minor'),
        ];
    }

    /** "1,920.00" in the form, "$1,920" on the cards. */
    public static function money(int $cents): string
    {
        return $cents % 100 === 0 && $cents >= 0 ? '$'.number_format(intdiv($cents, 100)) : WalletDisplayService::dollars($cents);
    }

    /** Dollars typed by the creator to cents, without floats. Null when it is not an amount. */
    public static function toCents(string $input): ?int
    {
        $input = str_replace([',', '$', ' '], '', trim($input));

        if (preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $input, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    private function dollarsInput(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function available(): int
    {
        return app(Ledger::class)->balance(AccountRef::of(AccountType::CreatorEarnings, $this->creatorId()))->amountMinor;
    }

    /**
     * @return list<PayoutDestination>
     */
    private function destinations(): array
    {
        return app(PayoutDestinations::class)->forUser($this->creatorId());
    }

    private function creatorId(): string
    {
        $id = auth()->id();
        abort_unless(is_string($id), 403);

        return $id;
    }
}
