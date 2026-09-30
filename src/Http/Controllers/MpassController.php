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
    public function login(Request $request): void
    {
        // Pass an explicit returnTo so onelogin/php-saml does not fall back to
        // the current URL (/auth/mpass/login) as RelayState, which would cause
        // an infinite redirect loop after MPass sends the user back.
        $returnTo = $request->query('return_to')
            ?? url(config('mpass.redirects.after_login', '/'));

        $this->mpass->login($returnTo);
    }

    /**
     * Assertion Consumer Service — receives the signed SAML Response from MPass
     * via HTTP POST and authenticates the local user.
     *
     * GET|POST /auth/mpass/acs
     */
    public function acs(Request $request): RedirectResponse
    {
        $this->mpass->processResponse();

        $errors = $this->mpass->getErrors();

        if (!empty($errors)) {
            MpassLoginFailed::dispatch('SAML response contained errors.', $errors);

            return $this->failureRedirect('Authentication failed. Please try again.');
        }

        if (!$this->mpass->isAuthenticated()) {
            MpassLoginFailed::dispatch('User is not authenticated after processing SAML response.');

            return $this->failureRedirect('Authentication was cancelled or denied.');
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
        if ($request->has('SAMLRequest')) {
            return $this->handleInboundLogoutRequest($request);
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
     * to our SLS endpoint. We must terminate the local session and confirm.
     */
    private function handleInboundLogoutRequest(Request $request): Response|RedirectResponse
    {
        ['nameId' => $nameId, 'sessionIndex' => $sessionIndex] =
            $this->parseLogoutRequest($request->input('SAMLRequest', ''));

        if ($nameId) {
            $this->terminateSessionForNameId($nameId, $sessionIndex);
        }

        $this->mpass->processSLO();

        $errors = $this->mpass->getErrors();

        if (!empty($errors)) {
            return response('SLO error: ' . implode(', ', $errors), 400);
        }

        MpassLogoutCompleted::dispatch();

        return redirect(config('mpass.redirects.after_logout', '/'));
    }

    /**
     * Extracts NameID and SessionIndex from a base64-encoded SAMLRequest XML.
     *
     * @return array{nameId: string|null, sessionIndex: string|null}
     */
    private function parseLogoutRequest(string $encodedXml): array
    {
        $xml = base64_decode($encodedXml);

        if (!$xml) {
            return ['nameId' => null, 'sessionIndex' => null];
        }

        // Strip namespace prefixes so SimpleXML can traverse nodes uniformly.
        $xml  = preg_replace('/(<\/?)(\w+):([^>]*>)/', '$1$2$3', $xml);
        $node = @simplexml_load_string($xml);

        if (!$node) {
            return ['nameId' => null, 'sessionIndex' => null];
        }

        $data = json_decode(json_encode($node), true);

        return [
            'nameId'       => $data['samlNameID'] ?? null,
            'sessionIndex' => $data['samlpSessionIndex'] ?? null,
        ];
    }

    /** Terminate the Laravel session that belongs to the given NameID. */
    private function terminateSessionForNameId(string $nameId, ?string $sessionIndex): void
    {
        MpassLogoutInitiated::dispatch(null, $nameId, $sessionIndex);

        $sessionStore = app('session');
        $currentId    = $sessionStore->getId();
        $handler      = $sessionStore->getHandler();

        $raw  = $handler->read($currentId);
        $data = $raw ? @unserialize($raw) : [];

        $storedNameId = $data[config('mpass.session.name_id', 'mpass_name_id')] ?? null;

        if ($storedNameId === $nameId) {
            $handler->destroy($currentId);
        }
    }

    private function resolveUser(SamlUser $samlUser): ?Authenticatable
    {
        return app(UserResolverContract::class)->resolve($samlUser);
    }

    /**
     * Validate that RelayState is safe to redirect to:
     * - must differ from the current ACS URL (avoid redirect loops)
     * - must start with the application URL (prevent open redirect)
     * - must not point to a logout endpoint
     */
    private function isSafeRelayState(string $relayState, string $selfUrl): bool
    {
        // Reject the ACS URL itself to avoid redirect loops.
        if ($relayState === $selfUrl) {
            return false;
        }

        $appUrl = rtrim(config('app.url', ''), '/');

        // Reject external URLs to prevent open redirect attacks.
        if ($appUrl && !str_starts_with($relayState, $appUrl)) {
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

    private function failureRedirect(string $message): RedirectResponse
    {
        return redirect(config('mpass.redirects.on_failure', '/login'))
            ->with('mpass_error', $message);
    }
}
