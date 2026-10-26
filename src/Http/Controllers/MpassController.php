<?php

namespace Mpass\Http\Controllers;

use Mpass\Contracts\UserResolverContract;
use Mpass\Events\MpassAuthenticated;
use Mpass\Events\MpassLoginFailed;
use Mpass\Events\MpassLogoutCompleted;
use Mpass\Events\MpassLogoutFailed;
use Mpass\Events\MpassLogoutInitiated;
use Mpass\MpassManager;
use Mpass\SamlUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use OneLogin\Saml2\Utils;

class MpassController extends Controller
{
    public function __construct(protected MpassManager $mpass) {}

    /**
     * Redirect the browser to MPass to begin authentication.
     *
     * GET /auth/mpass/login
     */
    public function login(Request $request): RedirectResponse
    {
        // Pass an explicit returnTo so onelogin/php-saml does not fall back to
        // the current URL (/auth/mpass/login) as RelayState, which would cause
        // an infinite redirect loop after MPass sends the user back.
        $returnTo = $request->query('return_to');

        if (!is_string($returnTo) || !$this->isSafeRedirect($returnTo)) {
            $returnTo = url(config('mpass.redirects.after_login', '/'));
        }

        $url = $this->mpass->loginUrl($returnTo);

        // Remembered so acs() only accepts the response to this very request.
        $request->session()->put(
            config('mpass.session.request_id', 'mpass_request_id'),
            $this->mpass->getLastRequestId(),
        );

        return redirect()->away($url);
    }

    /**
     * Assertion Consumer Service — receives the signed SAML Response from MPass
     * via HTTP POST and authenticates the local user.
     *
     * GET|POST /auth/mpass/acs
     */
    public function acs(Request $request): RedirectResponse
    {
        $requestId = $request->session()->pull(config('mpass.session.request_id', 'mpass_request_id'));

        $this->mpass->processResponse(is_string($requestId) ? $requestId : null);

        $errors = $this->mpass->getErrors();

        if (!empty($errors)) {
            MpassLoginFailed::dispatch('SAML response contained errors.', $errors);

            return $this->failureRedirect('Authentication failed. Please try again.');
        }

        if (!$this->mpass->isAuthenticated()) {
            MpassLoginFailed::dispatch('User is not authenticated after processing SAML response.');

            return $this->failureRedirect('Authentication was cancelled or denied.');
        }

        if (!$this->mpass->markAssertionAsUsed()) {
            MpassLoginFailed::dispatch('SAML assertion has already been used (possible replay).');

            return $this->failureRedirect('Authentication failed. Please try again.');
        }

        $samlUser = new SamlUser(
            nameId: $this->mpass->getNameId(),
            sessionIndex: $this->mpass->getSessionIndex(),
            rawAttributes: $this->mpass->getAttributes(),
        );

        $user = $this->resolveUser($samlUser);

        if ($user === null) {
            MpassLoginFailed::dispatch('User resolver returned null for NameID: ' . $samlUser->nameId);

            return $this->failureRedirect('Your account was not found or access is not allowed.');
        }

        Auth::login($user);

        Session::put(config('mpass.session.name_id', 'mpass_name_id'), $samlUser->nameId);
        Session::put(config('mpass.session.session_index', 'mpass_session_index'), $samlUser->sessionIndex);
        Session::put(config('mpass.session.name_id_format', 'mpass_name_id_format'), $this->mpass->getNameIdFormat());
        Session::put(config('mpass.session.name_id_name_qualifier', 'mpass_name_id_name_qualifier'), $this->mpass->getNameIdNameQualifier());
        Session::put(config('mpass.session.name_id_sp_name_qualifier', 'mpass_name_id_sp_name_qualifier'), $this->mpass->getNameIdSPNameQualifier());

        MpassAuthenticated::dispatch($user, $samlUser);

        $relayState = $request->input('RelayState');
        $selfUrl    = Utils::getSelfURL();

        $destination = ($relayState && $this->isSafeRelayState($relayState, $selfUrl))
            ? $relayState
            : config('mpass.redirects.after_login', '/');

        return redirect($destination);
    }

    /**
     * Single Logout — initiates the SLO flow by redirecting to MPass.
     *
     * GET /auth/mpass/slo
     */
    public function slo(Request $request): RedirectResponse
    {
        $nameId       = Session::get(config('mpass.session.name_id', 'mpass_name_id'), '');
        $sessionIndex = Session::get(config('mpass.session.session_index', 'mpass_session_index'));
        $nameIdFormat = Session::get(config('mpass.session.name_id_format', 'mpass_name_id_format'));
        $nameQualifier = Session::get(config('mpass.session.name_id_name_qualifier', 'mpass_name_id_name_qualifier'));
        $spNameQualifier = Session::get(config('mpass.session.name_id_sp_name_qualifier', 'mpass_name_id_sp_name_qualifier'));

        if (!$nameId) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect(config('mpass.redirects.after_logout', '/'));
        }

        MpassLogoutInitiated::dispatch(Auth::user(), $nameId, $sessionIndex);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $this->mpass->logout(
            returnTo: config('mpass.redirects.after_logout', '/'),
            nameId: $nameId,
            sessionIndex: $sessionIndex ?? '',
            nameIdFormat: $nameIdFormat,
            nameIdNameQualifier: $nameQualifier,
            nameIdSPNameQualifier: $spNameQualifier,
        );

