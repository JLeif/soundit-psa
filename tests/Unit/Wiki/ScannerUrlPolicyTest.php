<?php

namespace Tests\Unit\Wiki;

use App\Services\Wiki\Mining\WikiRedactor;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\ScannerCoverage;

class ScannerUrlPolicyTest extends TestCase
{
    public function test_ordinary_urls_and_c1_identifiers_survive_both_consumers(): void
    {
        $r = new WikiRedactor;
        $rows = ScannerCoverage::urls() + [
            'hex' => str_repeat('abcdef01', 3),
            'sha' => str_repeat('abcdef01', 5),
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'serial' => 'ABCD1234EFGH5678IJKL9012MNOP3456',
        ];
        foreach ($rows as $id => $text) {
            $this->assertSame([], $r->scan($text), $id);
            $this->assertSame($text, $r->redact($text), $id);
        }
    }

    public function test_contextual_url_secrets_need_no_case_mix(): void
    {
        $r = new WikiRedactor;
        foreach (ScannerCoverage::signedUrls() as $id => $text) {
            $this->assertContains('credential', array_column($r->scan($text), 'class'), $id);
            $this->assertNotSame($text, $r->redact($text), $id);
            // The synthetic token itself must not remain after a partial match.
            $this->assertStringNotContainsString('SyntheticAbCd0123456789EfGhIjKlMnOp', $r->redact($text), $id);
            $this->assertStringNotContainsString(str_repeat('abcdef01', 4), $r->redact($text), $id);
            $this->assertStringNotContainsString(str_repeat('ABCDEF01', 4), $r->redact($text), $id);
        }
    }

    public function test_existing_secret_classes_remain_detected_without_keyword_masking(): void
    {
        $r = new WikiRedactor;
        $dashes = str_repeat('-', 5);
        foreach ([
            'pem' => $dashes.'BEGIN PRIVATE'.' KEY'.$dashes."\nsynthetic\n".$dashes.'END PRIVATE'.' KEY'.$dashes,
            'padded' => str_repeat('A', 40).'==',
            'jwt' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJzeW50aGV0aWMifQ.fake',
            'keyword' => 'password: synthetic-only',
            'connection' => 'mysql://synthetic:fake@db.example.test/demo',
            'plus-no-mix' => str_repeat('a', 28).'+'.str_repeat('b', 11),
            'slash-mix' => str_repeat('aB3', 10).'/'.str_repeat('cD4', 3),
        ] as $id => $text) {
            $this->assertContains('credential', array_column($r->scan($text), 'class'), $id);
            $this->assertNotSame($text, $r->redact($text), $id);
        }
    }

    public function test_mix_is_local_to_the_candidate_not_nearby_prose(): void
    {
        $r = new WikiRedactor;
        $text = 'Ticket ABC123 see '.ScannerCoverage::urls()['knowledgebase'].' End X9';
        $this->assertSame([], $r->scan($text));
        $this->assertSame($text, $r->redact($text));
    }

    public function test_secret_suffix_controls_require_a_real_extension_for_final_segment_exclusion(): void
    {
        $r = new WikiRedactor;
        // Seed sample 152 is detected only through its final mixed segment:
        // an unconditional final-segment exemption must fail this control.
        $finalOnly = base64_encode(substr(hash('sha256', ScannerCoverage::SEED.':152', true), 0, 30));
        foreach ([$finalOnly, $finalOnly.'.', str_repeat('Ab3', 10).'/'.str_repeat('Cd4', 3).'.json'] as $text) {
            $this->assertContains('credential', array_column($r->scan($text), 'class'));
            $this->assertNotSame($text, $r->redact($text));
        }
        // A trailing slash still terminates the mixed segment before the extension.
        $trailingSlash = base64_encode(substr(hash('sha256', ScannerCoverage::SEED.':96914', true), 0, 30)).'.json';
        $this->assertContains('credential', array_column($r->scan($trailingSlash), 'class'));
        $this->assertNotSame($trailingSlash, $r->redact($trailingSlash));
        // A real extension excludes only the last segment, never earlier ones.
        $this->assertSame([], $r->scan($finalOnly.'.json'));
        $this->assertSame($finalOnly.'.json', $r->redact($finalOnly.'.json'));
    }

    public function test_long_slash_run_is_linear_and_has_no_engine_error(): void
    {
        $r = new WikiRedactor;
        foreach ([str_repeat('aB3', 10).'+Cd4', str_repeat('aB3', 10).'/Cd4', ' '.str_repeat('aB3', 10).'+Cd4'] as $token) {
            $text = str_repeat('a/', 30000).$token;
            $start = microtime(true);
            $this->assertContains('credential', array_column($r->scan($text), 'class'));
            $this->assertSame(PREG_NO_ERROR, preg_last_error());
            $this->assertStringNotContainsString($token, $r->redact($text));
            $this->assertSame(PREG_NO_ERROR, preg_last_error());
            $this->assertLessThan(1.0, microtime(true) - $start);
        }
    }

    public function test_every_scan_pattern_engine_error_is_a_credential_violation(): void
    {
        $this->assertEngineErrors('scan', 17);
    }

    public function test_every_redact_pattern_engine_error_withholds_the_whole_text(): void
    {
        $this->assertEngineErrors('redact', 9);
    }

    private function assertEngineErrors(string $method, int $count): void
    {
        $process = new \Symfony\Component\Process\Process([PHP_BINARY, dirname(__DIR__, 2).'/Fixtures/scanner-engine-error.php', $method]);
        $process->mustRun();
        $data = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame((new \ReflectionClass(WikiRedactor::class))->getFileName(), $data['source']);
        $this->assertSame($method === 'scan' ? [] : 'ordinary prose', $data['normal']);
        $this->assertCount($count, $data['rows']);
        foreach ($data['rows'] as $row) {
            $this->assertSame(1, $row['calls']);
            if ($method === 'scan') {
                $this->assertContains('credential', array_column($row['result'], 'class'));
            } else {
                $this->assertSame('[REDACTED:credential]', $row['result']);
            }
        }
    }

    public function test_random_corpus_pins_newly_admitted_cases_not_a_claim_of_zero_misses(): void
    {
        $old = '/\b[A-Za-z0-9+\/_-]{24,}[+\/]+[A-Za-z0-9+\/_-]*={0,2}\b/';
        $r = new WikiRedactor;
        $newlyAdmitted = [];
        $detected = 0;
        foreach (ScannerCoverage::randomBase64() as $id => $text) {
            $hit = $r->scan($text) !== [];
            $detected += (int) $hit;
            $this->assertSame($hit, $r->redact($text) !== $text, 'scan/redact parity '.$id);
            if (preg_match($old, $text) && ! $hit) {
                $newlyAdmitted[] = $id;
            }
        }
        $this->assertSame(37567, $detected);
        $this->assertCount(45, $newlyAdmitted);
        $this->assertSame('88d35b49ab2536476f6b711a4e84387d90fc1945ffdb3c3d39f62dd9c0d6b73c', hash('sha256', implode(',', $newlyAdmitted)));
    }
}
