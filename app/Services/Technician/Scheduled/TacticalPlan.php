<?php

namespace App\Services\Technician\Scheduled;

use App\Services\Tactical\Actions\RebootAction;
use App\Services\Tactical\Actions\RecoverAction;
use App\Services\Tactical\Actions\RunCommandAction;
use App\Services\Tactical\Actions\ServiceControlAction;
use App\Services\Tactical\Actions\SetMaintenanceAction;
use App\Services\Tactical\Actions\ShutdownAction;
use App\Services\Tactical\Actions\TacticalAction;
use InvalidArgumentException;

/** Exact #1783 argument boundary; scripts and dynamic patch sets deliberately absent. */
final class TacticalPlan
{
    public const TYPES = [
        'tactical_stage_command', 'tactical_stage_reboot', 'tactical_stage_shutdown',
        'tactical_stage_recover_mesh', 'tactical_stage_maintenance',
        'tactical_stage_start_service', 'tactical_stage_stop_service', 'tactical_stage_restart_service',
    ];

    public static function supports(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    public static function action(string $type): TacticalAction
    {
        return match ($type) {
            'tactical_stage_command' => new RunCommandAction,
            'tactical_stage_reboot' => new RebootAction,
            'tactical_stage_shutdown' => new ShutdownAction,
            'tactical_stage_recover_mesh' => new RecoverAction,
            'tactical_stage_maintenance' => new SetMaintenanceAction,
            'tactical_stage_start_service' => new ServiceControlAction('start'),
            'tactical_stage_stop_service' => new ServiceControlAction('stop'),
            'tactical_stage_restart_service' => new ServiceControlAction('restart'),
            default => throw new InvalidArgumentException('unsupported_scheduling_type'),
        };
    }

    public static function params(string $type, array $params): array
    {
        $keys = match ($type) {
            'tactical_stage_command' => ['cmd', 'shell', 'timeout'],
            'tactical_stage_recover_mesh' => ['mode'],
            'tactical_stage_maintenance' => ['enabled'],
            'tactical_stage_start_service', 'tactical_stage_stop_service', 'tactical_stage_restart_service' => ['service_name'],
            'tactical_stage_reboot', 'tactical_stage_shutdown' => [],
            default => throw new InvalidArgumentException('unsupported_scheduling_type'),
        };
        if (array_diff(array_keys($params), $keys) || array_diff($keys, array_keys($params))) {
            throw new InvalidArgumentException('unsupported_scheduling_arguments');
        }
        if (($type === 'tactical_stage_command' && (! is_int($params['timeout']) || ! is_string($params['cmd']) || trim($params['cmd']) !== $params['cmd']))
            || ($type === 'tactical_stage_maintenance' && ! is_bool($params['enabled']))
            || ($type === 'tactical_stage_recover_mesh' && $params['mode'] !== 'mesh')
            || (isset($params['service_name']) && (! is_string($params['service_name']) || trim($params['service_name']) !== $params['service_name']))) {
            throw new InvalidArgumentException('unsupported_scheduling_arguments');
        }
        $validated = self::action($type)->validateParams($params);
        if (ApprovalEnvelope::canonical($validated) !== ApprovalEnvelope::canonical($params)) {
            throw new InvalidArgumentException('scheduling_arguments_not_canonical');
        }

        return $validated;
    }

    /** Producers: tacticalrmm@1e786d37 agents/views.py, services/views.py. No invented fields. */
    public static function wire(string $type, string $agent, array $params): array
    {
        $params = self::params($type, $params);
        $path = 'agents/'.rawurlencode($agent).'/';

        return match ($type) {
            'tactical_stage_command' => ['POST', $path.'cmd/', [...$params, 'custom_shell' => null, 'run_as_user' => false, 'env_vars' => []]],
            'tactical_stage_reboot' => ['POST', $path.'reboot/', []],
            'tactical_stage_shutdown' => ['POST', $path.'shutdown/', []],
            'tactical_stage_recover_mesh' => ['POST', $path.'recover/', ['mode' => 'mesh']],
            'tactical_stage_maintenance' => ['PUT', $path, ['maintenance_mode' => $params['enabled']]],
            'tactical_stage_start_service', 'tactical_stage_stop_service', 'tactical_stage_restart_service' => [
                'POST', 'services/'.rawurlencode($agent).'/'.rawurlencode($params['service_name']).'/',
                ['sv_action' => match ($type) {
                    'tactical_stage_start_service' => 'start', 'tactical_stage_stop_service' => 'stop', default => 'restart',
                }],
            ],
        };
    }

    /** A raw command reply contains no reliable exit status: receipt is submitted, NOT completed. */
    public static function outcome(string $type, mixed $body): string
    {
        if ($type === 'tactical_stage_command') {
            return is_string($body) ? 'submitted' : 'uncertain';
        }
        $expected = match ($type) {
            'tactical_stage_reboot', 'tactical_stage_shutdown' => 'ok',
            'tactical_stage_recover_mesh' => 'Successfully completed recovery',
            'tactical_stage_maintenance' => 'The agent was updated successfully',
            'tactical_stage_start_service' => 'The service was started successfully',
            'tactical_stage_stop_service' => 'The service was stopped successfully',
            'tactical_stage_restart_service' => 'The service was restarted successfully',
            default => null,
        };

        return $expected !== null && $body === $expected ? 'completed' : 'uncertain';
    }
}
