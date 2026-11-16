<?php

namespace Mpass\Tests\Feature;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Mpass\Events\MpassAuthenticated;
use Mpass\Events\MpassLoginFailed;
use PHPUnit\Framework\Attributes\DataProvider;

class AcsTest extends ControllerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([MpassAuthenticated::class, MpassLoginFailed::class]);
        $this->fakeIncomingRequest('/auth/mpass/acs');
    }

    /** Configure the manager as if MPass returned a valid response. */
    private function validResponse(?string $requestId = '_request-1', bool $freshAssertion = true): void
    {
        $this->mpass->expects('processResponse')->with($requestId);
        $this->mpass->allows('getErrors')->andReturn([]);
        $this->mpass->allows('isAuthenticated')->andReturn(true);
        $this->mpass->allows('markAssertionAsUsed')->andReturn($freshAssertion);
        $this->mpass->allows('getNameId')->andReturn('2000000000001');
        $this->mpass->allows('getSessionIndex')->andReturn('_session-1');
        $this->mpass->allows('getAttributes')->andReturn(['FirstName' => ['Ion']]);
        $this->mpass->allows('getNameIdFormat')->andReturn('urn:oasis:names:tc:SAML:2.0:nameid-format:persistent');
        $this->mpass->allows('getNameIdNameQualifier')->andReturn('https://mpass.test');
        $this->mpass->allows('getNameIdSPNameQualifier')->andReturn(self::APP_URL);
    }

    private function postAcs(array $data = [], array $session = ['mpass_request_id' => '_request-1'])
    {
        return $this->withSession($session)->post('/auth/mpass/acs', $data + ['SAMLResponse' => 'x']);
    }

    public function test_successful_response_logs_user_in(): void
    {
        $user = $this->makeUser();
        $resolver = $this->resolveUserAs($user);
        $this->validResponse();

        $this->postAcs()
            ->assertRedirect('/')
            ->assertSessionHas('mpass_name_id', '2000000000001')
            ->assertSessionHas('mpass_session_index', '_session-1')
            ->assertSessionHas('mpass_name_id_format', 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent')
            ->assertSessionHas('mpass_name_id_name_qualifier', 'https://mpass.test')
            ->assertSessionHas('mpass_name_id_sp_name_qualifier', self::APP_URL)
            ->assertSessionMissing('mpass_request_id');

        $this->assertAuthenticatedAs($user);
        $this->assertSame('2000000000001', $resolver->received->nameId);
        $this->assertSame('Ion', $resolver->received->firstName());

        Event::assertDispatched(MpassAuthenticated::class, fn ($e) => $e->user === $user && $e->samlUser->nameId === '2000000000001');
        Event::assertNotDispatched(MpassLoginFailed::class);
    }

    public function test_unsolicited_response_is_processed_without_request_id(): void
    {
        $this->resolveUserAs($this->makeUser());
        $this->validResponse(requestId: null);

        $this->postAcs(session: [])->assertRedirect('/');
    }

    public function test_request_id_is_single_use(): void
    {
        $this->resolveUserAs($this->makeUser());
        $this->validResponse();

        $this->postAcs()->assertSessionMissing('mpass_request_id');
    }

    public function test_saml_errors_redirect_to_failure(): void
    {
        $this->mpass->expects('processResponse');
        $this->mpass->allows('getErrors')->andReturn(['invalid_response']);
        $this->mpass->expects('markAssertionAsUsed')->never();

        $this->postAcs()
            ->assertRedirect('/login')
            ->assertSessionHas('mpass_error');

        $this->assertGuest();
        Event::assertDispatched(MpassLoginFailed::class, fn ($e) => $e->errors === ['invalid_response']);
    }

    public function test_unauthenticated_response_redirects_to_failure(): void
    {
        $this->mpass->expects('processResponse');
        $this->mpass->allows('getErrors')->andReturn([]);
        $this->mpass->allows('isAuthenticated')->andReturn(false);
        $this->mpass->expects('markAssertionAsUsed')->never();

        $this->postAcs()->assertRedirect('/login')->assertSessionHas('mpass_error');

        $this->assertGuest();
        Event::assertDispatched(MpassLoginFailed::class);
    }

    public function test_replayed_assertion_is_rejected(): void
    {
        $resolver = $this->resolveUserAs($this->makeUser());
        $this->validResponse(freshAssertion: false);

        $this->postAcs()->assertRedirect('/login')->assertSessionHas('mpass_error');

        $this->assertGuest();
        $this->assertNull($resolver->received, 'Resolver must not run for a replayed assertion.');
        Event::assertDispatched(MpassLoginFailed::class, fn ($e) => str_contains($e->reason, 'replay'));
        Event::assertNotDispatched(MpassAuthenticated::class);
    }

    public function test_null_from_resolver_redirects_to_failure(): void
    {
        $this->resolveUserAs(null);
        $this->validResponse();

        $this->postAcs()->assertRedirect('/login')->assertSessionHas('mpass_error');

        $this->assertGuest();
        Event::assertDispatched(MpassLoginFailed::class);
        Event::assertNotDispatched(MpassAuthenticated::class);
    }

    public function test_failure_redirect_is_configurable(): void
    {
        config(['mpass.redirects.on_failure' => '/oops']);
        $this->mpass->expects('processResponse');
        $this->mpass->allows('getErrors')->andReturn(['invalid_response']);

        $this->postAcs()->assertRedirect('/oops');
    }

    #[DataProvider('relayStates')]
    public function test_relay_state_is_followed_only_when_safe(string $relayState, string $expected): void
    {
        $this->resolveUserAs($this->makeUser());
        $this->validResponse();

        $this->postAcs(['RelayState' => $relayState])->assertRedirect($expected);
    }

    public static function relayStates(): array
    {
        return [
            'local path'          => ['/dashboard', '/dashboard'],
            'same origin'         => ['https://app.example.com/dashboard', 'https://app.example.com/dashboard'],
            'other host'          => ['https://evil.com/', '/'],
            'suffix host'         => ['https://app.example.com.evil.com/', '/'],
            'userinfo'            => ['https://app.example.com@evil.com/', '/'],
            'protocol relative'   => ['//evil.com/', '/'],
            'backslash'           => ['/\\evil.com', '/'],
            'acs itself'          => ['https://app.example.com/auth/mpass/acs', '/'],
            'login route'         => ['/auth/mpass/login', '/'],
            'logout'              => ['/logout', '/'],
            'sign-out'            => ['/account/sign-out', '/'],
        ];
    }

    public function test_relay_state_login_route_check_follows_custom_prefix(): void
    {
        config(['mpass.routes.prefix' => 'sso']);
        $this->resolveUserAs($this->makeUser());
        $this->validResponse();

        // The route is registered under the original prefix; only the check changes.
        $this->postAcs(['RelayState' => '/sso/login'])->assertRedirect('/');
    }
}
