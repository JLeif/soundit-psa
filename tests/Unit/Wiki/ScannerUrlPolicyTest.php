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
        $this->assertSame(37593, $detected);
        $this->assertSame([1413, 10453, 17176, 19899, 22118, 29051, 38721, 38931, 39303, 43865, 45460, 45878, 63673, 64374, 66616, 68602, 68865, 74334, 79215], $newlyAdmitted);
    }
}
