<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class AccountController
{
    public function show(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return view('identity::account.hub', ['user' => $user]);
    }
}
