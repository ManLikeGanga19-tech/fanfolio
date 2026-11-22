<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Actions\Devices;
use App\Modules\Identity\Exceptions\DeviceLimitReached;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DeviceCookie;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-05: a session lives only while its device is active. Signing a device out
 * remotely ends its session on its next request.
 *
 * A signed-in session without a device (one started before devices existed) registers
 * its device now, under the same limit.
 */
final readonly class EnsureDeviceActive
{
    public function __construct(private Devices $devices) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $deviceId = $request->session()->get(Devices::SESSION_KEY);

        if (! is_string($deviceId)) {
            try {
                $device = $this->devices->register($user, DeviceCookie::ensure($request), $request->userAgent(), $request->ip());
            } catch (DeviceLimitReached) {
                return $this->signOut($request, 'This account is signed in on too many devices. Log in again to choose one to sign out.');
            }

            $request->session()->put(Devices::SESSION_KEY, $device->id);

            return $next($request);
        }

        if (! $this->devices->isActiveFor($user, $deviceId)) {
            return $this->signOut($request, 'You were signed out on this device.');
        }

        $this->devices->touch($deviceId, $request->ip());

        return $next($request);
    }

    private function signOut(Request $request, string $message): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 401);
        }

        return redirect()->route('login')->with('status', $message);
    }
}
