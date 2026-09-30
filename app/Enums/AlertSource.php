<?php

namespace App\Enums;

enum AlertSource: string
{
    case Tactical = 'tactical';
    case Ninja = 'ninja';
    case Comet = 'comet';
    case Huntress = 'huntress';
    case LeifRmm = 'leif_rmm';
    case Cipp = 'cipp';
    case AppRiver = 'appriver';

    public function label(): string
    {
        return match ($this) {
            self::Tactical => 'Tactical RMM',
            self::Ninja => 'NinjaRMM',
            self::Comet => 'Comet Backup',
            self::Huntress => 'Huntress',
            self::LeifRmm => 'Leif RMM',
            self::Cipp => 'CIPP',
            self::AppRiver => 'AppRiver',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Tactical => 'bi-hdd-network',
            self::Ninja => 'bi-hdd-network',
            self::Comet => 'bi-cloud-arrow-up',
            self::Huntress => 'bi-shield-check',
            self::LeifRmm => 'bi-hdd-network',
            self::Cipp => 'bi-microsoft',
            self::AppRiver => 'bi-key',
        };
    }
}
