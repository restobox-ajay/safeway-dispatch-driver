<?php

declare(strict_types=1);

namespace App\Tests\Unit\Htaccess;

use App\Htaccess\HtaccessLockValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HtaccessLockValidatorTest extends TestCase
{
    private string $docroot;

    protected function setUp(): void
    {
        $this->docroot = sys_get_temp_dir() . '/htl-validator-' . bin2hex(random_bytes(4));
        mkdir($this->docroot);
        file_put_contents($this->docroot . '/blocked.html', 'nope');
    }

    protected function tearDown(): void
    {
        @unlink($this->docroot . '/blocked.html');
        @rmdir($this->docroot);
    }

    private function validate(
        bool $enabled = false,
        string $ips = '',
        string $paths = '',
        string $status = '404',
        string $errorFile = '',
        ?string $requesterIp = null,
    ): \App\Htaccess\HtaccessLockValidation {
        return (new HtaccessLockValidator())->validate($enabled, $ips, $paths, $status, $errorFile, $requesterIp, $this->docroot);
    }

    public function testAcceptsAndCanonicalisesAMixOfAddressesAndCidrs(): void
    {
        $result = $this->validate(false, "# office\n203.0.113.0/24\n\n  198.51.100.7  \n2001:DB8::1\n2001:db8:0:0:0:0:0:2/64\n203.0.113.0/24\n");

        self::assertTrue($result->isValid(), implode('; ', $result->errors));
        self::assertSame(['203.0.113.0/24', '198.51.100.7', '2001:db8::1', '2001:db8::2/64'], $result->settings->ips);
    }

    /** @return array<string,array{0:string}> */
    public static function badIps(): array
    {
        return [
            'directive smuggled on its own line' => ["1.2.3.4\nRequire all granted"],
            'directive smuggled after the ip' => ['1.2.3.4 Require all granted'],
            'octet out of range' => ['999.1.1.1'],
            'hostname' => ['example.com'],
            'cidr matching everyone' => ['0.0.0.0/0'],
            'cidr too long for v4' => ['10.0.0.0/33'],
            'cidr v6 too long' => ['2001:db8::/129'],
            'non numeric prefix' => ['10.0.0.0/abc'],
            'negative prefix' => ['10.0.0.0/-1'],
            'embedded quote' => ['1.2.3.4"'],
        ];
    }

    #[DataProvider('badIps')]
    public function testRejectsAnythingThatIsNotACleanIpOrCidr(string $ips): void
    {
        $result = $this->validate(false, $ips);

        self::assertFalse($result->isValid());
        self::assertNull($result->settings);
    }

    /** @return array<string,array{0:string}> */
    public static function badPaths(): array
    {
        return [
            'no leading slash' => ['health'],
            'space' => ['/a b'],
            'quote and directive' => ["/x\"; Require all granted"],
            'parent traversal' => ['/../etc/passwd'],
            'double slash' => ['//evil'],
            'exempts everything' => ['/*'],
            'regex metacharacters' => ['/a(b|c)+'],
            'query string' => ['/health?x=1'],
            'newline smuggling' => ["/ok\nRequire all granted"],
        ];
    }

    #[DataProvider('badPaths')]
    public function testRejectsExemptPathsOutsideTheStrictAlphabet(string $paths): void
    {
        self::assertFalse($this->validate(false, '', $paths)->isValid());
    }

    public function testAcceptsExactAndPrefixExemptPaths(): void
    {
        $result = $this->validate(false, '', "/health\n/webhooks/*\n/a.b-c_d~e%20\n/health");

        self::assertTrue($result->isValid(), implode('; ', $result->errors));
        self::assertSame(['/health', '/webhooks/*', '/a.b-c_d~e%20'], $result->settings->exemptPaths);
    }

    public function testStatusMustBe403Or404(): void
    {
        self::assertTrue($this->validate(false, '', '', '403')->isValid());
        self::assertTrue($this->validate(false, '', '', '404')->isValid());
        self::assertFalse($this->validate(false, '', '', '500')->isValid());
        self::assertFalse($this->validate(false, '', '', '')->isValid());
    }

    public function testErrorFileMustExistUnderTheDocroot(): void
    {
        self::assertSame('/blocked.html', $this->validate(false, '', '', '404', '/blocked.html')->settings->errorFile);
        self::assertFalse($this->validate(false, '', '', '404', '/missing.html')->isValid());
        self::assertFalse($this->validate(false, '', '', '404', '/../../etc/passwd')->isValid());
        self::assertFalse($this->validate(false, '', '', '404', '/blocked.html*')->isValid());
        self::assertSame('', $this->validate(false, '', '', '404', '')->settings->errorFile, 'blank = empty response');
    }

    public function testEnablingRequiresAWhitelist(): void
    {
        $result = $this->validate(true, '', '', '404', '', '203.0.113.9');

        self::assertFalse($result->isValid());
        self::assertStringContainsString('at least one allowed IP', $result->errors[0]);
    }

    public function testEnablingIsRefusedWhenItWouldLockTheAdminOut(): void
    {
        $result = $this->validate(true, '198.51.100.0/24', '', '404', '', '203.0.113.9');

        self::assertFalse($result->isValid());
        self::assertStringContainsString('203.0.113.9', $result->errors[0]);
        self::assertStringContainsString('lock you out', $result->errors[0]);
    }

    public function testEnablingIsAllowedWhenTheAdminsIpIsCovered(): void
    {
        self::assertTrue($this->validate(true, '203.0.113.0/24', '', '404', '', '203.0.113.9')->isValid());
        self::assertTrue($this->validate(true, '2001:db8::/32', '', '404', '', '2001:db8::77')->isValid());
        self::assertFalse($this->validate(true, '203.0.113.0/24', '', '404', '', '2001:db8::77')->isValid(), 'a v6 admin is not covered by a v4 list');
    }

    public function testTurningTheLockOffNeverNeedsAWhitelist(): void
    {
        self::assertTrue($this->validate(false, '', '', '404', '', '203.0.113.9')->isValid());
    }

    public function testNormalizeIpGivesTheCanonicalFormOrNull(): void
    {
        $validator = new HtaccessLockValidator();

        self::assertSame('2001:db8::1', $validator->normalizeIp(' 2001:DB8:0:0:0:0:0:1 '));
        self::assertSame('203.0.113.0/24', $validator->normalizeIp('203.0.113.0/24'));
        self::assertNull($validator->normalizeIp('0.0.0.0/0'), '/0 would let everyone in');
        self::assertNull($validator->normalizeIp("1.2.3.4\nRequire all granted"));
        self::assertNull($validator->normalizeIp('banana'));
        self::assertNull($validator->normalizeIp(''));
    }

    public function testNormalizeExemptPathAgreesWithTheBulkValidator(): void
    {
        $validator = new HtaccessLockValidator();

        foreach (['/health', '/webhooks/*', '/a.b-c_d~e%20'] as $ok) {
            self::assertSame($ok, $validator->normalizeExemptPath(" $ok "));
            self::assertTrue($this->validate(false, '', $ok)->isValid(), "$ok must also pass the bulk validator");
        }
        foreach (['no-slash', '/*', '/a/../b', '//x', "/x\nRequire all granted", ''] as $bad) {
            self::assertNull($validator->normalizeExemptPath($bad));
            if ($bad !== '') {
                self::assertFalse($this->validate(false, '', $bad)->isValid(), "$bad must also fail the bulk validator");
            }
        }
    }
}
