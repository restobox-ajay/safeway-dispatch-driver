<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Entity\User;
use App\Bundle\AuthPat\Repository\PersonalAccessTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-119 / review C27, AC2: the personal-access-token per-user cap must not be
 * exceedable by a concurrent create.
 *
 * The `countActiveByUserId >= max` pre-check is racy: two concurrent creates both read a
 * count below the cap and both insert. The fix re-counts INSIDE the create transaction
 * and rolls the insert back when the fresh count exceeds the cap. We reproduce that
 * window deterministically by stubbing the count: the pre-check sees a stale 0 (below the
 * cap), the in-transaction re-count sees the concurrent over-cap value (2), so the guard
 * must roll the just-inserted token back — leaving zero tokens.
 */
final class PersonalAccessTokenRaceTest extends WebTestCase
{
    private const EMAIL = 'patrace@example.com';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->cleanup();

        $user = new User();
        $user->setEmail(self::EMAIL);
        $user->setName('PAT Race User');
        $user->setPassword(password_hash('testpassword', PASSWORD_BCRYPT, ['cost' => 4]));
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        // Cap = 1 active token per user.
        $this->em->getConnection()->executeStatement(
            "INSERT OR REPLACE INTO config (config_key, config_value) VALUES ('pat.max_tokens_per_user', '1')"
        );
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        try {
            $conn = $this->em->getConnection();
            $conn->executeStatement('DELETE FROM personal_access_tokens');
            $conn->executeStatement('DELETE FROM "user" WHERE email = ?', [self::EMAIL]);
            $conn->executeStatement("DELETE FROM config WHERE config_key LIKE 'pat.%'");
            $conn->executeStatement('DELETE FROM user_sessions');
        } catch (\Throwable) {
        }
    }

    public function testConcurrentCreateCannotExceedTheCap(): void
    {
        // Partial mock: only the count is faked (stale pre-check, then the over-cap value a
        // concurrent commit would make visible to the in-transaction guard). findActiveByUserId
        // stays REAL so the error re-render queries the live EntityManager — this is what
        // proves the rollback did not close the EM out from under the re-render.
        $mockRepo = $this->getMockBuilder(PersonalAccessTokenRepository::class)
            ->setConstructorArgs([self::getContainer()->get('doctrine')])
            ->onlyMethods(['countActiveByUserId'])
            ->getMock();
        $mockRepo->expects($this->atLeastOnce())
            ->method('countActiveByUserId')
            ->willReturnOnConsecutiveCalls(0, 2);
        self::getContainer()->set(PersonalAccessTokenRepository::class, $mockRepo);

        // Log in.
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => self::EMAIL,
            'password' => 'testpassword',
        ]);
        $this->client->followRedirect();

        // Load the tokens page (renders the create form + CSRF) and submit.
        $this->client->request('GET', '/account/tokens');
        $this->client->submitForm('Create Token', ['name' => 'Racing Token']);

        // The guard rolled the insert back: handled error, no token persisted.
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.error');

        $count = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM personal_access_tokens'
        );
        $this->assertSame(0, $count, 'The over-cap token must be rolled back, not persisted.');
    }
}
