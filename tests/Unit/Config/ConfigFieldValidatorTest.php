<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Config\ConfigFieldValidator;
use PHPUnit\Framework\TestCase;

final class ConfigFieldValidatorTest extends TestCase
{
    private ConfigFieldValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ConfigFieldValidator();
    }

    private function intField(array $extra = []): array
    {
        return array_merge(['label' => 'Some Int', 'type' => 'int', 'default' => '0'], $extra);
    }

    public function testIntAcceptsNonNegativeIntegerStrings(): void
    {
        $this->assertNull($this->validator->validate($this->intField(), '0'));
        $this->assertNull($this->validator->validate($this->intField(), '10'));
        $this->assertNull($this->validator->validate($this->intField(), '99999'));
    }

    public function testIntRejectsNonInteger(): void
    {
        $this->assertNotNull($this->validator->validate($this->intField(), 'banana'));
        $this->assertNotNull($this->validator->validate($this->intField(), '1.5'));
        $this->assertNotNull($this->validator->validate($this->intField(), '10x'));
        $this->assertNotNull($this->validator->validate($this->intField(), ''));
    }

    public function testIntRejectsNegative(): void
    {
        // ctype_digit already excludes the '-' sign
        $this->assertNotNull($this->validator->validate($this->intField(), '-5'));
    }

    public function testIntMinBoundRejectsBelow(): void
    {
        $field = $this->intField(['min' => 1]);
        $this->assertNotNull($this->validator->validate($field, '0'));
        $this->assertNull($this->validator->validate($field, '1'));
        $this->assertNull($this->validator->validate($field, '2'));
    }

    public function testIntMaxBoundRejectsAbove(): void
    {
        $field = $this->intField(['max' => 100]);
        $this->assertNull($this->validator->validate($field, '100'));
        $this->assertNotNull($this->validator->validate($field, '101'));
    }

    public function testEnumAcceptsInSetRejectsOutOfSet(): void
    {
        $field = ['label' => 'Mode', 'type' => 'enum', 'options' => ['open', 'invitation-only'], 'default' => 'open'];
        $this->assertNull($this->validator->validate($field, 'open'));
        $this->assertNull($this->validator->validate($field, 'invitation-only'));
        $this->assertNotNull($this->validator->validate($field, 'bogus'));
        $this->assertNotNull($this->validator->validate($field, ''));
        // strict comparison: an int-looking string is not silently coerced
        $this->assertNotNull($this->validator->validate($field, 'OPEN'));
    }

    public function testBoolAcceptsCanonicalValues(): void
    {
        $field = ['label' => 'Flag', 'type' => 'bool', 'default' => '0'];
        $this->assertNull($this->validator->validate($field, '0'));
        $this->assertNull($this->validator->validate($field, '1'));
    }

    public function testBoolRejectsNonCanonicalValue(): void
    {
        $field = ['label' => 'Flag', 'type' => 'bool', 'default' => '0'];
        $this->assertNotNull($this->validator->validate($field, 'yes'));
    }

    public function testTextAlwaysAccepted(): void
    {
        $field = ['label' => 'Free', 'type' => 'text', 'default' => ''];
        $this->assertNull($this->validator->validate($field, ''));
        $this->assertNull($this->validator->validate($field, 'anything at all /24,10.0.0.1'));
    }

    public function testUnknownTypeAccepted(): void
    {
        $field = ['label' => 'Mystery', 'type' => 'wat', 'default' => ''];
        $this->assertNull($this->validator->validate($field, 'anything'));
    }

    /** Issue #17: an IP list is validated entry by entry, so a typo can't silently lock everyone out. */
    private function ipListField(array $extra = []): array
    {
        return array_merge(['label' => 'Allowed IPs', 'type' => 'ip_list', 'default' => ''], $extra);
    }

    public function testIpListAcceptsAddressesAndCidrsSeparatedByCommas(): void
    {
        foreach (['', '127.0.0.1', '192.168.1.0/24,10.0.0.1', ' 10.0.0.1 , 2001:db8::/32 ,::1', '203.0.113.5,'] as $ok) {
            $this->assertNull($this->validator->validate($this->ipListField(), $ok), "\"$ok\" is a valid list");
        }
    }

    public function testIpListRejectsAnythingThatIsNotAnAddressOrCidr(): void
    {
        foreach (['203.0.113.5 198.51.100.7', '203.0.113.*', '10.0.0.0/33', '2001:db8::/129', '10.0.0.1/', '/24', 'localhost', '1.2.3.4/abc', '999.1.1.1', '::/00', '10.0.0.0/08', "10.0.0.1\n10.0.0.2"] as $bad) {
            $error = $this->validator->validate($this->ipListField(), $bad);
            $this->assertNotNull($error, "\"$bad\" must be refused");
        }
        $this->assertStringContainsString('203.0.113.5 198.51.100.7', (string) $this->validator->validate($this->ipListField(), '10.0.0.1,203.0.113.5 198.51.100.7'), 'the error names the bad entry');
    }

    public function testAnIpListThatMustAdmitTheSaverRefusesOneThatWouldLockThemOut(): void
    {
        $field = $this->ipListField(['require_client_ip' => true]);

        $this->assertNotNull($this->validator->validate($field, '198.51.100.0/24', '203.0.113.9'));
        $this->assertStringContainsString('203.0.113.9', (string) $this->validator->validate($field, '198.51.100.0/24', '203.0.113.9'));
        $this->assertNull($this->validator->validate($field, '198.51.100.0/24,203.0.113.0/24', '203.0.113.9'));
        $this->assertNull($this->validator->validate($field, '', '203.0.113.9'), 'an empty list allows everyone, so it cannot lock anyone out');
        $this->assertNull($this->validator->validate($this->ipListField(), '198.51.100.0/24', '203.0.113.9'), 'the guard applies only where the field asks for it');
    }
}

