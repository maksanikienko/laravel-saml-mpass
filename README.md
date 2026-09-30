# laravel-saml-mpass

Laravel package for SAML 2.0 authentication via **MPass** — the Republic of Moldova government Single Sign-On / Single Logout service.

Built on top of [onelogin/php-saml](https://github.com/SAML-Toolkits/php-saml).

---

## Requirements

- PHP 8.0 or higher
- Laravel 9, 10, 11, 12, or 13 (Laravel 13 requires PHP 8.3+)
- A certificate issued by [semnatura.md](https://semnatura.md/order/system-certificate) (or a self-signed one for testing)
- Your SP registered with MPass (contact servicii@egov.md)

---

## Installation

### 1. Install this package

```bash
composer require maksanikienko/laravel-saml-mpass
```

`onelogin/php-saml` is installed automatically as a dependency.

### 2. Publish the config file

```bash
php artisan vendor:publish --tag=mpass-config
```

---

## Configuration

### Environment variables

Add the following to your `.env`:

```dotenv
# Your application URL — used as the SP entityId and to build ACS / SLS URLs
APP_URL=https://your-app.example.com

# SP certificate and private key (paths relative to storage/app/)
MPASS_SP_CERT=certs/sp.crt
MPASS_SP_KEY=certs/sp.key

# MPass Identity Provider — testing environment
MPASS_IDP_ENTITY_ID=https://mpass.staging.egov.md
MPASS_SSO_URL=https://mpass.staging.egov.md/login/saml
MPASS_SLO_URL=https://mpass.staging.egov.md/logout/saml
MPASS_IDP_CERT=<base64-encoded-mpass-public-certificate>

# Production environment (swap when going live)
# MPASS_IDP_ENTITY_ID=https://mpass.gov.md
# MPASS_SSO_URL=https://mpass.gov.md/login/saml
# MPASS_SLO_URL=https://mpass.gov.md/logout/saml
```

### Certificates

Place your SP certificate files under `storage/app/certs/`:

```
your-laravel-app/
└── storage/
    └── app/
        └── certs/
            ├── sp.crt   ← public certificate (share with MPass during registration)
            └── sp.key   ← private key (never commit to version control)
```

`MPASS_IDP_CERT` is the **public** certificate of MPass itself. Fetch it directly from the MPass metadata endpoint:

```bash
# Staging
curl -s https://mpass.staging.egov.md/meta/saml 

# Production
curl -s https://mpass.gov.md/meta/saml
```

Copy the output <X509Certificate> and paste it as the value in your `.env`:

```dotenv
MPASS_IDP_CERT=MIICpDCCAYwCCQDU...
```

### Full config reference

See [`config/mpass.php`](config/mpass.php) — every key is documented inline.

---

## User resolver

The package ships with `DefaultMpassUserResolver` — it finds or creates a user by `idnp`, from MPass. This works out of the box for a standard Laravel users table.

### Customize for your project

**Step 1 — publish the resolver stub:**

```bash
php artisan mpass:publish-resolver
```

This creates `app/MPass/MpassUserResolver.php` — a class that extends `DefaultMpassUserResolver`. Override only the methods you need:

```php
// app/MPass/MpassUserResolver.php

class MpassUserResolver extends DefaultMpassUserResolver
{
    protected function findBy(SamlUser $samlUser): array
    {
        return ['idnp' => $samlUser->nameId];
    }

    // Map MPass attributes to your users table columns
    protected function newUserAttributes(SamlUser $samlUser): array
    {
        return [
            'first_name'        => $samlUser->firstName(),
            'last_name'         => $samlUser->lastName(),
            'email'             => $samlUser->email(),
            'phone'             => $samlUser->mobilePhone(),
            'email_verified_at' => now(),
            'password'          => null,
        ];
    }

    // Block login for specific users
    protected function isAllowed(Authenticatable $user): bool
    {
        return !$user->is_blocked;
    }
}
```

**Step 2 — create a dedicated service provider:**

```php
// app/Providers/MpassServiceProvider.php

namespace App\Providers;

use App\MPass\MpassUserResolver;
use Mpass\Contracts\UserResolverContract;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

class MpassServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->bind(UserResolverContract::class, MpassUserResolver::class);
    }

    public function provides(): array
    {
        return [UserResolverContract::class];
    }
}
```

`DeferrableProvider` means the provider only loads when `UserResolverContract` is first requested — i.e. during MPass authentication, not on every request.

**Step 3 — register the provider:**

Laravel 11+ — `bootstrap/providers.php`:
```php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\MpassServiceProvider::class,
];
```

Laravel 9 / 10 — `config/app.php`:
```php
'providers' => [
    // ...
    App\Providers\MpassServiceProvider::class,
],
```

---

## Exclude ACS and SLS from CSRF

MPass posts to your endpoints without a CSRF token. You must exclude them.

**Laravel 10 and below** — `app/Http/Middleware/VerifyCsrfToken.php`:

```php
protected $except = [
    'auth/mpass/acs',
    'auth/mpass/sls',
];
```

**Laravel 11 and above** — `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: [
        'auth/mpass/acs',
        'auth/mpass/sls',
    ]);
})
```

---

## Routes

The package registers the following routes automatically (under the `web` middleware):

| Method    | URI                   | Name         | Description                    |
|-----------|-----------------------|--------------|--------------------------------|
| GET       | `/auth/mpass/login`   | `mpass.login`| Redirect to MPass SSO          |
| GET, POST | `/auth/mpass/acs`     | `mpass.acs`  | Assertion Consumer Service     |
| GET       | `/auth/mpass/slo`     | `mpass.slo`  | Initiate Single Logout         |
| GET, POST | `/auth/mpass/sls`     | `mpass.sls`  | Single Logout Service callback |

Trigger login from your own login page:

```html
<a href="{{ route('mpass.login') }}">Sign in with MPass</a>
```

Trigger logout:

```html
<a href="{{ route('mpass.slo') }}">Sign out</a>
```

To disable built-in routes and define your own, set `routes.enabled = false` in `config/mpass.php`.

### MPass registration URLs

When registering your service with MPass (contact **servicii@egov.md**), you must provide the following two URLs:

| Endpoint | URL | Binding |
|----------|-----|---------|
| **ACS** (Assertion Consumer Service) | `/auth/mpass/acs` | HTTP-POST |
| **SLS** (Single Logout Service) | `/auth/mpass/sls` | HTTP-POST |

The easiest way to provide these to MPass is to expose the SP Metadata XML (see [SP Metadata](#sp-metadata) below) and send the URL or the XML file directly to the MPass team. The metadata contains both URLs, the entityID, and your SP certificate in the standard SAML format.

---

## Events

| Event | Payload | Fired when |
|-------|---------|-----------|
| `MpassAuthenticated`   | `$user`, `$samlUser` | SAML response verified and local user resolved. |
| `MpassLoginFailed`     | `$reason`, `$errors` | Authentication failed at any step. |
| `MpassLogoutInitiated` | `$user`, `$nameId`, `$sessionIndex` | SLO redirect to MPass is about to happen. |
| `MpassLogoutCompleted` | — | SLS callback processed, session terminated. |
| `MpassLogoutFailed`    | `$reason`, `$errors` | MPass answered the logout with an error or non-success status (e.g. its session already expired). The local session is already terminated; the user is redirected to `redirects.after_logout`. |

Example — activity logging:

```php
use Mpass\Events\MpassAuthenticated;

class LogMpassLogin
{
    public function handle(MpassAuthenticated $event)
    {
        activity()
            ->causedBy($event->user)
            ->event('mpass_login')
            ->log('User authenticated via MPass');
    }
}
```

---

## SamlUser attributes

`SamlUser` exposes all standard MPass attributes as typed accessors:

| Method / Property | MPass attribute | Type |
|--------|----------------|------|
| `$nameId` | NameIdentifier (IDNP) | `string` |
| `$sessionIndex` | SessionIndex | `string\|null` |
| `firstName()` | FirstName | `string\|null` |
| `lastName()` | LastName | `string\|null` |
| `birthDate()` | BirthDate | `string\|null` |
| `gender()` | Gender | `string\|null` |
| `email()` | EmailAddress | `string\|null` |
| `mobilePhone()` | MobilePhone | `string\|null` |
| `homePhone()` | HomePhone | `string\|null` |
| `language()` | Language | `string\|null` |
| `isResident()` | IsResident | `bool` |
| `idno()` | IDNO | `string\|null` |
| `companyName()` | CompanyName | `string\|null` |
| `administeredLegalEntities()` | AdministeredLegalEntity | `string[]` |
| `attribute(string $name)` | any attribute | `string[]` |
| `attributes()` | all attributes | `array` |

---

## SP Metadata

Expose your SP metadata XML to share with MPass during registration:

```php
use Mpass\Facades\Mpass;

Route::get('/auth/mpass/metadata', function () {
    return response(Mpass::getMetadata(), 200, ['Content-Type' => 'text/xml']);
});
```

---

## Error handling

On failure, the controller redirects to `config('mpass.redirects.on_failure')` (default `/login`) with the flash key `mpass_error`:

```blade
@if (session('mpass_error'))
    <div class="alert alert-danger">{{ session('mpass_error') }}</div>
@endif
```

---

## Verification

```bash
# Check routes are registered
php artisan route:list --name=mpass

# Verify certificates are readable and settings are valid
php artisan tinker
>>> app(\Mpass\MpassManager::class)->getMetadata()
```

If `getMetadata()` returns XML without errors — certificates are loaded and SAML settings are valid.

Open `/auth/mpass/login` in a browser. You should be redirected to the MPass login page.

---

## License

MIT — see [LICENSE](LICENSE).
