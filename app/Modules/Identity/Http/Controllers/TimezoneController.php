<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Decision D-15: the browser sends its IANA time zone
 * (Intl.DateTimeFormat().resolvedOptions().timeZone) when it differs from the saved one.
 */
final class TimezoneController
{
    public function update(Request $request): Response
    {
        $request->validate([
            'timezone' => ['required', 'string', 'max:64', 'timezone:all'],
        ]);
        $zone = $request->string('timezone')->toString();

        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        if ($user->timezone !== $zone) {
            $user->forceFill(['timezone' => $zone])->save();
        }

        return response()->noContent();
    }
}