        // logout() issues a redirect; execution stops here.
        return redirect('/'); // @phpstan-ignore-line
    }

    /**
     * Single Logout Service — receives inbound SLO requests/responses from MPass.
     *
     * GET|POST /auth/mpass/sls
     */
    public function sls(Request $request): Response|RedirectResponse
    {
        if ($request->query->has('SAMLRequest')) {
            return $this->handleInboundLogoutRequest($request);
        }

        // onelogin/php-saml validates SLO messages only in the HTTP-Redirect
        // binding. A POSTed message cannot be verified, so it must not touch
        // the local session.
        if (!$request->query->has('SAMLResponse')) {
            MpassLogoutFailed::dispatch('Unsupported SLO binding: only HTTP-Redirect is supported.');

            if ($request->request->has('SAMLRequest')) {
                return response('SLO error: unsupported binding', 400);
            }

            return redirect(config('mpass.redirects.after_logout', '/'));
        }

        $this->mpass->processSLO();

        $errors = $this->mpass->getErrors();

        // The local session was already terminated in slo(), so an unsuccessful
        // LogoutResponse (e.g. the IdP session had already expired) must not
        // strand the user on an error page.
        if (!empty($errors)) {
            MpassLogoutFailed::dispatch('SAML logout response contained errors.', $errors);

            return redirect(config('mpass.redirects.after_logout', '/'));
        }

        MpassLogoutCompleted::dispatch();

        return redirect(config('mpass.redirects.after_logout', '/'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * When MPass initiates logout (e.g. from another SP), it sends a SAMLRequest
     * to our SLS endpoint. Once the request is validated we terminate the local
     * session and answer MPass with a LogoutResponse.
     */
    private function handleInboundLogoutRequest(Request $request): Response|RedirectResponse
    {
        // onelogin checks the signature only when one is present; its
        // wantMessagesSigned switch cannot be used because it would also
        // demand a signed <Response> on login.
        if (!$request->query->has('Signature')) {
            MpassLogoutFailed::dispatch('SAML logout request is not signed.', ['unsigned_logout_request']);

            return response('SLO error: unsigned_logout_request', 400);
        }

        $responseUrl = $this->mpass->processLogoutRequest(
            fn (string $nameId, array $sessionIndexes) => $this->terminateSessionForNameId($request, $nameId, $sessionIndexes),
        );

        $errors = $this->mpass->getErrors();

        if (!empty($errors) || $responseUrl === null) {
            MpassLogoutFailed::dispatch('SAML logout request was rejected.', $errors);

            return response('SLO error: ' . implode(', ', $errors), 400);
        }

        MpassLogoutCompleted::dispatch();

        return redirect()->away($responseUrl);
    }

    /**
     * Terminate the current Laravel session if it belongs to the given NameID.
     *
     * @param list<string> $sessionIndexes
     */
    private function terminateSessionForNameId(Request $request, string $nameId, array $sessionIndexes): void
    {
        $storedNameId = $request->session()->get(config('mpass.session.name_id', 'mpass_name_id'));

        if ($storedNameId === null || !hash_equals((string) $storedNameId, $nameId)) {
            return;
        }

        MpassLogoutInitiated::dispatch(Auth::user(), $nameId, $sessionIndexes[0] ?? null);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function resolveUser(SamlUser $samlUser): ?Authenticatable
    {
        return app(UserResolverContract::class)->resolve($samlUser);
    }

    /**
     * Validate that RelayState is safe to redirect to:
     * - must differ from the current ACS URL (avoid redirect loops)
     * - must stay on the application (prevent open redirect)
     * - must not point to a logout endpoint
     */
    private function isSafeRelayState(string $relayState, string $selfUrl): bool
    {
        // Reject the ACS URL itself to avoid redirect loops.
        if ($relayState === $selfUrl) {
            return false;
        }

        // Reject external URLs to prevent open redirect attacks.
        if (!$this->isSafeRedirect($relayState)) {
            return false;
        }

        $path = strtolower(parse_url($relayState, PHP_URL_PATH) ?? '');

        // Reject the login URL — redirecting back to it would restart the SSO loop.
        $loginPath = '/' . trim(config('mpass.routes.prefix', 'auth/mpass'), '/') . '/login';
        if (str_ends_with($path, $loginPath)) {
            return false;
        }

        // Reject logout paths to prevent MethodNotAllowedHttpException.
        foreach (['logout', 'logoff', 'signout', 'sign-out'] as $keyword) {
            if (str_contains($path, $keyword)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Accept only a local path ("/dashboard") or an absolute URL whose scheme,
     * host and port match APP_URL. A prefix check is not enough:
     * "https://app.example.com.evil.com" and "https://app.example.com@evil.com"
     * both start with "https://app.example.com".
     */
    private function isSafeRedirect(string $url): bool
    {
        // Browsers treat a backslash like "/" and strip control characters and
        // whitespace, which turns e.g. "/\evil.com" into a protocol-relative URL.
        if ($url === '' || preg_match('/[\\\\\x00-\x20\x7F]/', $url)) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//');
        }

        $target = parse_url($url);
        $app    = parse_url((string) config('app.url', ''));

        if (!$target || !$app || empty($app['host']) || empty($target['host'])) {
            return false;
        }

        if (isset($target['user']) || isset($target['pass'])) {
            return false;
        }

        return strtolower($target['scheme'] ?? '') === strtolower($app['scheme'] ?? '')
            && strtolower($target['host']) === strtolower($app['host'])
            && ($target['port'] ?? null) === ($app['port'] ?? null);
    }

    private function failureRedirect(string $message): RedirectResponse
    {
        return redirect(config('mpass.redirects.on_failure', '/login'))
            ->with('mpass_error', $message);
    }
}
