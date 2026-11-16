<?php

namespace Mpass\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Mockery;
use Mockery\MockInterface;
use Mpass\Contracts\UserResolverContract;
use Mpass\MpassManager;
use Mpass\SamlUser;
use Mpass\Tests\TestCase;

abstract class ControllerTestCase extends TestCase
{
    protected MockInterface $mpass;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mpass = Mockery::mock(MpassManager::class);
        $this->instance(MpassManager::class, $this->mpass);
    }

    protected function makeUser(int $id = 1): GenericUser
    {
        return new GenericUser(['id' => $id, 'name' => 'Ion Popescu', 'password' => '', 'remember_token' => null]);
    }

    /** Bind a resolver that returns $user and records the SamlUser it got. */
    protected function resolveUserAs(?Authenticatable $user): object
    {
        $resolver = new class ($user) implements UserResolverContract {
            public ?SamlUser $received = null;

            public function __construct(private ?Authenticatable $user) {}

            public function resolve(SamlUser $samlUser): ?Authenticatable
            {
                $this->received = $samlUser;

                return $this->user;
            }
        };

        $this->instance(UserResolverContract::class, $resolver);

        return $resolver;
    }

    /** Session data as stored by a successful ACS. */
    protected function mpassSession(string $nameId = '2000000000001'): array
    {
        return [
            'mpass_name_id'                   => $nameId,
            'mpass_session_index'             => '_session-1',
            'mpass_name_id_format'            => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
            'mpass_name_id_name_qualifier'    => 'https://mpass.test',
            'mpass_name_id_sp_name_qualifier' => self::APP_URL,
        ];
    }
}
