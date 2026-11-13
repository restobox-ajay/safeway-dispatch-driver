<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AuditLogRepository;
use App\Service\AuditLogFilterParser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/audit-log', name: 'app_admin_audit_log', methods: ['GET'])]
class AdminAuditLogController extends AbstractController
{
    private const PAGE_SIZE = 20;

    public function __invoke(Request $request, AuditLogRepository $repository, AuditLogFilterParser $filterParser): Response
    {
        // Invalid date inputs are dropped from $filters and surfaced as
        // $result->error; the page renders gracefully (200) instead of a 500.
        $result  = $filterParser->parse($request);
        $filters = $result->filters;

        $page    = max(1, (int) $request->query->get('page', '1'));
        $total   = $repository->countFiltered($filters);
        $entries = $repository->findPaginated($page, self::PAGE_SIZE, $filters);
        $pages   = max(1, (int) ceil($total / self::PAGE_SIZE));

        return $this->render('admin/audit_log/index.html.twig', [
            'entries'     => $entries,
            'filters'     => $request->query->all(),
            'filterError' => $result->error,
            'currentPage' => $page,
            'totalPages'  => $pages,
            'total'       => $total,
        ]);
    }
}
