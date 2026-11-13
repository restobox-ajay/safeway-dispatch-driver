<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Driver\Api\DriverApiException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * How a failed admin API call reaches the driver (FEATURE-192/193):
 *  - tokens could not be renewed → back to the login page (JSON: 401 with `redirect`);
 *  - a JSON action (/driver/api/…) → `{error, errors, code}` with the API's status (offline → 503 with
 *    `offline: true`, so the page keeps the tap in its offline queue);
 *  - a page → the error screen, worded like the Android app.
 */
#[AsEventListener(event: ExceptionEvent::class, priority: 16)]
final readonly class DriverApiExceptionListener
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private Environment $twig,
    ) {}

    public function __invoke(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        $request = $event->getRequest();
        if (!$e instanceof DriverApiException || !str_starts_with($request->getPathInfo(), '/driver')) {
            return;
        }
        $json = str_starts_with($request->getPathInfo(), '/driver/api/');
        $login = $this->urls->generate('app_driver_login');

        if ($e->isSessionExpired()) {
            $event->setResponse($json
                ? new JsonResponse(['error' => $e->getMessage(), 'code' => 'SESSION_EXPIRED', 'redirect' => $login], Response::HTTP_UNAUTHORIZED)
                : new RedirectResponse($login));

            return;
        }

        $status = $e->offline ? Response::HTTP_SERVICE_UNAVAILABLE : ($e->status !== null && $e->status >= 400 && $e->status < 600 ? $e->status : Response::HTTP_BAD_GATEWAY);
        if ($json) {
            $event->setResponse(new JsonResponse(array_filter([
                'error' => $e->getMessage(),
                'errors' => $e->fieldErrors,
                'code' => $e->errorCode,
                'offline' => $e->offline ?: null,
            ], static fn ($v) => $v !== null && $v !== []) + $e->extra, $status)); // GitHub #46 #49: structured extras

            return;
        }

        $event->setResponse(new Response($this->twig->render('driver/error.html.twig', [
            'message' => $e->getMessage(),
            'offline' => $e->offline,
        ]), $status));
    }
}
