<?php

namespace Mpass\Tests\Unit;

use Mpass\SamlUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SamlUserTest extends TestCase
{
    private function user(array $attributes = []): SamlUser
    {
        return new SamlUser('2000000000001', '_session123', $attributes);
    }

    public function test_name_id_and_session_index_are_exposed(): void
    {
        $user = $this->user();

        $this->assertSame('2000000000001', $user->nameId);
        $this->assertSame('_session123', $user->sessionIndex);
    }

    public function test_standard_attributes_return_first_value(): void
    {
        $user = $this->user([
            'FirstName'    => ['Ion', 'Ignored'],
            'LastName'     => ['Popescu'],
            'BirthDate'    => ['1990-01-01'],
            'Gender'       => ['Male'],
            'EmailAddress' => ['ion@example.com'],
            'MobilePhone'  => ['+37360000000'],
            'HomePhone'    => ['+37322000000'],
            'Language'     => ['ro'],
            'IDNO'         => ['1003600000000'],
            'CompanyName'  => ['Example SRL'],
        ]);

        $this->assertSame('Ion', $user->firstName());
        $this->assertSame('Popescu', $user->lastName());
        $this->assertSame('1990-01-01', $user->birthDate());
        $this->assertSame('Male', $user->gender());
        $this->assertSame('ion@example.com', $user->email());
        $this->assertSame('+37360000000', $user->mobilePhone());
        $this->assertSame('+37322000000', $user->homePhone());
        $this->assertSame('ro', $user->language());
        $this->assertSame('1003600000000', $user->idno());
        $this->assertSame('Example SRL', $user->companyName());
    }

    public function test_missing_attributes_return_null(): void
    {
        $user = $this->user(['FirstName' => []]);

        $this->assertNull($user->firstName());
        $this->assertNull($user->lastName());
        $this->assertNull($user->email());
        $this->assertNull($user->idno());
        $this->assertSame([], $user->administeredLegalEntities());
        $this->assertSame([], $user->attribute('Unknown'));
    }

    #[DataProvider('residentValues')]
    public function test_is_resident_parses_boolean_string(?string $value, bool $expected): void
    {
        $user = $this->user($value === null ? [] : ['IsResident' => [$value]]);

        $this->assertSame($expected, $user->isResident());
    }

    public static function residentValues(): array
    {
        return [
            'true'      => ['true', true],
            'TRUE'      => ['TRUE', true],
            'false'     => ['false', false],
            'garbage'   => ['yes', false],
            'empty'     => ['', false],
            'missing'   => [null, false],
        ];
    }

    public function test_administered_legal_entities_returns_all_values(): void
    {
        $entities = ['Alpha SRL 1003600000001', 'Beta SA 1003600000002'];

        $this->assertSame($entities, $this->user(['AdministeredLegalEntity' => $entities])->administeredLegalEntities());
    }

    public function test_generic_accessors_return_raw_values(): void
    {
        $attributes = ['Custom' => ['a', 'b'], 'FirstName' => ['Ion']];
        $user = $this->user($attributes);

        $this->assertSame(['a', 'b'], $user->attribute('Custom'));
        $this->assertSame($attributes, $user->attributes());
    }
}
