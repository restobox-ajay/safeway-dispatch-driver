<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\Driver\DriverUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

final class RootController extends AbstractController
{
    /** The front door: a signed-in driver goes to Upcoming Destinations, anyone else to the login. */
    #[Route('/', name: 'app_root', methods: ['GET'])]
    public function index(): RedirectResponse
    {
        return $this->redirectToRoute($this->getUser() instanceof DriverUser ? 'app_driver_tasks' : 'app_driver_login');
    }
}
