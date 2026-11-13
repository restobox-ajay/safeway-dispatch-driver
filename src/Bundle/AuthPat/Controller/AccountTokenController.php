<?php

declare(strict_types=1);

namespace App\Bundle\AuthPat\Controller;

use App\Bundle\AuthPat\Entity\PersonalAccessToken;
use App\Bundle\AuthPat\Repository\PersonalAccessTokenRepository;
use App\Entity\User;
use App\Exception\TokenLimitExceededException;
use App\Service\ConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The user-facing Personal Access Token management UI (list / create / revoke), moved out of the core
 * AccountController into auth-pat-bundle (FEATURE-138). These routes exist ONLY when the bundle is
 * registered — with it absent they 404, which is the honest "uninstalled" behaviour (AC4).
 */
#[IsGranted('ROLE_USER')]
#[Route('/account')]
final class AccountTokenController extends AbstractController
{
    #[Route('/tokens', name: 'app_account_tokens', methods: ['GET'])]
    public function tokens(PersonalAccessTokenRepository $tokenRepo): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $tokens = $tokenRepo->findActiveByUserId((int) $user->getId());

        return $this->render('account/tokens.html.twig', [
            'tokens' => $tokens,
        ]);
    }

    #[Route('/tokens/new', name: 'app_account_tokens_new', methods: ['POST'])]
    public function createToken(
        Request $request,
        PersonalAccessTokenRepository $tokenRepo,
        EntityManagerInterface $em,
        ConfigService $configService,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('token_create', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_account_tokens');
        }

        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            return $this->render('account/tokens.html.twig', [
                'tokens' => $tokenRepo->findActiveByUserId((int) $user->getId()),
                'error' => 'Token name is required.',
            ]);
        }

        $maxTokens = $configService->getInt('pat.max_tokens_per_user', 0);
        if ($maxTokens > 0 && $tokenRepo->countActiveByUserId((int) $user->getId()) >= $maxTokens) {
            return $this->render('account/tokens.html.twig', [
                'tokens' => $tokenRepo->findActiveByUserId((int) $user->getId()),
                'error' => sprintf('You have reached the maximum of %d active token(s).', $maxTokens),
            ]);
        }

        $expiryDays = $configService->getInt('pat.default_expiry_days', 0);
        $expiresAt = $expiryDays > 0
            ? (new \DateTimeImmutable())->modify("+{$expiryDays} days")
            : null;

        $plaintext = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plaintext);

        $token = new PersonalAccessToken((int) $user->getId(), $name, $tokenHash, $expiresAt);

        // The cap check above is racy on its own — two concurrent creates could both pass
        // it. Enforce it atomically: persist, then re-count active tokens INSIDE the
        // transaction; if the fresh count exceeds the cap, throw to roll the insert back so
        // the cap can never be exceeded by a concurrent create (FEATURE-119 / review C27,
        // ADR-030). On SQLite the whole-DB write lock serialises the two transactions, so
        // the second re-count observes the first committed token and rolls back.
        //
        // We drive the transaction through the DBAL connection (not $em->wrapInTransaction,
        // which closes the EntityManager on a rolled-back closure) so the error re-render
        // below can still query through the EM.
        try {
            $em->getConnection()->transactional(function () use ($em, $token, $tokenRepo, $user, $maxTokens): void {
                $em->persist($token);
                $em->flush();

                if ($maxTokens > 0 && $tokenRepo->countActiveByUserId((int) $user->getId()) > $maxTokens) {
                    throw new TokenLimitExceededException();
                }
            });
        } catch (TokenLimitExceededException) {
            // The insert was rolled back at the DB level; drop the now-phantom managed entity
            // so it can never be re-inserted, then re-render with the real active tokens.
            $em->detach($token);

            return $this->render('account/tokens.html.twig', [
                'tokens' => $tokenRepo->findActiveByUserId((int) $user->getId()),
                'error' => sprintf('You have reached the maximum of %d active token(s).', $maxTokens),
            ]);
        }

        return $this->render('account/token_created.html.twig', [
            'token_name' => $name,
            'token_plaintext' => $plaintext,
        ]);
    }

    #[Route('/tokens/{id}/revoke', name: 'app_account_tokens_revoke', methods: ['POST'])]
    public function revokeToken(
        int $id,
        Request $request,
        PersonalAccessTokenRepository $tokenRepo,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('token_revoke_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_account_tokens');
        }

        /** @var User $user */
        $user = $this->getUser();
        $token = $tokenRepo->find($id);

        if ($token === null || $token->getUserId() !== (int) $user->getId()) {
            throw $this->createNotFoundException('Token not found.');
        }

        $token->revoke();
        $em->flush();

        $this->addFlash('success', 'Token revoked.');
        return $this->redirectToRoute('app_account_tokens');
    }
}
