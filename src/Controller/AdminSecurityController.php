<?php

declare(strict_types=1);

namespace App\Controller;

use App\EventListener\AdminRememberMeListener;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class AdminSecurityController extends AbstractController
{
    #[Route('/admin/login', name: 'app_admin_login')]
    public function login(Request $request, AuthenticationUtils $authenticationUtils): Response
    {
        // Already signed in? Send them to the admin dashboard instead of the login form.
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $error = $authenticationUtils->getLastAuthenticationError();

        // getLastUsername() is session-scoped, so it only survives a failed attempt within the same
        // session. For a genuine "prefill on next visit" (days later, after the session expired) fall
        // back to the opt-in ADMIN_LAST_EMAIL cookie written at login when "Remember me" was ticked
        // (ADR-051). Its presence also re-ticks the box, so the choice is sticky.
        $rememberedEmail = $request->cookies->get(AdminRememberMeListener::EMAIL_COOKIE);
        $lastUsername = $authenticationUtils->getLastUsername() ?: (string) ($rememberedEmail ?? '');

        return $this->render('admin/security/login.html.twig', [
            'last_username' => $lastUsername,
            'remember_me_checked' => $rememberedEmail !== null && $rememberedEmail !== '',
            'error' => $error,
        ]);
    }

    #[Route('/admin/logout', name: 'app_admin_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method should never be reached; the firewall intercepts logout requests.');
    }
}
