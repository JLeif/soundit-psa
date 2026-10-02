<?php

namespace App\Enums;

enum AlertSource: string
{
    case Tactical = 'tactical';
    case Ninja = 'ninja';
    case Comet = 'comet';
    case Huntress = 'huntress';
    case Cipp = 'cipp';
    case AppRiver = 'appriver';
    case LeifRmm = 'leif_rmm';

    public function label(): string
    {
        return match ($this) {
            self::Tactical => 'Tactical RMM',
            self::Ninja => 'NinjaRMM',
            self::Comet => 'Comet Backup',
            self::Huntress => 'Huntress',
            self::Cipp => 'CIPP',
            self::AppRiver => 'AppRiver',
            self::LeifRmm => 'Leif RMM',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Tactical => 'bi-hdd-network',
            self::Ninja => 'bi-hdd-network',
            self::Comet => 'bi-cloud-arrow-up',
            self::Huntress => 'bi-shield-check',
            self::Cipp => 'bi-microsoft',
            self::AppRiver => 'bi-key',
            self::LeifRmm => 'bi-hdd-network',
        };
    }
}
