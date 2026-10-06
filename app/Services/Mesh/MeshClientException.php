<?php

namespace App\Services\Mesh;

/**
 * A Mesh call that did not succeed. The code is the HTTP status Mesh answered
 * with, or 0 when there is none (see MeshWriteClient::request()).
 *
 * Two kinds share this class. An UPSTREAM failure is built by
 * MeshWriteClient::request() as "Mesh API error: <Guzzle message>", and that
 * message quotes the request URI and a summary of the vendor's response body
 * (C-56), so it is not safe to surface. A failure the client DETECTS ITSELF
 * (a page ceiling, a row-count mismatch, a missing tenant or key) is written
 * by the PSA, quotes no vendor text, and may have happened after every call
 * answered HTTP 200. Callers that report a failure to a person, a stored field
 * or a log line use statusPhrase(), which tells the two apart.
 */
class MeshClientException extends \RuntimeException
{
    /** The prefix MeshWriteClient::request() puts on a wrapped Guzzle message. */
    public const UPSTREAM_PREFIX = 'Mesh API error';

    /**
     * The failure as a phrase safe to report. With an HTTP status: "Mesh
     * answered the rule list read with HTTP 503". An upstream failure without
     * one: "the rule list read failed without an HTTP status from Mesh" —
     * nothing from its message. A failure the client detected itself: its own
     * message, which is the real cause and carries no vendor text.
     *
     * @param  string  $what  the call, as a noun phrase ("the rule list read")
     */
    public function statusPhrase(string $what): string
    {
        $status = (int) $this->getCode();

        if ($status > 0) {
            return "Mesh answered {$what} with HTTP {$status}";
        }

        if ($this->raisedByClient()) {
            return $this->getMessage();
        }

        return "{$what} failed without an HTTP status from Mesh";
    }

    /**
     * True only for a message the client wrote itself: not request()'s wrap,
     * not chained to another exception, and naming no URI. Anything else is
     * treated as carrying vendor text.
     */
    private function raisedByClient(): bool
    {
        $message = $this->getMessage();

        return $this->getPrevious() === null
            && trim($message) !== ''
            && ! str_starts_with($message, self::UPSTREAM_PREFIX)
            && ! str_contains($message, '://');
    }
}
