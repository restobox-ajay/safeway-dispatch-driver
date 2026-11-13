<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\InvitationAlreadyUsedException;
use App\Repository\InvitationRepository;
use App\Service\AuditLogger;
use App\Service\InvitationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/users')]
class AdminInvitationController extends AbstractController
{
    private const PAGE_SIZE = 10;

    #[Route('/invite', name: 'app_admin_users_invite', methods: ['GET', 'POST'])]
    public function invite(
        Request $request,
        InvitationService $invitationService,
        AuditLogger $auditLogger,
    ): Response {
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_invite', (string) $request->request->get('_token', ''))) {
                $errors['csrf'] = 'Invalid CSRF token.';
            } else {
                $email = trim((string) $request->request->get('email', ''));

                if ($email === '') {
                    $errors['email'] = 'Email is required.';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors['email'] = 'Please enter a valid email address.';
                }

                if ($errors === []) {
                    $invitationService->send($email);

                    $auditLogger->log(
                        $this->getUser()?->getUserIdentifier() ?? 'unknown',
                        'admin',
                        $request->getClientIp() ?? '0.0.0.0',
                        'admin.user_invite',
                        'success',
                        $email
                    );

                    $this->addFlash('success', 'Invitation sent to ' . $email . '.');

                    return $this->redirectToRoute('app_admin_users_invitations');
                }
            }
        }

        return $this->render('admin/invitations/invite.html.twig', [
            'errors' => $errors,
        ]);
    }

    #[Route('/invitations', name: 'app_admin_users_invitations', methods: ['GET'])]
    public function list(Request $request, InvitationRepository $invitationRepository): Response
    {
        $page        = max(1, (int) $request->query->get('page', '1'));
        $total       = $invitationRepository->countAll();
        $invitations = $invitationRepository->findPaginated($page, self::PAGE_SIZE);
        $pages       = max(1, (int) ceil($total / self::PAGE_SIZE));

        return $this->render('admin/invitations/list.html.twig', [
            'invitations' => $invitations,
            'currentPage' => $page,
            'totalPages'  => $pages,
            'total'       => $total,
        ]);
    }

    #[Route('/invitations/{id}/resend', name: 'app_admin_users_invitations_resend', methods: ['POST'])]
    public function resend(
        int $id,
        Request $request,
        InvitationRepository $invitationRepository,
        InvitationService $invitationService,
        AuditLogger $auditLogger,
    ): Response {
        $invitation = $invitationRepository->find($id);
        if ($invitation === null) {
            throw $this->createNotFoundException('Invitation not found.');
        }

        if (!$this->isCsrfTokenValid('admin_invite_resend_' . $id, (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Used-ness guard lives in InvitationService, so web and API reject identically (ADR-022).
        try {
            $invitationService->resend($invitation);
        } catch (InvitationAlreadyUsedException) {
            $this->addFlash('error', 'This invitation has already been used and cannot be resent.');

            return $this->redirectToRoute('app_admin_users_invitations');
        }

        $auditLogger->log(
            $this->getUser()?->getUserIdentifier() ?? 'unknown',
            'admin',
            $request->getClientIp() ?? '0.0.0.0',
            'admin.user_invite_resend',
            'success',
            $invitation->getEmail()
        );

        $this->addFlash('success', 'Invitation resent to ' . $invitation->getEmail() . '.');

        return $this->redirectToRoute('app_admin_users_invitations');
    }
}
