<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\OpenApiSpec;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API documentation for the two REST surfaces (ADR-060): a Swagger UI page plus the OpenAPI document it
 * renders. Open to every admin class — any admin role can be issued an admin API token, so any of them may
 * need to read how to use it (Ken). The spec is served through this gated route rather than as a static
 * file, so the API description is not public.
 *
 * Endpoints only tech support may call (`x-audience: tech-support`, e.g. the Htaccess Lock API) are left out of
 * the document served to every other admin — the docs never advertise what the viewer cannot use.
 *
 * The spec is hand-written (docs/api/openapi.yaml) and held to the real routes and responses by
 * OpenApiSpecTest / OpenApiContractTest / ApiDocsCest, so it cannot silently drift.
 */
#[Route('/admin/api-docs')]
#[IsGranted('ROLE_ADMIN')]
class AdminApiDocsController extends AbstractController
{
    public function __construct(private readonly OpenApiSpec $spec)
    {
    }

    #[Route('', name: 'app_admin_api_docs', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/api_docs/index.html.twig', [
            'specUrl' => $this->generateUrl('app_admin_api_docs_spec'),
        ]);
    }

    #[Route('/openapi.json', name: 'app_admin_api_docs_spec', methods: ['GET'])]
    public function spec(): JsonResponse
    {
        $response = JsonResponse::fromJsonString($this->spec->jsonFor($this->isGranted('ROLE_TECH_SUPPORT')));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
