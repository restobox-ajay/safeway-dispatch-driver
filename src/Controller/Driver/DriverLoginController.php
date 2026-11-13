<?php

declare(strict_types=1);

namespace App\Controller\Driver;

use App\Security\Driver\DriverPinAuthenticator;
use App\Security\Driver\DriverUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/** Driver login page (FEATURE-193). The POST is handled by DriverPinAuthenticator; logout by the firewall. */
final class DriverLoginController extends AbstractController
{
    #[Route('/driver', name: 'app_driver_home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->redirectToRoute(DriverPinAuthenticator::HOME_ROUTE);
    }

    /** The app's front door: /login is the driver login (login + PIN, as the mockup's login.html). */
    #[Route('/login', name: DriverPinAuthenticator::LOGIN_ROUTE, methods: ['GET', 'POST'])]
    public function login(Request $request, AuthenticationUtils $auth): Response
    {
        if ($this->getUser() instanceof DriverUser) {
            return $this->redirectToRoute(DriverPinAuthenticator::HOME_ROUTE);
        }

        $response = $this->render('driver/login.html.twig', [
            'last_username' => $auth->getLastUsername(),
            'error' => $auth->getLastAuthenticationError(),
        ]);
        if (DriverPinAuthenticator::deviceId($request) === 'web-unknown') {
            $response->headers->setCookie(Cookie::create(DriverPinAuthenticator::DEVICE_COOKIE, bin2hex(random_bytes(16)))
                ->withExpires(new \DateTimeImmutable('+5 years'))
                ->withPath('/')
                ->withSecure($request->isSecure())
                ->withHttpOnly(true)
                ->withSameSite(Cookie::SAMESITE_LAX));
        }

        return $response;
    }

    /** The old address, kept for bookmarks and home-screen icons made before the move. */
    #[Route('/driver/login', name: 'app_driver_login_old', methods: ['GET'])]
    public function oldLogin(): Response
    {
        return $this->redirectToRoute(DriverPinAuthenticator::LOGIN_ROUTE, [], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/driver/logout', name: 'app_driver_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Handled by the driver firewall\'s logout.');
    }
}
