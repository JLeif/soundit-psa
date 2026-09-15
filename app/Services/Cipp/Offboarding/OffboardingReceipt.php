<?php

namespace App\Services\Cipp\Offboarding;

final class OffboardingReceipt
{
    /** Queue admission only; even a positive result never attests execution or effects. */
    public static function classify(mixed $response, string $target): array
    {
        $status = is_array($response) && is_int($response['status'] ?? null) ? $response['status'] : null;
        if ($status !== null && ($status < 100 || $status > 599)) {
            $status = null;
        }
        $unknown = ['admission' => 'ambiguous', 'response_class' => 'response_unknown', 'http_status' => $status];
        if ($status !== 200 || ! is_array($response['body'] ?? null)) {
            return $unknown;
        }
        $body = $response['body'];
        if (array_diff(array_keys($body), ['Results', 'DeploymentId']) || ! is_array($body['Results'] ?? null)
            || ! array_is_list($body['Results']) || count($body['Results']) !== 1
            || $body['Results'][0] !== "Task Offboarding: {$target} scheduled to run now") {
            return $unknown;
        }
        if (! is_string($body['DeploymentId'] ?? null)
            || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $body['DeploymentId'])) {
            return [...$unknown, 'response_class' => 'queue_reported_uncorrelated'];
        }

        return ['admission' => 'accepted', 'response_class' => 'queue_reported', 'http_status' => $status];
    }
}
