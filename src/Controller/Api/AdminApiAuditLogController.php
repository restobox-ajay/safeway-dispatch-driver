<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\AuditLog;
use App\Repository\AuditLogRepository;
use App\Service\AuditLogFilterParser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin-api/audit-log')]
final class AdminApiAuditLogController extends AbstractController
{
    /**
     * Pages are clamped to 1..MAX_PAGE (issue #53): a huge ?page= saturated (int) to PHP_INT_MAX, and
     * (page - 1) * size became a float that the int-typed setFirstResult() rejected with a 500.
     */
    private const MAX_PAGE = 1_000_000;

    private const PAGE_SIZE = 20;

    #[Route('', name: 'app_api_admin_audit_log', methods: ['GET'])]
    public function list(Request $request, AuditLogRepository $repository, AuditLogFilterParser $filterParser): JsonResponse
    {
        $result = $filterParser->parse($request);
        if (!$result->isValid()) {
            return new JsonResponse(['error' => $result->error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $filters = $result->filters;

        $page    = max(1, min((int) $request->query->get('page', '1'), self::MAX_PAGE));
        $total   = $repository->countFiltered($filters);
        $entries = $repository->findPaginated($page, self::PAGE_SIZE, $filters);
        $pages   = max(1, (int) ceil($total / self::PAGE_SIZE));

        return new JsonResponse([
            'data' => array_map($this->serializeEntry(...), $entries),
            'meta' => [
                'total'       => $total,
                'page'        => $page,
                'total_pages' => $pages,
            ],
        ]);
    }

    private function serializeEntry(AuditLog $entry): array
    {
        return [
            'id'         => $entry->getId(),
            'actor'      => $entry->getActor(),
            'actor_type' => $entry->getActorType(),
            'ip'         => $entry->getIp(),
            'action'     => $entry->getAction(),
            'outcome'    => $entry->getOutcome(),
            'context'    => $entry->getContext(),
            'created_at' => $entry->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
