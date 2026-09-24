<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')
                ->prefix('portal')
                ->group(base_path('routes/portal.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '127.0.0.1');
        $middleware->web(append: [
            \App\Http\Middleware\CheckStorageHealth::class,
        ]);
        $middleware->alias([
            'admin' => \App\Http\Middleware\RequireAdmin::class,
            'assistant.enabled' => \App\Http\Middleware\AssistantEnabled::class,
            'portal.enabled' => \App\Http\Middleware\PortalEnabled::class,
            'portal.auth' => \App\Http\Middleware\PortalAuthenticate::class,
            'portal.scope' => \App\Http\Middleware\PortalClientScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Never flash a credential back into the session.
        //
        // A failed validate() redirects withInput(), and the framework's
        // own $dontFlash list carries exactly three entries:
        // current_password, password and password_confirmation. Every other
        // submitted field -- including an API key, a webhook secret or an
        // admin password -- is written to the session's _old_input so the
        // form can be repopulated. SESSION_ENCRYPT is false, so that value
        // is serialised to the session driver in the clear and outlives the
        // request that failed by the length of the session.
        //
        // This is registered once, here, rather than guarded per action.
        // The alternative is a hand-written strip in every controller method
        // that accepts a secret, which is a rule enforced by ~20 separate
        // remembering-to-do-its and silently absent from the next one
        // written. Registering it against the handler covers every validation
        // failure, FormRequests included, and ones that do not exist yet. It
        // does NOT cover an action that calls ->withInput() itself:
        // RedirectResponse::withInput() flashes the whole request and never
        // consults this list, so such an action must strip the secret
        // locally, as PreferencesController does for sip_password.
        //
        // A local strip is still correct where an action wants the secret
        // out of the request entirely rather than only out of the flash;
        // LITSRMM does that. This list is the floor, not a replacement.
        //
        // Names are the validated field names, not the Setting keys they are
        // stored under. Deliberately excluded: assistant_daily_token_limit,
        // triage_daily_token_limit, triage_max_tokens_per_run and max_tokens
        // (integer budgets), generate_secret and has_secret (booleans), and
        // mcp_token_label (a display label). Those carry a credential-shaped
        // NAME and no credential; suppressing them would break form
        // repopulation for no gain.
        $exceptions->dontFlash([
            'api_key',
            'api_secret',
            'api_token',
            'auth_token',
            'client_secret',
            'comet_admin_password',
            'comet_backup_password',
            'credentials',
            'hdb_password',
            'hdb_totp_secret',
            'install_account_token',
            'mcp_client_secret',
            'openai_api_key',
            'secret',
            'secret_key',
            'signing_secret',
            'sip_password',
            'teams_bot_client_secret',
            'technician_teams_webhook_url',
            'totp_secret',
            'wake_secret',
            'webhook_secret',
            'webhook_verifier_token',
        ]);
    })->create();
