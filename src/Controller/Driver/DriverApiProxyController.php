<?php

declare(strict_types=1);

namespace App\Controller\Driver;

use App\Driver\Api\DriverApiClient;
use App\Driver\Api\DriverApiException;
use App\Security\Driver\DriverUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The driver pages' JSON actions: /driver/api/<path> is passed to the Safeway Driver API (docs/driver-api.md) as the
 * signed-in driver, for the calls the app makes and no others.
 *
 * Writes need the page's CSRF token in X-CSRF-Token. The tap's own Idempotency-Key (made in the browser when the
 * driver tapped, and kept by the offline queue) is passed through, so a replay is applied once. Failures become
 * JSON through DriverApiExceptionListener.
 */
#[IsGranted(DriverUser::ROLE)]
final class DriverApiProxyController extends AbstractController
{
    public const CSRF_ID = 'driver_action';

    private const STOP = 'stops/stp_[A-Za-z0-9_-]{1,64}';
    private const ROUTE = 'routes/R-\d{8}-\d{1,4}';

    /** method => path patterns (relative to /driver-api/v1/) the browser may call. */
    private const ALLOWED = [
        'GET' => [
            '#^me\z#', '#^routes/today\z#', '#^' . self::STOP . '\z#', '#^messages\z#',
        ],
        'POST' => [
            '#^' . self::STOP . '/(on-my-way|arrived|complete|undo|notes)\z#',
            '#^' . self::ROUTE . '/(start|finish)\z#',
            '#^photos\z#', '#^messages/\d+/read\z#', '#^positions\z#', '#^problems\z#', '#^hours\z#', '#^diagnostics\z#',
        ],
    ];

    /** The multipart fields of a photo upload (docs/driver-api.md section 5). */
    private const PHOTO_FIELDS = ['client_photo_id', 'taken_at', 'tier'];

    public function __construct(private readonly DriverApiClient $api) {}

    #[Route('/driver/api/{path}', name: 'app_driver_api_proxy', requirements: ['path' => '.+'], methods: ['GET', 'POST'])]
    public function __invoke(Request $request, string $path): Response
    {
        $method = $request->getMethod();
        if (!self::allowed($method, $path)) {
            return new JsonResponse(['error' => 'Not available.'], Response::HTTP_NOT_FOUND);
        }
        if ($method !== 'GET' && !$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['error' => 'This page is out of date. Reload it and try again.', 'code' => 'CSRF'], Response::HTTP_FORBIDDEN);
        }
        $key = self::idempotencyKey($request);

        $result = match (true) {
            $method === 'GET' => $this->api->get($path),
            $path === 'photos' => $this->upload($request, $key),
            default => $this->api->post($path, self::jsonBody($request), $key),
        };

        return $result === null ? new Response(null, Response::HTTP_NO_CONTENT) : new JsonResponse($result);
    }

    public static function allowed(string $method, string $path): bool
    {
        foreach (self::ALLOWED[$method] ?? [] as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private function upload(Request $request, ?string $key): mixed
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new DriverApiException('Add a photo.', 422, 'VALIDATION_FAILED', ['file' => 'Required.']);
        }
        $fields = [];
        foreach (self::PHOTO_FIELDS as $name) {
            $value = $request->request->get($name);
            if (\is_string($value) && $value !== '') {
                $fields[$name] = $value;
            }
        }

        return $this->api->upload('photos', $fields, 'file', $file->getPathname(), $file->getClientOriginalName() ?: 'photo.jpg', $file->getMimeType() ?? 'image/jpeg', $key);
    }

    /** @return array<string, mixed> */
    private static function jsonBody(Request $request): array
    {
        if ($request->getContent() === '') {
            return [];
        }
        $data = json_decode($request->getContent(), true);

        return \is_array($data) ? $data : [];
    }

    private static function idempotencyKey(Request $request): ?string
    {
        $key = trim((string) $request->headers->get('Idempotency-Key', ''));

        return $key !== '' && \strlen($key) <= 255 && preg_match('/^\S+$/', $key) === 1 ? $key : null;
    }
}
