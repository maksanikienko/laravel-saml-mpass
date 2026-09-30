<?php

namespace Mpass;

use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Settings;

/**
 * Wraps onelogin/php-saml and resolves SP certificates from storage paths
 * before building the settings array expected by the library.
 */
class MpassManager
{
    private Auth $saml;

    public function __construct(private array $config)
    {
        $this->saml = new Auth($this->buildSamlSettings());
    }

    public function getSamlAuth(): Auth
    {
        return $this->saml;
    }

    /** Redirect the browser to MPass for authentication. */
    public function login(?string $returnTo = null): void
    {
        $this->saml->login($returnTo);
    }

    /**
     * Process the ACS (Assertion Consumer Service) POST from MPass.
     * Call getErrors() and isAuthenticated() after this to check the result.
     */
    public function processResponse(): void
    {
        $this->saml->processResponse();
    }

    /** Initiate a SAML Single Logout request toward MPass. */
    public function logout(
        ?string $returnTo = null,
        string $nameId = '',
        string $sessionIndex = '',
        ?string $nameIdFormat = null,
        ?string $nameIdNameQualifier = null,
        ?string $nameIdSPNameQualifier = null,
    ): void {
        $this->saml->logout(
            $returnTo,
            [],
            $nameId,
            $sessionIndex,
            false,
            $nameIdFormat,
            $nameIdNameQualifier,
            $nameIdSPNameQualifier,
        );
    }

    /** Process an inbound SLS (Single Logout Service) request or response. */
    public function processSLO(): void
    {
        $this->saml->processSLO();
    }

    public function getErrors(): array
    {
        return $this->saml->getErrors();
    }

    public function isAuthenticated(): bool
    {
        return $this->saml->isAuthenticated();
    }

    /**
     * Return SAML attributes keyed by attribute name, each value an array.
     *
     * @return array<string, list<string>>
     */
    public function getAttributes(): array
    {
        return $this->saml->getAttributes();
    }

    /** The NameID value — typically the user's IDNP in MPass. */
    public function getNameId(): string
    {
        return $this->saml->getNameId() ?? '';
    }

    public function getNameIdFormat(): ?string
    {
        return $this->saml->getNameIdFormat();
    }

    public function getNameIdNameQualifier(): ?string
    {
        return $this->saml->getNameIdNameQualifier();
    }

    public function getNameIdSPNameQualifier(): ?string
    {
        return $this->saml->getNameIdSPNameQualifier();
    }

    public function getSessionIndex(): ?string
    {
        return $this->saml->getSessionIndex();
    }

    /** Return the SP metadata XML. Useful for registering your service with MPass. */
    public function getMetadata(): string
    {
        $settings = new Settings($this->buildSamlSettings(), true);

        return $settings->getSPMetadata();
    }

    private function buildSamlSettings(): array
    {
        $sp = $this->config['sp'];

        return [
            'debug'    => $this->config['debug'] ?? false,
            'sp'       => [
                'entityId'                 => $sp['entityId'],
                'assertionConsumerService' => $sp['assertionConsumerService'],
                'singleLogoutService'      => $sp['singleLogoutService'],
                'NameIDFormat'             => $sp['NameIDFormat'],
                'x509cert'                 => $this->resolveFileOrString($sp['x509cert']),
                'privateKey'               => $this->resolveFileOrString($sp['privateKey']),
            ],
            'idp'      => $this->config['idp'],
            'security' => $this->config['security'],
        ];
    }

    /** Accept either a raw PEM string or a path relative to storage/app/. */
    private function resolveFileOrString(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (str_contains($value, '-----') || str_contains($value, "\n")) {
            return $value;
        }

        $path = storage_path('app/' . ltrim($value, '/'));

        if (is_file($path)) {
            return file_get_contents($path);
        }

        // Treat as a literal string (e.g. env-provided base64 cert without headers).
        return $value;
    }
}
