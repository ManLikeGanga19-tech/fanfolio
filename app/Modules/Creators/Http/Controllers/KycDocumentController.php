<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorKyc;
use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Identity\Contracts\Data\DocumentSide;
use App\Modules\Identity\Contracts\Data\VerificationStatus;
use App\Modules\Identity\Contracts\IdentityVerifier;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decision D-01: staff look at an ID image or selfie frame fetched from the KYC
 * provider on demand. It is streamed straight through, never cached or stored
 * (rule 10), and every view is audited.
 */
final class KycDocumentController
{
    public function show(Request $request, string $creator, string $side, IdentityVerifier $verifier, Accounts $accounts, AuditLog $audit): Response
    {
        $staffId = $request->user()?->getAuthIdentifier();
        abort_unless(is_string($staffId) && $accounts->isStaff($staffId), 403);

        $documentSide = DocumentSide::tryFrom($side);
        abort_unless($documentSide instanceof DocumentSide, 404);

        $record = Creator::query()->find($creator);
        abort_unless($record instanceof Creator && $record->user_id !== $staffId, 404);

        $check = $record->kycChecks()
            ->whereIn('status', [VerificationStatus::Passed, VerificationStatus::Review])
            ->latest('checked_at')
            ->first();
        abort_unless($check instanceof CreatorKyc, 404);

        $image = $verifier->fetchDocumentImage($check->provider_ref, $documentSide);

        $audit->record('kyc.document_viewed', $staffId, 'creator_kyc', $check->id, ['side' => $documentSide->value]);

        return response($image->bytes, 200, [
            'Content-Type' => $image->contentType,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
