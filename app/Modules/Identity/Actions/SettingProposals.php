<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Identity\Contracts\Setting;
use App\Modules\Identity\Contracts\StaffAbility;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\SettingProposal;
use Illuminate\Support\Facades\DB;

/**
 * Canvas AdminSettings (plan M8a use cases 35 and 42, D-16, D-24): "Money and safety
 * settings need a second admin to approve before they take effect." One admin proposes
 * with a reason; a different admin approves (the value changes at once) or rejects with
 * a note; the proposer may withdraw. Every step is audited (rule 12). A value is checked
 * when proposed, and approval is refused if the setting changed in the meantime.
 */
final readonly class SettingProposals
{
    public function __construct(
        private PlatformSettingsStore $settings,
        private Accounts $accounts,
        private AuditLog $audit,
    ) {}

    public function propose(Setting $setting, mixed $value, string $staffId, string $reason): SettingProposal
    {
        $this->allowed($staffId);
        $reason = $this->text($reason, 'reason', 'Say why this should change.');
        $value = SettingValues::normalise($setting, $value);

        return DB::transaction(function () use ($setting, $value, $staffId, $reason): SettingProposal {
            $current = $this->settings->value($setting);

            if (SettingValues::same($current, $value)) {
                throw new IdentityException($setting->label().' is already set to that.', $setting->value);
            }

            if (SettingProposal::query()->where('key', $setting)->where('status', SettingProposal::PENDING)->lockForUpdate()->exists()) {
                throw new IdentityException('A change to '.lcfirst($setting->label()).' is already waiting for approval.', $setting->value);
            }

            $proposal = new SettingProposal;
            $proposal->forceFill([
                'key' => $setting,
                'current_value' => $current,
                'proposed_value' => $value,
                'reason' => $reason,
                'proposed_by' => $staffId,
                'proposed_at' => now(),
            ])->save();

            $this->audit->record('settings.proposed', $staffId, 'settings_proposal', $proposal->id, [
                'setting' => $setting->value,
                'from' => SettingValues::describe($setting, $current),
                'to' => SettingValues::describe($setting, $value),
            ]);

            return $proposal;
        });
    }

    public function approve(string $proposalId, string $staffId, string $note = ''): SettingProposal
    {
        $this->allowed($staffId);
        $note = trim($note);

        return DB::transaction(function () use ($proposalId, $staffId, $note): SettingProposal {
            $proposal = $this->pending($proposalId);
            $this->secondAdmin($proposal, $staffId);

            if (! SettingValues::same($this->settings->value($proposal->key), $proposal->current_value)) {
                throw new IdentityException('This setting changed since it was proposed. Reject this and propose again.', 'proposal');
            }

            $this->settings->put($proposal->key, $proposal->proposed_value, $staffId);
            $this->decide($proposal, SettingProposal::APPROVED, $staffId, $note === '' ? null : mb_substr($note, 0, 500));

            $this->audit->record('settings.changed', $staffId, 'settings_proposal', $proposal->id, [
                'setting' => $proposal->key->value,
                'from' => SettingValues::describe($proposal->key, $proposal->current_value),
                'to' => SettingValues::describe($proposal->key, $proposal->proposed_value),
                'proposed_by' => $proposal->proposed_by,
            ]);

            return $proposal;
        });
    }

    public function reject(string $proposalId, string $staffId, string $note): SettingProposal
    {
        $this->allowed($staffId);
        $note = $this->text($note, 'note', 'Write a note for the audit log.');

        return DB::transaction(function () use ($proposalId, $staffId, $note): SettingProposal {
            $proposal = $this->pending($proposalId);
            $this->secondAdmin($proposal, $staffId);
            $this->decide($proposal, SettingProposal::REJECTED, $staffId, $note);

            $this->audit->record('settings.rejected', $staffId, 'settings_proposal', $proposal->id, ['setting' => $proposal->key->value, 'note' => $note]);

            return $proposal;
        });
    }

    public function withdraw(string $proposalId, string $staffId): SettingProposal
    {
        $this->allowed($staffId);

        return DB::transaction(function () use ($proposalId, $staffId): SettingProposal {
            $proposal = $this->pending($proposalId);

            if ($proposal->proposed_by !== $staffId) {
                throw new IdentityException('Only the admin who proposed it can withdraw it.', 'proposal');
            }

            $this->decide($proposal, SettingProposal::WITHDRAWN, $staffId, null);
            $this->audit->record('settings.withdrawn', $staffId, 'settings_proposal', $proposal->id, ['setting' => $proposal->key->value]);

            return $proposal;
        });
    }

    private function allowed(string $staffId): void
    {
        if (! $this->accounts->can($staffId, StaffAbility::ProposeSettings)) {
            throw new IdentityException('Your role cannot do this.', 'staff');
        }
    }

    private function pending(string $proposalId): SettingProposal
    {
        $proposal = SettingProposal::query()->whereKey($proposalId)->lockForUpdate()->first();

        if (! $proposal instanceof SettingProposal || $proposal->status !== SettingProposal::PENDING) {
            throw new IdentityException('This proposal is no longer waiting for approval.', 'proposal');
        }

        return $proposal;
    }

    private function secondAdmin(SettingProposal $proposal, string $staffId): void
    {
        if ($proposal->proposed_by === $staffId) {
            throw new IdentityException('A second admin has to decide on your own proposal.', 'proposal');
        }
    }

    private function decide(SettingProposal $proposal, string $status, string $staffId, ?string $note): void
    {
        $proposal->forceFill(['status' => $status, 'decided_by' => $staffId, 'decided_at' => now(), 'decision_note' => $note])->save();
    }

    private function text(string $text, string $field, string $message): string
    {
        $text = trim($text);

        if (mb_strlen($text) < 3 || mb_strlen($text) > 500) {
            throw new IdentityException($message, $field);
        }

        return $text;
    }
}
