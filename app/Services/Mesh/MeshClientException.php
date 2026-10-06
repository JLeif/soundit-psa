<?php

namespace App\Services\Mesh;

/**
 * A Mesh call that did not succeed. The code is the HTTP status Mesh answered
 * with, or 0 when there is none (see MeshWriteClient::request()).
 *
 * The MESSAGE is not safe to surface: for an upstream failure it wraps a
 * Guzzle message, which quotes the request URI and a summary of the vendor's
 * response body (C-56). Callers that report a failure to a person, a stored
 * field or a log line use statusPhrase(), which carries the status only.
 */
class MeshClientException extends \RuntimeException
{
    /**
     * The failure as a status-only phrase, e.g. "Mesh answered the rule list
     * read with HTTP 503", or "the rule list read failed without an HTTP
     * status from Mesh" when there is no status. Nothing from the message.
     *
     * @param  string  $what  the call, as a noun phrase ("the rule list read")
     */
    public function statusPhrase(string $what): string
    {
        $status = (int) $this->getCode();

        return $status > 0
            ? "Mesh answered {$what} with HTTP {$status}"
            : "{$what} failed without an HTTP status from Mesh";
    }
}
