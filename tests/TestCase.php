<?php

namespace Mpass\Tests;

use Mpass\MpassServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const APP_URL = 'https://app.example.com';
    public const IDP_ENTITY_ID = 'https://mpass.test';
    public const IDP_SLO_URL = 'https://mpass.test/logout/saml';

    private array $serverBackup = [];
    private array $getBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
    }

    /**
     * onelogin/php-saml reads the current URL and query straight from the
     * superglobals, which Laravel's test client does not populate.
     */
    protected function fakeIncomingRequest(string $path, array $query = []): void
    {
        $_GET = $query;
        $_SERVER['HTTP_HOST'] = parse_url(self::APP_URL, PHP_URL_HOST);
        $_SERVER['SERVER_PORT'] = '443';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = $path;
        unset($_SERVER['QUERY_STRING'], $_SERVER['PATH_INFO']);
    }

    protected function getPackageProviders($app): array
    {
        return [MpassServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.url', self::APP_URL);
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('mpass', self::mpassConfig());
    }

    /** Full package config pointing at the test certificates. */
    public static function mpassConfig(array $overrides = []): array
    {
        $config = require __DIR__ . '/../config/mpass.php';

        $config['sp']['entityId'] = self::APP_URL;
        $config['sp']['assertionConsumerService']['url'] = self::APP_URL . '/auth/mpass/acs';
        $config['sp']['singleLogoutService']['url'] = self::APP_URL . '/auth/mpass/sls';
        $config['sp']['x509cert'] = self::fixture('sp.crt');
        $config['sp']['privateKey'] = self::fixture('sp.key');

        $config['idp']['entityId'] = self::IDP_ENTITY_ID;
        $config['idp']['singleSignOnService']['url'] = 'https://mpass.test/login/saml';
        $config['idp']['singleLogoutService']['url'] = self::IDP_SLO_URL;
        $config['idp']['x509cert'] = self::fixture('idp.crt');

        $config['debug'] = false;

        return array_replace_recursive($config, $overrides);
    }

    public static function fixture(string $name): string
    {
        return file_get_contents(__DIR__ . '/fixtures/' . $name);
    }
}
