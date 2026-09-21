# Local Composer vendor freshness

This is a **development-only** maintenance procedure, not production repair or
release authority. Coordinate shared checkouts with their owner; never refresh a
reviewer's dependencies, a live gate's inputs, or a seed while it is being copied.
See [contributor setup](../CONTRIBUTING.md) and [authority](DECISIONS.md#authority).

## After a lock bump

A checkout's `composer.lock` records intent, not installed bytes. At reference
commit `7750f17b`, `scripts/deploy.sh` runs `composer install --no-dev
--optimize-autoloader --quiet` **inside its remote SSH shell**. Successful remote
deployment does not refresh the invoking local checkout or other worktrees.
Refresh each owned local tree against its own intended manifest/lock before using
it for tests or vendor-source citations. Do not copy a sibling's generated
autoloader: it may bind to that sibling's application paths.

With exclusive ownership, a healthy tree can ordinarily use `composer install`
from its unchanged lock (not `composer update`), with scripts/plugins disabled
until their effects are separately approved. Preserve its intended dev/no-dev
shape. Verify actual content and application acceptance below; Composer exit 0,
`composer show`, `installed.php`, `installed.json` and timestamps are not content
certificates.

If installed metadata already claims the locked versions while files are stale,
ordinary install can plan zero updates and leave the stale bytes in place.
Changing metadata alone is not repair. Use the reversible empty-target procedure
below rather than repeatedly accepting exit 0.

## Empty-target recovery under consumer exclusion

1. **Establish ownership before mutation.** Record the checkout's explicit Git SHA,
   status and hashes of `composer.json` / `composer.lock`. Preserve unexpected
   residue. Confirm `vendor` is a real directory, not a shared link; confirm space
   and permissions. Record intended dev/no-dev mode from the owner and existing
   installation, using metadata for shape only, not freshness. Do not infer mode
   from a package count (Composer can include platform/virtual entries).
2. Exclude all consumers for the whole operation: tests, reviewers, seed-copy
   jobs, PHP processes, source readers and scheduled work. Have their owner
   quiesce them; an instantaneous empty process scan is not an exclusion lease.
   Inspect directory-specific cwd, open descriptors and mapped files, with a
   positive control. `fuser -m` on an ordinary directory reports the containing
   filesystem, not just that tree. If ownership/exclusion is uncertain, stop.
3. Retain `vendor`, including original Composer metadata, by a **no-overwrite
   rename** to a uniquely named audit/rollback directory **outside the checkout**
   and outside normal source-search roots. Prefer the same filesystem for an
   atomic rename; do not silently substitute a cross-filesystem copy/delete.
   Require the destination absent. Record both paths and a full sorted
   relative-path manifest: file hashes, file set, symlink targets and modes.
   Compare before/after; retain the evidence separately. Never delete the old
   tree or leave the stale copy beside live source where recursive readers can
   mistake it for current vendor code.
4. With `vendor` now absent, install in the **real target checkout**, from its own
   unchanged manifest and lock. Use a private Composer home/cache, no inherited
   credentials, and a reviewed environment. For a dev-inclusive tree the command
   is:

   ```sh
   composer install --no-scripts --no-plugins --no-interaction --prefer-dist --no-progress
   ```

   For a tree intentionally maintained **no-dev**, add `--no-dev`; do not change
   that shape as part of a freshness repair. Ensure inherited `COMPOSER_NO_DEV`
   or alternate manifest/vendor-directory settings cannot change the plan.
   Empty-target installation forces package extraction rather than trusting the
   old installed metadata. Do not run `composer update` or edit the lock.
5. Verify the original SHA, source status and manifest/lock hashes remain
   unchanged, then perform **both** acceptance legs below before releasing any
   consumer. Scripts and plugins remain disabled by default. Any necessary
   package-discovery/cache step needs a separately checked, consumer-scoped
   development action; do not enable all hooks just to make a boot failure vanish.

## Acceptance: bytes AND application

- Derive the complete changed-package population from both `packages` and
  `packages-dev` in the before/after lock; report the denominator and which mode
  applies. Compare all distribution files and file sets (changed, missing,
  extra) against independently obtained exact locked archive references. Check
  representative source constants/hashes too. A fresh reference must itself be
  tied to the lock, not accepted solely because its install returned zero.
  Run the same gauge on retained known-stale bytes to prove it detects drift.
  Distinguish package distribution files from generated autoload metadata.
- Inspect generated autoload paths in the real checkout. In an isolated, safe
  development configuration, run `php artisan --version` as a boot smoke check;
  a successful autoload build is not app acceptance. Run the relevant tests and
  [full contributor gate](../CONTRIBUTING.md#the-gate) before using the tree for
  a landing. No boot/test step here authorizes live vendor calls or production
  access. A version smoke check is not a full behavioral test.
- Preserve mode: a dev-inclusive application/cache can still discover
  `Laravel\Pail\PailServiceProvider`. Rebuilding it with `--no-dev` can remove
  Pail and fail boot while every audited runtime package passes byte parity.
  Diagnose that mismatch; do not erase caches or broaden hooks blindly.

### Recorded scope correction

Lock bump `017f5a9d` changed **15 packages: 14 runtime plus dev-only
`symfony/yaml`**. The runtime set is `guzzlehttp/{guzzle,promises,psr7}`,
`laravel/framework`, `league/commonmark`, `phpseclib/phpseclib`,
`symfony/{http-foundation,http-kernel,mailer,mime,routing}` and
`symfony/polyfill-{intl-idn,php84,php85}`. The earlier 12-package repair
certificate was a subset, not the complete changed population; Jeeves's
independent verification corrected it to all 15 (3,079 distribution files).
A no-dev tree correctly omits YAML: its corresponding scope is 14 packages
(3,061 files). These historical counts are not a standing test specification;
re-derive the population on each lock bump. Package parity is not an all-vendor
certificate, vulnerability scan, or attestation of OPcache/process memory.

## Rollback and handback

If install or acceptance fails, keep consumers excluded. Require a fresh absent
failure-evidence destination; rename the newly created `vendor` there, then
rename the retained original back to the now-absent `vendor` path, without
replacement/overlay. If install created no vendor, only the second rename is
needed. Compare the restored full manifest with the original and verify safe
consumer state before resuming work. This restores the **old, known-stale bytes
and metadata**, not a repaired tree; do not certify it for new-lock testing.

Record exact original, retained and failed-tree paths, commands, manifest hashes,
checks run/failed/skipped, and remaining ownership/authority on the coordination
record. Retain rollback until the owner accepts disposal. Never restore the
stale tree into production or treat this note as permission to merge, deploy,
change credentials, or mutate another seat's dependencies.
