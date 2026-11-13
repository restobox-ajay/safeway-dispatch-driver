<?php

declare(strict_types=1);

namespace App\Controller\Driver;

use App\Driver\Api\DriverApiClient;
use App\Driver\StopView;
use App\Security\Driver\DriverUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The driver pages, one per Figma frame and spec item ("Driver Web App - Do Mobile Only For now"):
 *  - Upcoming Destinations (main page: next deliveries from the immediate next, ETA; Details / Add Note / Complete)
 *  - Completed Destinations
 *  - Destination Details (company, stop, address -> Google Maps, phone, special instructions, products, ETA, dispatch note)
 *  - Add Note, Mark Complete (complete with a photo, or not delivered / other issue with a reason)
 *  - Work Hours (start, breaks, end)
 * Every page reads the Safeway Driver API (GET /routes/today, GET /stops/{id}); the taps go through /driver/api (stop.js).
 */
#[IsGranted(DriverUser::ROLE)]
final class DriverPagesController extends AbstractController
{
    public const PER_PAGE = 10;
    private const STOP_ID = 'stp_[A-Za-z0-9_-]{1,64}';

    public function __construct(private readonly DriverApiClient $api) {}

    #[Route('/driver/tasks', name: 'app_driver_tasks', methods: ['GET'])]
    public function upcoming(Request $request): Response
    {
        [$routes, $tz] = $this->today();
        $cards = [];
        $nextId = null;
        foreach ($routes as $route) {
            foreach ($route['stops'] as $stop) {
                if ($stop['done'] || !StopView::isWork($stop)) {
                    continue;
                }
                $nextId ??= $stop['id'];
                $cards[] = $stop + ['route_name' => $route['name']];
            }
        }

        return $this->render('driver/tasks.html.twig', [
            'routes' => $routes,
            'next_id' => $nextId,
            'tab' => 'upcoming',
        ] + self::page($cards, $request));
    }

    #[Route('/driver/completed', name: 'app_driver_completed', methods: ['GET'])]
    public function completed(Request $request): Response
    {
        [$routes] = $this->today();
        $cards = [];
        foreach ($routes as $route) {
            foreach ($route['stops'] as $stop) {
                if ($stop['done'] && StopView::isWork($stop)) {
                    $cards[] = $stop;
                }
            }
        }
        usort($cards, static fn (array $a, array $b): int => strcmp((string) $b['completed_at'], (string) $a['completed_at']));

        return $this->render('driver/completed.html.twig', ['tab' => 'completed'] + self::page($cards, $request));
    }

    #[Route('/driver/stops/{id}', name: 'app_driver_stop', requirements: ['id' => self::STOP_ID], methods: ['GET'])]
    public function stop(string $id): Response
    {
        return $this->render('driver/stop.html.twig', ['stop' => $this->oneStop($id), 'tab' => 'upcoming']);
    }

    #[Route('/driver/stops/{id}/note', name: 'app_driver_note', requirements: ['id' => self::STOP_ID], methods: ['GET'])]
    public function note(string $id): Response
    {
        return $this->render('driver/note.html.twig', ['stop' => $this->oneStop($id), 'reasons' => StopView::REASONS, 'tab' => 'upcoming']);
    }

    #[Route('/driver/stops/{id}/complete', name: 'app_driver_complete', requirements: ['id' => self::STOP_ID], methods: ['GET'])]
    public function complete(string $id): Response
    {
        $stop = $this->oneStop($id);
        $tasks = array_map(static fn (array $t): array => $t + [
            'label' => StopView::typeLabel((string) ($t['type'] ?? '')),
            'outcomes' => StopView::outcomes((string) ($t['type'] ?? '')),
        ], $stop['tasks']);

        return $this->render('driver/complete.html.twig', [
            'stop' => $stop,
            'tasks' => $tasks,
            'outcome_labels' => StopView::OUTCOME_LABELS,
            'reasons' => StopView::REASONS,
            'tab' => 'upcoming',
        ]);
    }

    #[Route('/driver/hours', name: 'app_driver_hours', methods: ['GET'])]
    public function hours(): Response
    {
        $me = $this->api->get('me');

        return $this->render('driver/hours.html.twig', [
            'me' => \is_array($me) ? $me : [],
            'tab' => 'hours',
        ]);
    }

    /** A proof photo of the driver's own stop (GET /photos/{id} answers a driver only their own photos). */
    #[Route('/driver/photos/{photoId}', name: 'app_driver_photo', requirements: ['photoId' => 'pho_[A-Za-z0-9]{8,40}'], methods: ['GET'])]
    public function photo(string $photoId): Response
    {
        $photo = $this->api->photo($photoId);
        $type = \in_array($photo['type'], ['image/jpeg', 'image/png'], true) ? $photo['type'] : 'image/jpeg';
        $response = new Response($photo['content'], Response::HTTP_OK, ['Content-Type' => $type, 'X-Content-Type-Options' => 'nosniff']);
        // Not kept by the browser: on a shared phone the next driver must not find these in the cache.
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * Today's routes, each with its stops as StopView arrays.
     *
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    private function today(): array
    {
        $res = $this->api->get('routes/today');
        $tz = \is_array($res) && \is_string($res['time_zone'] ?? null) ? $res['time_zone'] : 'UTC';
        $routes = [];
        foreach (\is_array($res['routes'] ?? null) ? $res['routes'] : [] as $route) {
            if (!\is_array($route)) {
                continue;
            }
            $stops = array_map(static fn (array $s): array => StopView::of($s, $tz), array_values(array_filter(\is_array($route['stops'] ?? null) ? $route['stops'] : [], 'is_array')));
            $routes[] = [
                'ref' => (string) ($route['ref'] ?? ''),
                'name' => (string) ($route['name'] ?? ''),
                'status' => (string) ($route['status'] ?? ''),
                'truck' => \is_array($route['truck'] ?? null) ? (string) ($route['truck']['name'] ?? '') : '',
                'next_allowed' => \is_array($route['next_allowed'] ?? null) ? $route['next_allowed'] : [],
                'start_blocked' => \is_string($route['start_blocked'] ?? null) ? $route['start_blocked'] : null,
                'stops' => $stops,
                'left' => \count(array_filter($stops, static fn (array $s): bool => !$s['done'] && StopView::isWork($s))),
            ];
        }

        return [$routes, $tz];
    }

    /** @return array<string, mixed> */
    private function oneStop(string $id): array
    {
        $res = $this->api->get('stops/' . $id);
        $me = $this->api->get('me');
        $tz = \is_array($me) && \is_string($me['time_zone'] ?? null) ? $me['time_zone'] : 'UTC';

        return StopView::of(\is_array($res['stop'] ?? null) ? $res['stop'] : [], $tz);
    }

    /**
     * "Showing 1 to 10 of N entries" with Previous / numbers / Next, as on the Figma lists.
     *
     * @param list<array<string, mixed>> $cards
     *
     * @return array<string, mixed>
     */
    private static function page(array $cards, Request $request): array
    {
        $total = \count($cards);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, $request->query->getInt('page', 1)));
        $offset = ($page - 1) * self::PER_PAGE;

        return [
            'cards' => \array_slice($cards, $offset, self::PER_PAGE),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'from' => $total === 0 ? 0 : $offset + 1,
            'to' => min($total, $offset + self::PER_PAGE),
        ];
    }
}
