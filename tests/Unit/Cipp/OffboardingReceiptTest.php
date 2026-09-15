<?php

namespace Tests\Unit\Cipp;

use App\Services\Cipp\Offboarding\OffboardingReceipt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OffboardingReceiptTest extends TestCase
{
    private function response(): array
    {
        return ['status' => 200, 'body' => ['Results' => ['Task Offboarding: leaver@example.test scheduled to run now'], 'DeploymentId' => '11111111-1111-4111-8111-111111111111']];
    }

    public function test_queue_acceptance_is_not_execution(): void
    {
        $this->assertSame(['admission' => 'accepted', 'response_class' => 'queue_reported', 'http_status' => 200], OffboardingReceipt::classify($this->response(), 'leaver@example.test'));
    }

    public static function malformed(): array
    {
        return [[null], [false], [[]], [['Error - failed']], [['Task Offboarding: leaver@example.test scheduled to run now', true]], ['Task Offboarding: leaver@example.test scheduled to run now'], [['Task Offboarding: other@example.test scheduled to run now']], [['prefix Task Offboarding: leaver@example.test scheduled to run now']]];
    }

    #[DataProvider('malformed')]
    public function test_malformed_or_error_is_ambiguous(mixed $results): void
    {
        $response = $this->response();
        $response['body']['Results'] = $results;
        $this->assertSame('ambiguous', OffboardingReceipt::classify($response, 'leaver@example.test')['admission']);
    }

    public function test_missing_correlation_is_not_retryable(): void
    {
        $response = $this->response();
        unset($response['body']['DeploymentId']);
        $this->assertSame('queue_reported_uncorrelated', OffboardingReceipt::classify($response, 'leaver@example.test')['response_class']);
    }

    public function test_errors_and_non_integer_status_never_accept(): void
    {
        foreach ([400, 401, 403, 429, 500, 503, '200', null] as $status) {
            $this->assertSame('ambiguous', OffboardingReceipt::classify(array_replace($this->response(), ['status' => $status]), 'leaver@example.test')['admission']);
        }
    }
}
