<?php

namespace Mpass\Tests\Unit;

use Mockery;
use Mpass\MpassManager;
use Mpass\Tests\TestCase;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Utils;
use ReflectionProperty;

class MpassManagerTest extends TestCase
{
    private function manager(array $overrides = []): MpassManager
    {
        return new MpassManager(self::mpassConfig($overrides));
    }

    /** Replace the onelogin Auth inside the manager with a mock. */
    private function managerWithAuth(Auth $auth, array $overrides = []): MpassManager
    {
        $manager = $this->manager($overrides);

        (new ReflectionProperty(MpassManager::class, 'saml'))->setValue($manager, $auth);

        return $manager;
    }

    // -------------------------------------------------------------------------
    // Container / certificates
    // -------------------------------------------------------------------------

    public function test_manager_is_bound_as_singleton(): void
    {
        $this->assertSame($this->app->make(MpassManager::class), $this->app->make(MpassManager::class));
    }

    public function test_pem_strings_are_used_as_is(): void
    {
        $settings = $this->manager()->getSamlAuth()->getSettings();

        $this->assertSame(
            Utils::formatCert(self::fixture('sp.crt')),
            $settings->getSPcert(),
        );
        $this->assertSame(
            Utils::formatPrivateKey(self::fixture('sp.key')),
            $settings->getSPkey(),
        );
    }

    public function test_cert_paths_are_resolved_relative_to_storage_app(): void
    {
        $dir = storage_path('app/mpass-test-certs');
        @mkdir($dir, 0777, true);
        file_put_contents($dir . '/sp.crt', self::fixture('sp.crt'));
        file_put_contents($dir . '/sp.key', self::fixture('sp.key'));

        try {
            $settings = $this->manager(['sp' => [
                'x509cert'   => '/mpass-test-certs/sp.crt',
                'privateKey' => 'mpass-test-certs/sp.key',
            ]])->getSamlAuth()->getSettings();

            $this->assertSame(Utils::formatCert(self::fixture('sp.crt')), $settings->getSPcert());
            $this->assertSame(Utils::formatPrivateKey(self::fixture('sp.key')), $settings->getSPkey());
        } finally {
            @unlink($dir . '/sp.crt');
            @unlink($dir . '/sp.key');
            @rmdir($dir);
        }
    }

    public function test_value_that_is_neither_pem_nor_existing_file_is_used_literally(): void
    {
        // A base64 body without PEM headers, e.g. supplied via env.
        $body = trim(preg_replace('/-----[^-]+-----|\s+/', '', self::fixture('sp.crt')));

        $settings = $this->manager(['sp' => ['x509cert' => $body]])->getSamlAuth()->getSettings();

        $this->assertSame(Utils::formatCert($body), $settings->getSPcert());
    }

    public function test_empty_cert_does_not_read_storage_directory(): void
    {
        $settings = $this->manager([
            'sp'       => ['x509cert' => '', 'privateKey' => ''],
            'security' => [
                'authnRequestsSigned'  => false,
                'logoutRequestSigned'  => false,
                'logoutResponseSigned' => false,
            ],
        ])->getSamlAuth()->getSettings();

        $this->assertSame('', (string) $settings->getSPcert());
    }

    public function test_metadata_describes_the_sp(): void
    {
        $metadata = $this->manager()->getMetadata();

        $this->assertStringContainsString('entityID="' . self::APP_URL . '"', $metadata);
        $this->assertStringContainsString(self::APP_URL . '/auth/mpass/acs', $metadata);
        $this->assertStringContainsString(self::APP_URL . '/auth/mpass/sls', $metadata);
    }

    // -------------------------------------------------------------------------
    // Login
    // -------------------------------------------------------------------------

    public function test_login_url_points_to_idp_and_exposes_request_id(): void
    {
        $manager = $this->manager();

        $url = $manager->loginUrl(self::APP_URL . '/dashboard');
        $requestId = $manager->getLastRequestId();

        $this->assertStringStartsWith('https://mpass.test/login/saml?', $url);
        $this->assertNotEmpty($requestId);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame(self::APP_URL . '/dashboard', $query['RelayState']);
        $this->assertArrayHasKey('Signature', $query);

        $xml = gzinflate(base64_decode($query['SAMLRequest']));
        $this->assertStringContainsString('ID="' . $requestId . '"', $xml);
    }

    // -------------------------------------------------------------------------
    // Replay protection
    // -------------------------------------------------------------------------

    public function test_assertion_can_be_used_only_once(): void
    {
        $auth = Mockery::mock(Auth::class);
        $auth->allows('getLastAssertionId')->andReturn('_assertion-1');
        $auth->allows('getLastAssertionNotOnOrAfter')->andReturn(time() + 300);

        $manager = $this->managerWithAuth($auth);

        $this->assertTrue($manager->markAssertionAsUsed());
        $this->assertFalse($manager->markAssertionAsUsed());
    }

