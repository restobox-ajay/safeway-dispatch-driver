<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Admin;
use App\Repository\AdminAccessTokenRepository;
use App\Repository\AdminRepository;
use App\Security\AdminApiTokenManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin API token pages (issue #39 / ADR-064):
 *  - every admin sees and revokes THEIR OWN tokens at /admin/api-tokens (scoped by getUser(); another admin's
 *    token id is answered 404, never revoked);
 *  - tech support can open any admin's token page and revoke one or all of that admin's tokens
 *    (/admin/superadmin/admins/{adminId}/api-tokens). Not superadmins: reading and killing another account's
 *    API credentials is a maintainer power, like the other Maintainer tools.
 * Issuing stays on the shell (`app:admin:create-api-token`). Every revoke goes through AdminApiTokenManager.
 */
#[IsGranted('ROLE_ADMIN')]
class AdminApiTokenController extends AbstractController
{
    public function __construct(
        private readonly AdminAccessTokenRepository $tokens,
        private readonly AdminApiTokenManager $tokenManager,
    ) {
    }

    #[Route('/admin/api-tokens', name: 'app_admin_api_tokens', methods: ['GET'])]
    public function mine(): Response
    {
        $me = $this->me();

        return $this->render('admin/api_tokens/index.html.twig', [
            'owner' => $me,
            'own' => true,
            'tokens' => $this->tokens->findAllByAdminId((int) $me->getId()),
        ]);
    }

    #[Route('/admin/api-tokens/{id}/revoke', name: 'app_admin_api_tokens_revoke', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function revokeMine(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_api_token_revoke_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_api_tokens');
        }

        $me = $this->me();
        $token = $this->tokens->find($id);
        if ($token === null || $token->getAdminId() !== (int) $me->getId()) {
            throw $this->createNotFoundException('Token not found.');
        }

        $this->addFlash('success', $this->tokenManager->revoke($token, $me->getEmail(), $me->getEmail(), $request->getClientIp() ?? '0.0.0.0')
            ? sprintf('Token "%s" revoked. Anything still using it now gets 401.', $token->getName())
            : sprintf('Token "%s" was already revoked.', $token->getName()));

        return $this->redirectToRoute('app_admin_api_tokens');
    }

    #[Route('/admin/superadmin/admins/{adminId}/api-tokens', name: 'app_admin_support_api_tokens', requirements: ['adminId' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_TECH_SUPPORT')]
    public function ofAdmin(int $adminId, AdminRepository $admins): Response
    {
        $owner = $admins->find($adminId) ?? throw $this->createNotFoundException('Admin not found.');

        return $this->render('admin/api_tokens/index.html.twig', [
            'owner' => $owner,
            'own' => false,
            'tokens' => $this->tokens->findAllByAdminId((int) $owner->getId()),
        ]);
    }

    #[Route('/admin/superadmin/admins/{adminId}/api-tokens/{id}/revoke', name: 'app_admin_support_api_tokens_revoke', requirements: ['adminId' => '\d+', 'id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_TECH_SUPPORT')]
    public function revokeOfAdmin(int $adminId, int $id, Request $request, AdminRepository $admins): Response
    {
        $owner = $admins->find($adminId) ?? throw $this->createNotFoundException('Admin not found.');
        if (!$this->isCsrfTokenValid('admin_api_token_revoke_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_support_api_tokens', ['adminId' => $adminId]);
        }

        $token = $this->tokens->find($id);
        if ($token === null || $token->getAdminId() !== (int) $owner->getId()) {
            throw $this->createNotFoundException('Token not found.');
        }

        $this->addFlash('success', $this->tokenManager->revoke($token, $owner->getEmail(), $this->me()->getEmail(), $request->getClientIp() ?? '0.0.0.0')
            ? sprintf('Token "%s" of %s revoked.', $token->getName(), $owner->getEmail())
            : sprintf('Token "%s" of %s was already revoked.', $token->getName(), $owner->getEmail()));

        return $this->redirectToRoute('app_admin_support_api_tokens', ['adminId' => $adminId]);
    }

    #[Route('/admin/superadmin/admins/{adminId}/api-tokens/revoke-all', name: 'app_admin_support_api_tokens_revoke_all', requirements: ['adminId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_TECH_SUPPORT')]
    public function revokeAllOfAdmin(int $adminId, Request $request, AdminRepository $admins): Response
    {
        $owner = $admins->find($adminId) ?? throw $this->createNotFoundException('Admin not found.');
        if (!$this->isCsrfTokenValid('admin_api_token_revoke_all_' . $adminId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_support_api_tokens', ['adminId' => $adminId]);
        }

        $count = $this->tokenManager->revokeAllFor($owner, $this->me()->getEmail(), $request->getClientIp() ?? '0.0.0.0', 'tech support');
        $this->addFlash('success', sprintf('Revoked %d token(s) of %s.', $count, $owner->getEmail()));

        return $this->redirectToRoute('app_admin_support_api_tokens', ['adminId' => $adminId]);
    }

    private function me(): Admin
    {
        $me = $this->getUser();
        if (!$me instanceof Admin) {
            throw $this->createAccessDeniedException();
        }

        return $me;
    }
}
