<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Cipp\CippMcpAuthException;
use App\Services\Cipp\CippMcpCodeExchangeException;
use App\Services\Cipp\CippMcpConnector;
use App\Services\Cipp\CippMcpSignInErrors;
use App\Support\CippConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * "Connect CIPP MCP": the one-time authorization-code + PKCE sign-in that gives
 * the PSA a delegated CIPP MCP connection (CIPP v11+, card 6abc4adc leg 2).
 *
 * Same shape as AppRiverOAuthController / QboController: a random CSRF state in
 * the session, checked on the callback. PKCE adds a code_verifier, also held in
 * the session, sent to Microsoft only as its S256 challenge and only as itself in
 * the code exchange. No token, code or verifier is logged or flashed; vendor
 * failures are reported by error code only.
 */
class CippMcpOAuthController extends Controller
{
    private const STATE_KEY = 'cipp_mcp_oauth_state';

    private const VERIFIER_KEY = 'cipp_mcp_oauth_verifier';

    public function redirect()
    {
        $tenantId = (string) CippConfig::get('tenant_id');
        $clientId = (string) CippConfig::get('mcp_client_id');

        if ($tenantId === '' || $clientId === '' || (string) CippConfig::get('mcp_backend_host') === '') {
            return redirect()->route('settings.integrations')
                ->with('error', 'CIPP MCP is not set up for sign-in. Save the Azure AD Tenant ID, MCP Client ID and MCP backend host (Application ID URI) first.');
        }

        $state = Str::random(40);
        $verifier = CippMcpConnector::newCodeVerifier();
        session([self::STATE_KEY => $state, self::VERIFIER_KEY => $verifier]);

        return redirect(CippMcpConnector::authorizeUrl($tenantId, [
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => route('auth.cipp-mcp.callback'),
            'response_mode' => 'query',
            'scope' => CippMcpConnector::signInScope(),
            'state' => $state,
            'code_challenge' => CippMcpConnector::codeChallenge($verifier),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ]));
    }

    public function callback(Request $request, CippMcpConnector $connector)
    {
        $expectedState = session()->pull(self::STATE_KEY);
        $verifier = session()->pull(self::VERIFIER_KEY);
        $receivedState = $request->query('state');

        if (! is_string($expectedState) || $expectedState === ''
            || ! is_string($receivedState) || ! hash_equals($expectedState, $receivedState)) {
            Log::warning('[CIPP MCP OAuth] State mismatch', [
                'expected_present' => is_string($expectedState) && $expectedState !== '',
                'received_present' => is_string($receivedState) && $receivedState !== '',
            ]);
            abort(400, 'Invalid OAuth state parameter.');
        }

        if (! is_string($verifier) || $verifier === '') {
            Log::warning('[CIPP MCP OAuth] PKCE code_verifier missing from the session');
            abort(400, 'Missing PKCE verifier; start the connection again.');
        }

        if ($request->query('error')) {
            $code = CippMcpConnector::sanitizeCode((string) $request->query('error'));
            // Entra puts the AADSTS number only in error_description. Take the
            // NUMBER from it and nothing else: the description text is never
            // logged or flashed.
            if (preg_match('/\bAADSTS\d{4,}\b/', (string) $request->query('error_description'), $m)) {
                $code = ($code !== 'unknown' ? $code.' / ' : '').$m[0];
            }
            Log::warning('[CIPP MCP OAuth] Authorization error', ['error' => $code]);

            return redirect()->route('settings.integrations')
                ->with('error', CippMcpSignInErrors::message($code, atCodeExchange: false));
        }

        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            return redirect()->route('settings.integrations')
                ->with('error', 'CIPP MCP sign-in was cancelled or failed.');
        }

        try {
            $connector->exchangeCode($code, $verifier, route('auth.cipp-mcp.callback'));
        } catch (CippMcpCodeExchangeException $e) {
            // Microsoft refused the exchange: a sanitised CODE only, mapped to plain English.
            Log::warning('[CIPP MCP OAuth] Code exchange failed', ['error' => $e->errorCode]);

            return redirect()->route('settings.integrations')
                ->with('error', CippMcpSignInErrors::message($e->errorCode, atCodeExchange: true));
        } catch (CippMcpAuthException $e) {
            // Every other CippMcpAuthException exchangeCode() throws is PSA-authored
            // text (not configured / could not reach Microsoft (<class>) / no refresh
            // token); none carries a vendor body or exception message.
            Log::warning('[CIPP MCP OAuth] Code exchange failed', ['error' => $e->getMessage()]);

            return redirect()->route('settings.integrations')
                ->with('error', 'Could not connect CIPP MCP: '.$e->getMessage());
        }

        $status = $connector->status();
        Log::info('[CIPP MCP OAuth] Connected', ['upn' => $status['upn']]);

        return redirect()->route('settings.integrations')
            ->with('success', 'CIPP MCP connected'.($status['upn'] ? ' as '.$status['upn'] : '').'.');
    }
}
