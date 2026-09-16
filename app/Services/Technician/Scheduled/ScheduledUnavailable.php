<?php

namespace App\Services\Technician\Scheduled;

/** Pre-intent availability only. Never raised by the mutation transport. */
final class ScheduledUnavailable extends \RuntimeException {}
