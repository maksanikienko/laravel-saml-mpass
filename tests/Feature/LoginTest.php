<?php

namespace Mpass\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;

class LoginTest extends ControllerTestCase
{
    private function expectLoginTo(string $returnTo): void
    {
        $this->mpass->expects('loginUrl')->with($returnTo)->andReturn('https://mpass.test/login/saml?SAMLRequest=x');
        $this->mpass->allows('getLastRequestId')->andReturn('_request-1');
    }

    public function test_login_redirects_to_mpass_and_remembers_request_id(): void
    {
        $this->expectLoginTo(self::APP_URL);

        $this->get('/auth/mpass/login')
            ->assertRedirect('https://mpass.test/login/saml?SAMLRequest=x')
            ->assertSessionHas('mpass_request_id', '_request-1');
    }

    public function test_default_return_to_follows_after_login_config(): void
    {
        config(['mpass.redirects.after_login' => '/home']);
        $this->expectLoginTo(self::APP_URL . '/home');

        $this->get('/auth/mpass/login')->assertRedirect();
    }

    #[DataProvider('safeTargets')]
    public function test_safe_return_to_is_passed_to_mpass(string $target): void
    {
        $this->expectLoginTo($target);

        $this->get('/auth/mpass/login?' . http_build_query(['return_to' => $target]))->assertRedirect();
    }

    public static function safeTargets(): array
    {
        return [
            'local path'           => ['/dashboard'],
            'local path and query' => ['/dashboard?tab=1'],
            'same origin'          => ['https://app.example.com/dashboard'],
            'same origin, upper'   => ['HTTPS://APP.EXAMPLE.COM/dashboard'],
        ];
    }

    #[DataProvider('unsafeTargets')]
    public function test_unsafe_return_to_falls_back_to_default(string $target): void
    {
        $this->expectLoginTo(self::APP_URL);

        $this->get('/auth/mpass/login?' . http_build_query(['return_to' => $target]))->assertRedirect();
    }

    public static function unsafeTargets(): array
    {
        return [
            'other host'             => ['https://evil.com/'],
            'suffix host'            => ['https://app.example.com.evil.com/'],
            'userinfo'               => ['https://app.example.com@evil.com/'],
            'user and password'      => ['https://user:pass@app.example.com/'],
            'protocol relative'      => ['//evil.com/'],
            'backslash'              => ['/\\evil.com/'],
            'backslash scheme'       => ['https:\\\\evil.com'],
            'tab'                    => ["/\t/evil.com"],
            'newline'                => ["/\n/evil.com"],
            'leading space'          => [' https://evil.com'],
            'null byte'              => ["/\0/evil.com"],
            'other scheme'           => ['http://app.example.com/'],
            'other port'             => ['https://app.example.com:8443/'],
            'javascript'             => ['javascript:alert(1)'],
            'data'                   => ['data:text/html,<script>alert(1)</script>'],
            'relative without slash' => ['evil.com'],
        ];
    }

    public function test_array_return_to_falls_back_to_default(): void
    {
        $this->expectLoginTo(self::APP_URL);

        $this->get('/auth/mpass/login?return_to[]=/dashboard')->assertRedirect();
    }

    public function test_absolute_target_is_rejected_when_app_url_is_missing(): void
    {
        config(['app.url' => '']);
        $this->mpass->expects('loginUrl')
            ->withArgs(fn (string $returnTo) => !str_contains($returnTo, 'evil.com'))
            ->andReturn('https://mpass.test/login/saml');
        $this->mpass->allows('getLastRequestId')->andReturn('_request-1');

        $this->get('/auth/mpass/login?return_to=https://evil.com/')->assertRedirect();
    }
}
