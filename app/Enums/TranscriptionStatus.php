<?php

namespace App\Enums;

enum TranscriptionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Unusable = 'unusable';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Unusable => 'Unusable — listen to recording',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'bg-secondary',
            self::Processing => 'bg-info',
            self::Completed => 'bg-success',
            self::Failed => 'bg-danger',
            self::Unusable => 'bg-warning text-dark',
        };
    }
}
