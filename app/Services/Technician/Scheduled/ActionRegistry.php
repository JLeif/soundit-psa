<?php

namespace App\Services\Technician\Scheduled;

/** Exact approved catalog; installed adapters are a strict subset. */
final class ActionRegistry
{
    public const ACTIONS = [
        'cipp_stage_disable_user_sign_in' => 'cipp_disable_user_sign_in',
        'cipp_stage_enable_user_sign_in' => 'cipp_enable_user_sign_in',
        'cipp_stage_revoke_user_sessions' => 'cipp_revoke_user_sessions',
        'cipp_stage_remove_user_mfa_methods' => 'cipp_remove_user_mfa_methods',
        'cipp_stage_set_legacy_per_user_mfa' => 'cipp_set_legacy_per_user_mfa',
        'cipp_stage_assign_user_license' => 'cipp_assign_user_license',
        'cipp_stage_assign_tenant_user_license' => 'cipp_assign_tenant_user_license',
        'cipp_stage_remove_user_license' => 'cipp_remove_user_license',
        'cipp_stage_convert_mailbox' => 'cipp_convert_mailbox',
        'cipp_stage_set_mailbox_forwarding' => 'cipp_set_mailbox_forwarding',
        'cipp_stage_set_mailbox_gal_visibility' => 'cipp_set_mailbox_gal_visibility',
        'cipp_stage_set_mailbox_out_of_office' => 'cipp_set_mailbox_out_of_office',
        'cipp_stage_set_mailbox_delegate' => 'cipp_set_mailbox_delegate',
        'cipp_stage_remove_directory_role' => 'cipp_remove_directory_role',
        'cipp_stage_remove_mailbox_rule' => 'cipp_remove_mailbox_rule',
        'cipp_stage_release_quarantine_message' => 'cipp_release_quarantine_message',
        'cipp_stage_add_tenant_allow_entry' => 'cipp_add_tenant_allow_entry',
        'cipp_stage_reassign_onedrive' => 'cipp_reassign_onedrive',
        'cipp_stage_edit_user' => 'cipp_edit_user',
        'cipp_stage_set_group_membership' => 'cipp_set_group_membership',
        'tactical_stage_script' => 'tactical_run_script',
        'tactical_stage_command' => 'tactical_run_command',
        'tactical_stage_reboot' => 'tactical_reboot_device',
        'tactical_stage_shutdown' => 'tactical_shutdown_device',
        'tactical_stage_recover_mesh' => 'tactical_recover_mesh',
        'tactical_stage_maintenance' => 'tactical_set_maintenance',
        'tactical_stage_start_service' => 'tactical_start_service',
        'tactical_stage_stop_service' => 'tactical_stop_service',
        'tactical_stage_restart_service' => 'tactical_restart_service',
        'tactical_stage_install_approved_patches' => 'tactical_install_approved_patches',
    ];

    public static function directTool(string $action): ?string
    {
        return self::ACTIONS[$action] ?? null;
    }

    /** Fixed, distinct admission reason codes. Never echo an arbitrary caller type. */
    public static function admissionRefusal(string $action): ?string
    {
        if (self::directTool($action) === null) {
            return 'scheduling_type_not_registered';
        }

        return match ($action) {
            'tactical_stage_script' => 'unsupported_scheduling_type:tactical_stage_script',
            'tactical_stage_install_approved_patches' => 'unsupported_scheduling_type:tactical_stage_install_approved_patches',
            default => null,
        };
    }

    public static function adapterAvailable(string $action): bool
    {
        return MailboxPlan::supports($action) || TacticalPlan::supports($action);
    }
}
