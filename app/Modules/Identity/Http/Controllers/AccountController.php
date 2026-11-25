<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Creators\Contracts\CreatorPages;
use App\Modules\Identity\Actions\Devices;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Wallet\Contracts\WalletDisplay;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class AccountController
{
    public function show(Request $request, Devices $devices, WalletDisplay $wallet, CreatorPages $creators): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $credits = $wallet->credits($user->id);
        $currency = $wallet->currency($user->id, $request->header('Accept-Language'));

        return view('identity::account.hub', [
            'user' => $user,
            'deviceCount' => $devices->active($user)->count(),
            // Canvas FanMore · Account: the wallet card and Currency row (M3 PR 2, D-21).
            'credits' => $credits,
            'approximate' => $wallet->approximate($credits, $currency),
            'currency' => $currency === 'KES' ? 'KSh' : $currency,
            // A fan who started or sent a creator application sees where it stands, not "Become a creator".
            'application' => $user->role === Role::Fan ? $creators->applicationStage($user->id) : null,
        ]);
    }
}
