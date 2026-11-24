<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Models\User;
use Closure;
use DateTimeZone;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Decision D-15: times are shown in the signed-in user's own time zone (saved from the
 * browser); stored times stay UTC. Filament's zone is a long-lived singleton under
 * Octane, so it is set on every request, back to the app zone for guests.
 */
final readonly class ApplyDisplayTimezone
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        FilamentTimezone::set($user instanceof User ? $this->valid($user->timezone) : null);

        return $next($request);
    }

    private function valid(?string $zone): ?string
    {
        if ($zone === null || $zone === '') {
            return null;
        }

        try {
            new DateTimeZone($zone);
        } catch (Throwable) {
            return null;
        }

        return $zone;
    }
}
