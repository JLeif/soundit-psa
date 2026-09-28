<?php

namespace App\Services\Tactical;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;
use Throwable;

/**
 * Raised for any failed Tactical API call. Carries the STRUCTURED signal the
 * action bus (P2 §11/M2) classifies on:
 *
 *   - A transport failure — a Guzzle ConnectException / timeout with no HTTP
 *     response (code 0) — is offline-classifiable (the agent / NATS is
 *     unreachable). isTransportFailure() === true; statusCode() === null.
 *   - An HTTP response error (401/403/404/5xx) carries its status + body.
 *     isTransportFailure() === false. These must NEVER be collapsed to
 *     "offline" — a 403 is an auth failure / possible key compromise.
 */
class TacticalClientException extends RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        private readonly ?int $statusCode = null,
        private readonly ?string $responseBody = null,
        private readonly bool $transportFailure = false,
        private readonly bool $timedOutAfterSend = false,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Build from a caught Guzzle exception, deriving the structured signal.
     *
     * A RequestException with a response => an HTTP error (carries status/body,
     * not a transport failure). Anything else (ConnectException, timeouts,
     * TooManyRedirectsException — none carry a usable response) => transport
     * failure.
     *
     * SECURITY: the $message parameter is intentionally IGNORED — it may carry
     * the Guzzle exception message, which Guzzle's BodySummarizer embeds a
     * ~120-byte summary of the HTTP response body into. A Tactical URLAction
     * endpoint serializes fields="__all__" and echoes rest_headers (containing
     * X-Webhook-Key) in validation-error bodies, so the Guzzle message leaks
     * the key verbatim. The exception MESSAGE is always built from the HTTP
     * status only (body-free). Callers that explicitly need the raw body must
     * use responseBody() — that accessor is preserved for behavior, not logging.
     */
    public static function fromGuzzle(string $message, Throwable $e): self
    {
        $status = null;
        $body = null;
        $transport = true;

        if ($e instanceof RequestException && $e->hasResponse()) {
            $response = $e->getResponse();
            $status = $response?->getStatusCode();
            $body = $response !== null ? (string) $response->getBody() : null;
            $transport = false;
        }

        // Build a body-free message from the HTTP status only — never from
        // $message or $e->getMessage(), which may embed the response body.
        $safeMessage = $status !== null
            ? "Tactical API error (HTTP {$status})"
            : 'Tactical API error (transport failure)';

        return new self($safeMessage, $e->getCode(), $e, $status, $body, $transport, $transport && self::timedOutAfterRequestSent($e));
    }

    /**
     * #3971: cURL 28 arrives as a ConnectException both when the connection never
     * opened and when the request went out and no answer came back in time. The
     * handler context separates them: cURL's request_size (CURLINFO_REQUEST_SIZE)
     * is the size of the request it issued, and is 0 when the timeout fired
     * before the connection and any TLS handshake completed. A context without
     * those keys proves nothing, so it answers false.
     */
    private static function timedOutAfterRequestSent(Throwable $e): bool
    {
        if (! $e instanceof ConnectException && ! $e instanceof RequestException) {
            return false;
        }

        $context = $e->getHandlerContext();

        return ($context['errno'] ?? null) === CURLE_OPERATION_TIMEDOUT
            && is_int($context['request_size'] ?? null)
            && $context['request_size'] > 0;
    }

    /**
     * True when the failure is a transport / connectivity problem (no HTTP
     * response) — the bus may classify this as `offline`.
     */
    public function isTransportFailure(): bool
    {
        return $this->transportFailure;
    }

    /**
     * #3971: true only for a transport failure whose handler context shows the
     * request was written before the timeout fired, so the far end may have
     * acted on it. Never true for a failure that carried an HTTP response.
     */
    public function timedOutAfterSend(): bool
    {
        return $this->timedOutAfterSend;
    }

    /**
     * The HTTP status code when the failure carried a response, else null.
     */
    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * The raw HTTP response body when present, else null.
     */
    public function responseBody(): ?string
    {
        return $this->responseBody;
    }
}
