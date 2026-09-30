<?php

namespace Mpass;

/**
 * Immutable value object that carries the identity attributes returned by MPass
 * after a successful authentication.
 *
 * All standard MPass attributes are exposed as typed accessors. Any custom
 * attributes registered for your service are available via attribute() / attributes().
 */
final class SamlUser
{
    /**
     * @param string                      $nameId         SAML NameID — typically the user's IDNP.
     * @param string|null                 $sessionIndex   MPass session index; required for SLO.
     * @param array<string, list<string>> $rawAttributes  Raw attribute map from the SAML assertion.
     */
    public function __construct(
        public string $nameId,
        public ?string $sessionIndex,
        private array $rawAttributes,
    ) {}

    // -------------------------------------------------------------------------
    // Standard MPass attribute accessors
    // -------------------------------------------------------------------------

    public function firstName(): ?string
    {
        return $this->first('FirstName');
    }

    public function lastName(): ?string
    {
        return $this->first('LastName');
    }

    public function birthDate(): ?string
    {
        return $this->first('BirthDate');
    }

    public function gender(): ?string
    {
        return $this->first('Gender');
    }

    public function email(): ?string
    {
        return $this->first('EmailAddress');
    }

    public function mobilePhone(): ?string
    {
        return $this->first('MobilePhone');
    }

    public function homePhone(): ?string
    {
        return $this->first('HomePhone');
    }

    public function language(): ?string
    {
        return $this->first('Language');
    }

    /** Whether MPass confirmed the user as a resident of the Republic of Moldova. */
    public function isResident(): bool
    {
        return strtolower($this->first('IsResident') ?? 'false') === 'true';
    }

    /**
     * The IDNO of the company/organization associated with this authentication.
     * Only present when the certificate used for authentication includes it.
     */
    public function idno(): ?string
    {
        return $this->first('IDNO');
    }

    /** Company name from the authentication certificate. */
    public function companyName(): ?string
    {
        return $this->first('CompanyName');
    }

    /**
     * Entities administered by the user — zero or more "Name IDNO" strings.
     *
     * @return list<string>
     */
    public function administeredLegalEntities(): array
    {
        return $this->rawAttributes['AdministeredLegalEntity'] ?? [];
    }

    // -------------------------------------------------------------------------
    // Generic access
    // -------------------------------------------------------------------------

    /**
     * Return all values for a named attribute.
     *
     * @return list<string>
     */
    public function attribute(string $name): array
    {
        return $this->rawAttributes[$name] ?? [];
    }

    /**
     * Return all raw SAML attributes.
     *
     * @return array<string, list<string>>
     */
    public function attributes(): array
    {
        return $this->rawAttributes;
    }

    // -------------------------------------------------------------------------

    private function first(string $name): ?string
    {
        return $this->rawAttributes[$name][0] ?? null;
    }
}