    public function test_different_assertions_are_accepted(): void
    {
        $auth = Mockery::mock(Auth::class);
        $auth->allows('getLastAssertionId')->andReturn('_assertion-1', '_assertion-2');
        $auth->allows('getLastAssertionNotOnOrAfter')->andReturn(time() + 300);

        $manager = $this->managerWithAuth($auth);

        $this->assertTrue($manager->markAssertionAsUsed());
        $this->assertTrue($manager->markAssertionAsUsed());
    }

    public function test_missing_assertion_id_is_rejected(): void
    {
        $auth = Mockery::mock(Auth::class);
        $auth->allows('getLastAssertionId')->andReturn(null);

        $this->assertFalse($this->managerWithAuth($auth)->markAssertionAsUsed());
    }

    public function test_used_assertion_is_remembered_past_its_expiry(): void
    {
        $auth = Mockery::mock(Auth::class);
        $auth->allows('getLastAssertionId')->andReturn('_assertion-1');
        $auth->allows('getLastAssertionNotOnOrAfter')->andReturn(time() + 60);

        $this->managerWithAuth($auth)->markAssertionAsUsed();

        // NotOnOrAfter + clock-drift margin (300 s) must still be covered.
        $this->travel(60 + 299)->seconds();
        $this->assertTrue(cache()->has('mpass:assertion:' . hash('sha256', '_assertion-1')));

        $this->travel(2)->seconds();
        $this->assertFalse(cache()->has('mpass:assertion:' . hash('sha256', '_assertion-1')));
    }

    public function test_replay_cache_store_is_configurable(): void
    {
        config(['cache.stores.mpass-replay' => ['driver' => 'array']]);

        $auth = Mockery::mock(Auth::class);
        $auth->allows('getLastAssertionId')->andReturn('_assertion-1');
        $auth->allows('getLastAssertionNotOnOrAfter')->andReturn(time() + 300);

        $this->managerWithAuth($auth, ['replay_cache_store' => 'mpass-replay'])->markAssertionAsUsed();

        $key = 'mpass:assertion:' . hash('sha256', '_assertion-1');
        $this->assertTrue(cache()->store('mpass-replay')->has($key));
        $this->assertFalse(cache()->store('array')->has($key));
    }

    // -------------------------------------------------------------------------
    // Inbound LogoutRequest (IdP-initiated SLO)
    // -------------------------------------------------------------------------

    /**
     * Build an HTTP-Redirect LogoutRequest as MPass would send it and expose it
     * to onelogin through the superglobals it reads.
     */
    private function receiveLogoutRequest(string $nameId, string $signingKey = 'idp', ?callable $tamper = null): void
    {
        $idp = new Auth([
            'strict' => true,
            'sp'     => [
                'entityId'                 => self::IDP_ENTITY_ID,
                'assertionConsumerService' => ['url' => 'https://mpass.test/acs'],
                'NameIDFormat'             => 'urn:oasis:names:tc:SAML:2.0:nameid-format:unspecified',
                'x509cert'                 => self::fixture($signingKey . '.crt'),
                'privateKey'               => self::fixture($signingKey . '.key'),
            ],
            'idp' => [
                'entityId'            => self::APP_URL,
                'singleSignOnService' => ['url' => self::APP_URL . '/auth/mpass/acs'],
                'singleLogoutService' => ['url' => self::APP_URL . '/auth/mpass/sls'],
                'x509cert'            => self::fixture('sp.crt'),
            ],
            'security' => ['logoutRequestSigned' => true],
        ]);

        $url = $idp->logout(null, [], $nameId, '_session-1', true);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        if ($tamper) {
            $query = $tamper($query);
        }

        $this->fakeIncomingRequest('/auth/mpass/sls', $query);
    }

    public function test_valid_logout_request_runs_callback_and_returns_response_url(): void
    {
        $this->receiveLogoutRequest('2000000000001');

        $manager = $this->manager();
        $received = null;
        $responseUrl = $manager->processLogoutRequest(function (string $nameId, array $indexes) use (&$received) {
            $received = [$nameId, $indexes];
        });

        $this->assertSame([], $manager->getErrors());
        $this->assertSame(['2000000000001', ['_session-1']], $received);
        $this->assertStringStartsWith(self::IDP_SLO_URL . '?SAMLResponse=', $responseUrl);
    }

    public function test_logout_request_signed_with_foreign_key_is_rejected(): void
    {
        $this->receiveLogoutRequest('2000000000001', signingKey: 'sp');

        $this->assertLogoutRequestRejected();
    }

    public function test_tampered_logout_request_is_rejected(): void
    {
        $this->receiveLogoutRequest('2000000000001', tamper: function (array $query) {
            $xml = gzinflate(base64_decode($query['SAMLRequest']));
            $xml = str_replace('2000000000001', '2000000000002', $xml);
            $query['SAMLRequest'] = base64_encode(gzdeflate($xml));

            return $query;
        });

        $this->assertLogoutRequestRejected();
    }

    private function assertLogoutRequestRejected(): void
    {
        $manager = $this->manager();
        $called = false;

        $responseUrl = $manager->processLogoutRequest(function () use (&$called) {
            $called = true;
        });

        $this->assertNull($responseUrl);
        $this->assertFalse($called, 'Callback must not run for an invalid LogoutRequest.');
        $this->assertNotEmpty($manager->getErrors());
    }
}
