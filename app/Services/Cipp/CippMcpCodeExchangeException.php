<?php

namespace App\Services\Cipp;

/**
 * Microsoft refused the Connect CIPP MCP code exchange. Carries the sanitised
 * OAuth error CODE only (CippMcpConnector::errorCode(): e.g.
 * `invalid_grant / AADSTS53003`), never the response body, so the callback can
 * map it to plain English (CippMcpSignInErrors) without parsing a message.
 */
class CippMcpCodeExchangeException extends CippMcpAuthException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct('CIPP MCP code exchange was refused: '.$errorCode);
    }
}
