<?php

namespace App\Services\Cipp;

/**
 * CippMcpClient could not authenticate to ExecMCP: no MCP credentials are
 * configured, the OAuth token request failed or returned no access_token, or
 * ExecMCP answered HTTP 401.
 *
 * Distinct from its parent so a caller can tell "the MCP transport cannot sign
 * in" (CIPP did not run the query, so another transport may ask it) from
 * "CIPP answered with an error" (the question WAS asked; retrying elsewhere
 * would hide that answer). The curated read tools fail over to the REST
 * CippClient on this type only — see AssistantToolExecutor::cippMcpRelay().
 */
class CippMcpAuthException extends CippClientException {}
