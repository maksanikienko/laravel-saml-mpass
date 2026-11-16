<?php

namespace Mpass\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Mpass\Events\MpassLogoutCompleted;
use Mpass\Events\MpassLogoutFailed;
use Mpass\Events\MpassLogoutInitiated;

class LogoutTest extends ControllerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([MpassLogoutInitiated::class, MpassLogoutCompleted::class, MpassLogoutFailed::class]);
    }

    // -------------------------------------------------------------------------
    // SP-initiated: GET /slo
    // -------------------------------------------------------------------------

    public function test_slo_without_mpass_session_logs_out_locally(): void
    {
        $this->mpass->expects('logout')->never();

        $this->actingAs($this->makeUser())
            ->get('/auth/mpass/slo')
            ->assertRedirect('/');

        $this->assertGuest();
        Event::assertNotDispatched(MpassLogoutInitiated::class);
    }

    public function test_slo_ends_local_session_and_sends_logout_request_to_mpass(): void
    {
        $user = $this->makeUser();

        $this->mpass->expects('logout')->with(
            '/',
            '2000000000001',
            '_session-1',
            'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
            'https://mpass.test',
            self::APP_URL,
        );

        $this->actingAs($user)
            ->withSession($this->mpassSession())
            ->get('/auth/mpass/slo');

        $this->assertGuest();
        $this->assertFalse(session()->has('mpass_name_id'), 'Session must be invalidated before redirecting to MPass.');
        Event::assertDispatched(
            MpassLogoutInitiated::class,
            fn ($e) => $e->user === $user && $e->nameId === '2000000000001' && $e->sessionIndex === '_session-1',
        );
    }

    // -------------------------------------------------------------------------
    // IdP-initiated: GET /sls?SAMLRequest=...
    // -------------------------------------------------------------------------

    public function test_unsigned_logout_request_is_rejected_without_touching_session(): void
    {
        $this->mpass->expects('processLogoutRequest')->never();

        $this->actingAs($this->makeUser())
            ->withSession($this->mpassSession())
            ->get('/auth/mpass/sls?SAMLRequest=x')
            ->assertStatus(400);

        $this->assertAuthenticated();
        $this->assertSame('2000000000001', session('mpass_name_id'));
        Event::assertDispatched(MpassLogoutFailed::class, fn ($e) => $e->errors === ['unsigned_logout_request']);
    }

    public function test_valid_logout_request_for_current_user_ends_session(): void
    {
        $user = $this->makeUser();
        $this->mpass->expects('processLogoutRequest')->andReturnUsing(function (callable $onValid) {
            $onValid('2000000000001', ['_session-1']);

            return 'https://mpass.test/logout/saml?SAMLResponse=y';
        });
        $this->mpass->allows('getErrors')->andReturn([]);

        $this->actingAs($user)
            ->withSession($this->mpassSession())
            ->get('/auth/mpass/sls?SAMLRequest=x&SigAlg=a&Signature=s')
            ->assertRedirect('https://mpass.test/logout/saml?SAMLResponse=y');

        $this->assertGuest();
        $this->assertFalse(session()->has('mpass_name_id'));
        Event::assertDispatched(MpassLogoutInitiated::class, fn ($e) => $e->user === $user && $e->sessionIndex === '_session-1');
        Event::assertDispatched(MpassLogoutCompleted::class);
    }

    public function test_logout_request_for_another_name_id_keeps_session(): void
    {
        $this->mpass->expects('processLogoutRequest')->andReturnUsing(function (callable $onValid) {
            $onValid('2000000000999', []);

            return 'https://mpass.test/logout/saml?SAMLResponse=y';
        });
        $this->mpass->allows('getErrors')->andReturn([]);

        $this->actingAs($this->makeUser())
            ->withSession($this->mpassSession())
            ->get('/auth/mpass/sls?SAMLRequest=x&SigAlg=a&Signature=s')
            ->assertRedirect('https://mpass.test/logout/saml?SAMLResponse=y');

        $this->assertAuthenticated();
        $this->assertSame('2000000000001', session('mpass_name_id'));
        Event::assertNotDispatched(MpassLogoutInitiated::class);
    }

    public function test_logout_request_without_mpass_session_keeps_session(): void
    {
        $this->mpass->expects('processLogoutRequest')->andReturnUsing(function (callable $onValid) {
            $onValid('2000000000001', []);

            return 'https://mpass.test/logout/saml?SAMLResponse=y';
        });
        $this->mpass->allows('getErrors')->andReturn([]);

        $this->actingAs($this->makeUser())
            ->get('/auth/mpass/sls?SAMLRequest=x&SigAlg=a&Signature=s')
            ->assertRedirect();

        $this->assertAuthenticated();
    }

    public function test_invalid_logout_request_returns_400_and_keeps_session(): void
    {
        $this->mpass->expects('processLogoutRequest')->andReturnNull();
        $this->mpass->allows('getErrors')->andReturn(['invalid_logout_request']);

        $this->actingAs($this->makeUser())
            ->withSession($this->mpassSession())
            ->get('/auth/mpass/sls?SAMLRequest=x&SigAlg=a&Signature=s')
            ->assertStatus(400);

        $this->assertAuthenticated();
        Event::assertDispatched(MpassLogoutFailed::class, fn ($e) => $e->errors === ['invalid_logout_request']);
        Event::assertNotDispatched(MpassLogoutCompleted::class);
    }

    public function test_posted_logout_request_is_rejected_without_touching_session(): void
    {
        $this->mpass->expects('processLogoutRequest')->never();
        $this->mpass->expects('processSLO')->never();

        $this->actingAs($this->makeUser())
            ->withSession($this->mpassSession())
            ->post('/auth/mpass/sls', ['SAMLRequest' => 'x'])
            ->assertStatus(400);

        $this->assertAuthenticated();
        Event::assertDispatched(MpassLogoutFailed::class);
    }

    // -------------------------------------------------------------------------
    // Response to SP-initiated logout: GET /sls?SAMLResponse=...
    // -------------------------------------------------------------------------

    public function test_successful_logout_response_completes_logout(): void
    {
        $this->mpass->expects('processSLO');
        $this->mpass->allows('getErrors')->andReturn([]);

        $this->get('/auth/mpass/sls?SAMLResponse=x')->assertRedirect('/');

        Event::assertDispatched(MpassLogoutCompleted::class);
        Event::assertNotDispatched(MpassLogoutFailed::class);
    }

    public function test_failed_logout_response_still_redirects_after_logout(): void
    {
        config(['mpass.redirects.after_logout' => '/bye']);
        $this->mpass->expects('processSLO');
        $this->mpass->allows('getErrors')->andReturn(['logout_not_success']);

        $this->get('/auth/mpass/sls?SAMLResponse=x')->assertRedirect('/bye');

        Event::assertDispatched(MpassLogoutFailed::class, fn ($e) => $e->errors === ['logout_not_success']);
        Event::assertNotDispatched(MpassLogoutCompleted::class);
    }

    public function test_posted_logout_response_is_ignored(): void
    {
        $this->mpass->expects('processSLO')->never();

        $this->actingAs($this->makeUser())
            ->withSession($this->mpassSession())
            ->post('/auth/mpass/sls', ['SAMLResponse' => 'x'])
            ->assertRedirect('/');

        $this->assertAuthenticated();
        Event::assertDispatched(MpassLogoutFailed::class);
        Event::assertNotDispatched(MpassLogoutCompleted::class);
    }
}
