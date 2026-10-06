<?php

namespace App\Console\Commands;

use App\Models\AssetWatch;
use Illuminate\Console\Command;

/**
 * Mark asset watches past expires_at as expired (card K3VEcxtw). Firing never
 * depends on this: the evaluator refuses any watch whose expires_at has passed.
 * This only records the state and frees the (owner, asset, state) slot.
 */
class ExpireAssetWatches extends Command
{
    protected $signature = 'assets:expire-watches';

    protected $description = 'Mark asset watches whose lifetime has ended as expired.';

    public function handle(): int
    {
        $count = AssetWatch::query()
            ->whereNotNull('active_key')
            ->where('expires_at', '<=', now())
            ->update(['active_key' => null, 'expired_at' => now()]);

        $this->info("Expired {$count} asset watch(es).");

        return self::SUCCESS;
    }
}
