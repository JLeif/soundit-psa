<?php

namespace Tests\Feature;

use App\Services\VersionService;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Controls for the served-commit read (#3980).
 *
 * Every case drives the REAL service against a REAL git plumbing layout built on
 * disk, never a mock of our own expectations: the defect these controls exist for
 * was a read that returned a clean empty answer, and a fake that returns what the
 * code wants cannot fail that way. base_path() is repointed at the fixture so the
 * service resolves the fixture's own .git.
 */
class VersionReadTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = sys_get_temp_dir().'/psa-version-'.bin2hex(random_bytes(6));
        mkdir($this->fixture.'/.git/refs/heads', 0755, true);
        app()->setBasePath($this->fixture);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->fixture));
        parent::tearDown();
    }

    private function service(): VersionService
    {
        return new VersionService;
    }

    private const SHA_A = '03005ea8e33f53dfe1be832d50ed6735043899c3';

    private const SHA_B = '5fd10881f5d4a43f51544c86dcbae8035c025df3';

    // ---------------------------------------------------------------- success

    public function test_it_reads_the_served_commit_from_a_loose_ref_without_the_git_binary(): void
    {
        file_put_contents($this->fixture.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->fixture.'/.git/refs/heads/main', self::SHA_A."\n");

        // Precondition: no `git` binary is reachable at all, so a pass cannot come
        // from shelling out. Without this the control would not discriminate
        // between the plumbing read and the old Process call.
        $path = getenv('PATH');
        putenv('PATH=/nonexistent');
        try {
            $v = $this->service()->current();
        } finally {
            putenv('PATH='.$path);
        }

        $this->assertSame(self::SHA_A, $v['commit_hash']);
        $this->assertSame('03005ea', $v['commit_short']);
        $this->assertSame('main', $v['branch']);
        $this->assertNull($v['error']);
    }

    public function test_a_detached_head_reports_the_commit_it_holds(): void
    {
        file_put_contents($this->fixture.'/.git/HEAD', self::SHA_A."\n");

        $v = $this->service()->current();

        $this->assertSame(self::SHA_A, $v['commit_hash']);
        $this->assertStringContainsString('detached', $v['branch']);
        $this->assertNull($v['error']);
    }

    // ------------------------------------------------- loose beats packed-refs

    public function test_a_stale_packed_ref_does_not_override_the_loose_ref(): void
    {
        // Measured on production 2026-09-27: packed-refs named a commit 628 behind
        // the loose ref. Reading packed-refs first returns a REAL OLD COMMIT, so the
        // answer is a plausible 40-hex sha for a tree that is not served -- the one
        // failure mode a caller cannot detect.
        file_put_contents($this->fixture.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->fixture.'/.git/refs/heads/main', self::SHA_A."\n");
        file_put_contents(
            $this->fixture.'/.git/packed-refs',
            "# pack-refs with: peeled fully-peeled sorted \n".self::SHA_B." refs/heads/main\n"
        );

        $this->assertSame(self::SHA_A, $this->service()->current()['commit_hash']);
    }

    public function test_packed_refs_is_used_when_no_loose_ref_exists(): void
    {
        // Positive control for the fallback: without this, a reader that ignored
        // packed-refs entirely would pass the test above for the wrong reason.
        file_put_contents($this->fixture.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents(
            $this->fixture.'/.git/packed-refs',
            "# pack-refs with: peeled fully-peeled sorted \n".self::SHA_B." refs/heads/main\n"
        );

        $this->assertSame(self::SHA_B, $this->service()->current()['commit_hash']);
    }

    public function test_a_peeled_tag_line_is_not_mistaken_for_a_ref(): void
    {
        file_put_contents($this->fixture.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents(
            $this->fixture.'/.git/packed-refs',
            "# pack-refs with: peeled fully-peeled sorted \n"
            .self::SHA_B." refs/tags/v1\n^".self::SHA_A."\n"
            .self::SHA_A." refs/heads/main\n"
        );

        $this->assertSame(self::SHA_A, $this->service()->current()['commit_hash']);
    }

    public function test_a_broken_loose_ref_does_not_fall_back_to_a_stale_packed_entry(): void
    {
        // An existing loose ref is authoritative even when it is unusable. An empty
        // (truncated) loose file beside a stale packed entry must fail, not return
        // the stale packed commit as a clean answer.
        file_put_contents($this->fixture.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->fixture.'/.git/refs/heads/main', '');
        file_put_contents(
            $this->fixture.'/.git/packed-refs',
            "# pack-refs with: peeled fully-peeled sorted \n".self::SHA_B." refs/heads/main\n"
        );

        $v = $this->service()->current();

        $this->assertSame(VersionService::UNKNOWN, $v['commit_hash']);
        $this->assertStringContainsString('neither a commit id nor a symref', (string) $v['error']);
    }

    public function test_a_loose_symref_is_followed_rather_than_bypassed_to_packed_refs(): void
    {
        file_put_contents($this->fixture.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->fixture.'/.git/refs/heads/main', "ref: refs/heads/release\n");
        file_put_contents($this->fixture.'/.git/refs/heads/release', self::SHA_A."\n");
        file_put_contents(
            $this->fixture.'/.git/packed-refs',
            "# pack-refs with: peeled fully-peeled sorted \n".self::SHA_B." refs/heads/main\n"
        );

        $this->assertSame(self::SHA_A, $this->service()->current()['commit_hash']);
    }

    public function test_a_symref_cycle_is_a_failure_with_a_reason(): void
    {
        file_put_contents($this->fixture.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->fixture.'/.git/refs/heads/main', "ref: refs/heads/main\n");

        $v = $this->service()->current();

        $this->assertSame(VersionService::UNKNOWN, $v['commit_hash']);
        $this->assertStringContainsString('deeper than 5', (string) $v['error']);
    }

    // ------------------------------------------------------- linked worktree

    public function test_it_follows_a_gitdir_pointer_file_in_a_linked_worktree(): void
    {
        // Every review worktree in this pipeline has .git as a FILE, so a reader
        // that assumes a directory reports "unknown" on the checkouts we test in.
        $real = $this->fixture.'/realgit';
        mkdir($real.'/refs/heads', 0755, true);
        file_put_contents($real.'/HEAD', "ref: refs/heads/main\n");
        file_put_contents($real.'/refs/heads/main', self::SHA_A."\n");

        exec('rm -rf '.escapeshellarg($this->fixture.'/.git'));
        file_put_contents($this->fixture.'/.git', "gitdir: {$real}\n");

        $this->assertSame(self::SHA_A, $this->service()->current()['commit_hash']);
    }

    public function test_a_linked_worktree_resolves_its_ref_through_commondir(): void
    {
        // The real layout, which the fixture above did NOT model: a linked worktree
        // keeps its own HEAD but holds NO refs/ of its own -- refs live in the shared
        // git directory named by `commondir`. Found by running version:refresh inside
        // this very worktree and getting "unknown" from a healthy checkout, after the
        // looser fixture above had already passed.
        $shared = $this->fixture.'/shared.git';
        mkdir($shared.'/refs/heads', 0755, true);
        file_put_contents($shared.'/refs/heads/topic', self::SHA_A."\n");

        $wt = $shared.'/worktrees/leg';
        mkdir($wt, 0755, true);
        file_put_contents($wt.'/HEAD', "ref: refs/heads/topic\n");
        file_put_contents($wt.'/commondir', "../..\n");   // relative, as git writes it

        exec('rm -rf '.escapeshellarg($this->fixture.'/.git'));
        file_put_contents($this->fixture.'/.git', "gitdir: {$wt}\n");

        $v = $this->service()->current();

        $this->assertSame(self::SHA_A, $v['commit_hash'], 'refs must resolve through commondir');
        $this->assertSame('topic', $v['branch']);
    }

    // ---------------------------------------------------------- failure path

    public function test_a_failed_read_returns_the_unknown_sentinel_and_never_a_blank(): void
    {
        // The shipped defect: Process::run() RETURNS on non-zero exit instead of
        // throwing, so the catch arm never ran and empty strings were returned and
        // cached. Asserting "not blank" is the point -- a blank is truthy and so
        // reaches a surface as though it were an answer.
        exec('rm -rf '.escapeshellarg($this->fixture.'/.git'));

        Log::spy();

        $v = $this->service()->current();

        foreach (['commit_hash', 'commit_short', 'branch'] as $key) {
            $this->assertSame(VersionService::UNKNOWN, $v[$key], "{$key} must carry the sentinel");
            $this->assertNotSame('', $v[$key], "{$key} must never be an empty string");
        }
        $this->assertNotNull($v['error'], 'a failed read must say why');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($m) => is_string($m) && str_contains($m, '[Version]'))
            ->once();
    }

    public function test_an_unresolvable_ref_is_a_failure_not_a_blank(): void
    {
        // HEAD names a branch with neither a loose ref nor a packed entry.
        file_put_contents($this->fixture.'/.git/HEAD', "ref: refs/heads/main\n");

        $v = $this->service()->current();

        $this->assertSame(VersionService::UNKNOWN, $v['commit_hash']);
        $this->assertStringContainsString('packed-refs', (string) $v['error']);
    }

    public function test_a_garbage_head_is_a_failure_not_a_partial_answer(): void
    {
        file_put_contents($this->fixture.'/.git/HEAD', "not-a-sha-or-a-ref\n");

        $this->assertSame(VersionService::UNKNOWN, $this->service()->current()['commit_hash']);
    }

    // ------------------------------------------------------------- no cache

    public function test_a_moved_head_is_reported_with_no_refresh_step(): void
    {
        // This is the control for the ruling's second acceptance check. The old code
        // cached the sha for 24h and scripts/deploy.sh never cleared the key, so after
        // such a deploy a WORKING read reported the previous commit until the TTL
        // expired. Two calls with no
        // intervening refresh must disagree.
        file_put_contents($this->fixture.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->fixture.'/.git/refs/heads/main', self::SHA_A."\n");

        $first = $this->service()->current();
        $this->assertSame(self::SHA_A, $first['commit_hash']);

        file_put_contents($this->fixture.'/.git/refs/heads/main', self::SHA_B."\n");

        $second = $this->service()->current();
        $this->assertSame(self::SHA_B, $second['commit_hash'], 'the served commit must not be cached');
    }

    // --------------------------------------------------------------- footer

    public function test_the_footer_renders_the_badge_from_the_service(): void
    {
        file_put_contents($this->fixture.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->fixture.'/.git/refs/heads/main', self::SHA_A."\n");

        $html = view('components.footer')->render();

        $this->assertStringContainsString('v03005ea', $html);
    }

    public function test_the_footer_withholds_the_badge_rather_than_rendering_a_bare_v(): void
    {
        // The production symptom. The old guard tested truthiness, and an array of
        // empty strings is truthy, so it rendered "v" with nothing after it. Asserting
        // the absence of a bare marker is what a truthiness guard cannot satisfy.
        exec('rm -rf '.escapeshellarg($this->fixture.'/.git'));

        $html = view('components.footer')->render();

        $this->assertStringNotContainsString('>v<', $html);
        $this->assertStringNotContainsString('v'.VersionService::UNKNOWN, $html);
        $this->assertStringNotContainsString('route(\'about\')', $html);
        // Positive control: the footer still rendered, so the assertions above are
        // about the badge being withheld and not about an exception being thrown.
        $this->assertStringContainsString('site-footer', $html);
    }

    public function test_the_served_commit_is_not_written_to_the_cache(): void
    {
        // Asserts the mechanism, not just the effect: if any key holds the sha, a
        // future reader can pick it up and reintroduce the staleness.
        file_put_contents($this->fixture.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->fixture.'/.git/refs/heads/main', self::SHA_A."\n");

        $this->service()->current();

        $this->assertNull(\Illuminate\Support\Facades\Cache::get('psa_version_current'));
    }
}
