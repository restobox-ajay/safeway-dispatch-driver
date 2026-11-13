<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

class RootController extends AbstractController
{
    /**
     * The application has no landing page of its own at '/', so instead of leaking
     * Symfony's default "Welcome to Symfony!" page (or a bare 404) we always redirect:
     * authenticated visitors go to their post-login landing page (the user firewall's
     * default_target_path, /dashboard), everyone else goes to the login form.
     */
    #[Route('/', name: 'app_root')]
    public function index(): RedirectResponse
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_dashboard');
        }

        // The app's front door is the driver login; staff sign in at /staff/login.
        return $this->redirectToRoute('app_driver_login');
    }
}
