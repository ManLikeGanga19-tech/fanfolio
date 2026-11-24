<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Http\Controllers;

use App\Modules\Creators\Contracts\CreatorPages;
use App\Modules\Identity\Contracts\AccountDirectory;
use App\Modules\TrustSafety\Actions\FileReport;
use App\Modules\TrustSafety\Enums\ReportReason;
use App\Modules\TrustSafety\Exceptions\TrustSafetyException;
use App\Modules\TrustSafety\Models\ModerationCase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The "Report" sheet (canvas FanMore · Report content) posts here, for a creator page by
 * its handle or an account by its username (plan M8a use case 28). Reports on posts and
 * messages arrive with M4 and M6. The answer is the same whether or not the report was
 * new, so a repeat tells the reporter nothing.
 */
final class ReportController
{
    public const string THANKS = 'Thanks. Your report was sent. Reports are anonymous.';

    public function store(Request $request, FileReport $report, CreatorPages $pages, AccountDirectory $directory): RedirectResponse
    {
        $reporter = $request->user()?->getAuthIdentifier();
        abort_unless(is_string($reporter), 403);

        $request->validate([
            'kind' => ['required', Rule::in([ModerationCase::CREATOR_PAGE, ModerationCase::ACCOUNT])],
            'target' => ['required', 'string', 'max:60'],
            'reason' => ['required', Rule::enum(ReportReason::class)],
            'details' => ['nullable', 'string', 'max:'.FileReport::DETAILS_MAX],
        ], [
            'reason.required' => 'Choose what is wrong.',
            'reason.enum' => 'Choose what is wrong.',
        ]);

        $kind = $request->string('kind')->toString() === ModerationCase::CREATOR_PAGE ? ModerationCase::CREATOR_PAGE : ModerationCase::ACCOUNT;
        $target = $request->string('target')->toString();
        $details = $request->filled('details') ? $request->string('details')->toString() : null;

        $userId = $kind === ModerationCase::CREATOR_PAGE
            ? $pages->userIdForHandle($target)
            : $directory->findByUsername($target)?->id;

        if (! is_string($userId)) {
            throw ValidationException::withMessages(['target' => 'We could not find that account.']);
        }

        try {
            $report->handle($reporter, $userId, $kind, ReportReason::from($request->string('reason')->toString()), $details);
        } catch (TrustSafetyException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        return back()->with('status', self::THANKS);
    }
}
