<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

/**
 * SEC-15 least privilege: what each staff role may do in /admin (plan M8a, "Roles").
 * Every ability is also a Laravel Gate of the same name, so pages and Actions in any
 * module check `Gate::allows(StaffAbility::X->value)` or `Accounts::can()`.
 *
 * Moderators work queues and give short suspensions and strikes; admins also ban,
 * lift bans, see the audit log, manage staff and settings (decision D-18).
 */
enum StaffAbility: string
{
    // Overview
    case ViewOverview = 'admin.overview.view';
    case ViewSystemHealth = 'admin.overview.health';

    // Creator approvals and the creators directory
    case ReviewCreators = 'admin.creators.review';
    case ViewCreators = 'admin.creators.view';
    case PauseCreator = 'admin.creators.pause';
    case SuspendCreator = 'admin.creators.suspend';
    case ReinstateCreator = 'admin.creators.reinstate';
    case RequireIdRecheck = 'admin.creators.id_recheck';
    case PauseCreatorPayouts = 'admin.creators.pause_payouts';

    // Users and bans
    case ViewUsers = 'admin.users.view';
    case SuspendUser = 'admin.users.suspend';
    /** Suspensions longer than 7 days (D-18: moderators suspend for up to 7). */
    case SuspendUserLong = 'admin.users.suspend_long';
    case BanUser = 'admin.users.ban';
    case LiftRestriction = 'admin.users.lift';
    case GiveStrike = 'admin.users.strike';
    case SignOutUser = 'admin.users.sign_out';
    case RequestAgeRecheck = 'admin.users.age_recheck';
    case ResetSecondFactor = 'admin.users.reset_mfa';
    case FlagUser = 'admin.users.flag';

    // Moderation queue
    case WorkModeration = 'admin.moderation.work';

    // Audit log, staff, settings
    case ViewAuditLog = 'admin.audit.view';
    case ExportAuditLog = 'admin.audit.export';
    case ManageStaff = 'admin.staff.manage';
    case ViewSettings = 'admin.settings.view';
    case ProposeSettings = 'admin.settings.propose';

    /** Abilities moderators do not have. */
    public function adminOnly(): bool
    {
        return match ($this) {
            self::ViewSystemHealth,
            self::SuspendCreator,
            self::ReinstateCreator,
            self::PauseCreatorPayouts,
            self::SuspendUserLong,
            self::BanUser,
            self::LiftRestriction,
            self::ResetSecondFactor,
            self::ViewAuditLog,
            self::ExportAuditLog,
            self::ManageStaff,
            self::ViewSettings,
            self::ProposeSettings => true,
            default => false,
        };
    }
}
