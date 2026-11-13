<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Routing\RouteRequirement;
use App\Entity\Invitation;
use App\Exception\InvitationAlreadyUsedException;
use App\Repository\InvitationRepository;
use App\Service\AuditLogger;
use App\Service\InvitationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin-api/invitations')]
final class AdminApiInvitationController extends AbstractController
{
    #[Route('', name: 'app_api_admin_invitations_send', methods: ['POST'])]
    public function send(
        Request $request,
        InvitationService $invitationService,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $data  = json_decode($request->getContent(), true) ?? [];
        $email = trim((string) ($data['email'] ?? ''));

        if ($email === '') {
            return new JsonResponse(['error' => 'Email is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'Please enter a valid email address.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $invitation = $invitationService->send($email);

        $this->audit($auditLogger, $request, 'admin.user_invite', $email);

        return new JsonResponse($this->serializeInvitation($invitation), Response::HTTP_CREATED);
    }

    #[Route('/{id}/resend', name: 'app_api_admin_invitations_resend', requirements: ['id' => RouteRequirement::ID], methods: ['POST'])]
    public function resend(
        int $id,
        Request $request,
        InvitationRepository $invitationRepository,
        InvitationService $invitationService,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $invitation = $invitationRepository->find($id);
        if ($invitation === null) {
            return new JsonResponse(['error' => 'Invitation not found.'], Response::HTTP_NOT_FOUND);
        }

        // Used-ness guard lives in InvitationService, so web and API reject identically (ADR-022).
        try {
            $invitationService->resend($invitation);
        } catch (InvitationAlreadyUsedException) {
            return new JsonResponse(
                ['error' => 'Invitation has already been used and cannot be resent.'],
                Response::HTTP_CONFLICT
            );
        }

        $this->audit($auditLogger, $request, 'admin.user_invite_resend', $invitation->getEmail());

        return new JsonResponse($this->serializeInvitation($invitation));
    }

    /** @param string $target the affected account's email, recorded as the audit context (issue #46) */
    private function audit(AuditLogger $auditLogger, Request $request, string $action, string $target): void
    {
        $auditLogger->log(
            $this->getUser()?->getUserIdentifier() ?? 'unknown',
            'admin',
            $request->getClientIp() ?? '0.0.0.0',
            $action,
            'success',
            $target
        );
    }

    private function serializeInvitation(Invitation $invitation): array
    {
        return [
            'id'         => $invitation->getId(),
            'email'      => $invitation->getEmail(),
            'expires_at' => $invitation->getExpiresAt()->format(\DateTimeInterface::ATOM),
            'used_at'    => $invitation->getUsedAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $invitation->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
