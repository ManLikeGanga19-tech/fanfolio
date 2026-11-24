<?php

declare(strict_types=1);

use App\Modules\Creators\Actions\SuspendCreator;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Models\User;
use App\Modules\Wallet\Contracts\Data\AccountRef;
use App\Modules\Wallet\Contracts\Data\AccountType;
use App\Modules\Wallet\Contracts\Data\LedgerTransaction;
use App\Modules\Wallet\Contracts\Data\Posting;
use App\Modules\Wallet\Contracts\Data\PurchaseKind;
use App\Modules\Wallet\Contracts\Data\PurchaseRequest;
use App\Modules\Wallet\Contracts\Exceptions\PurchaseRefused;
use App\Modules\Wallet\Contracts\Ledger;
use App\Modules\Wallet\Contracts\Purchases;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

// Brief rule 9 across modules: a sale goes only to a creator who can earn, checked through
// Creators' real eligibility; a suspension stops sales at once.

afterEach(fn () => expectLedgerBalanced());

it('pays an approved creator and refuses one suspended a moment later', function (): void {
    Notification::fake();
    $admin = signedInStaff();
    $fan = User::factory()->create();
    $creatorUser = User::factory()->create();
    $creator = Creator::factory()->create(['user_id' => $creatorUser->id, 'handle' => 'paid.creator', 'display_name' => 'Paid Creator']);
    $creator->forceFill(['status' => CreatorStatus::Active, 'kyc_status' => KycStatus::Approved, 'approved_at' => now()])->save();

    app(Ledger::class)->post(LedgerTransaction::make('topup', 'fund:'.Str::ulid(), [
        Posting::debit(AccountRef::platform(AccountType::ProcessorClearing), Money::credits(500)),
        Posting::credit(AccountRef::of(AccountType::FanWallet, $fan->id), Money::credits(500)),
    ]));

    $purchases = app(Purchases::class);
    $purchases->charge(new PurchaseRequest($fan->id, $creatorUser->id, Money::credits(100), PurchaseKind::Tip, 'tip-1'));
    expect(app(Ledger::class)->balance(AccountRef::of(AccountType::CreatorPending, $creatorUser->id))->amountMinor)->toBe(80);

    app(SuspendCreator::class)->handle($creator->id, $admin->id, 'policy', 'Checking a report');

    expect(fn () => $purchases->charge(new PurchaseRequest($fan->id, $creatorUser->id, Money::credits(100), PurchaseKind::Tip, 'tip-2')))
        ->toThrow(PurchaseRefused::class, 'cannot take payments');
    expect(app(Ledger::class)->balance(AccountRef::of(AccountType::FanWallet, $fan->id))->amountMinor)->toBe(400);
});
