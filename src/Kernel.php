<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Mirrors MicroKernelTrait's default route loading, then conditionally imports the routes of any
     * registered optional feature bundle under src/Bundle/ (FEATURE-138, C36 Option A). Because the
     * import is gated on bundle registration, a bundle's routes exist ONLY when it is enabled in
     * config/bundles.php — with it absent, its routes 404 (the "uninstalled" guarantee, AC4). Route
     * files are NOT auto-discovered from src/ (config/routes.yaml imports only App\Controller), so
     * this explicit, registration-gated import is the single place bundle routes are wired.
     */
    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $configDir = preg_replace('{/config$}', '/{config}', $this->getConfigDir());

        $routes->import($configDir.'/{routes}/'.$this->environment.'/*.{php,yaml}');
        $routes->import($configDir.'/{routes}/*.{php,yaml}');

        if (is_file($this->getConfigDir().'/routes.yaml')) {
            $routes->import($configDir.'/routes.yaml');
        } else {
            $routes->import($configDir.'/{routes}.php');
        }

        if ($fileName = (new \ReflectionObject($this))->getFileName()) {
            $routes->import($fileName, 'attribute');
        }

        if (isset($this->bundles['AuthPatBundle'])) {
            $routes->import('@AuthPatBundle/Resources/config/routes.php');
        }

        if (isset($this->bundles['AuthMagicLinkBundle'])) {
            $routes->import('@AuthMagicLinkBundle/Resources/config/routes.php');
        }

        if (isset($this->bundles['AuthImpersonationBundle'])) {
            $routes->import('@AuthImpersonationBundle/Resources/config/routes.php');
        }

        if (isset($this->bundles['Auth2faBundle'])) {
            $routes->import('@Auth2faBundle/Resources/config/routes.php');
        }

        if (isset($this->bundles['AuthSecurityBundle'])) {
            $routes->import('@AuthSecurityBundle/Resources/config/routes.php');
        }
    }

    /**
     * FEATURE-131 (review C17) — fail-fast host configuration guard.
     *
     * `framework.trusted_hosts` is built from DEFAULT_URI, ADMIN_DOMAIN and APP_DOMAIN
     * (config/packages/framework.yaml). An empty entry there compiles to a regex that matches
     * EVERY host, silently disabling host-header protection. This guard refuses to boot — in
     * web and CLI alike — unless DEFAULT_URI is a valid absolute URL and both domain vars are
     * non-empty, so no empty pattern can ever reach the trusted-hosts list. Single-domain
     * deployments set ADMIN_DOMAIN = APP_DOMAIN = the single host. Amends ADR-019 (ADR-029).
     */
    public function boot(): void
    {
        $this->assertRequiredHostConfig();

        parent::boot();
    }

    private function assertRequiredHostConfig(): void
    {
        $defaultUri = self::readEnv('DEFAULT_URI');
        $parts = $defaultUri !== '' ? parse_url($defaultUri) : false;
        if (!\is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \RuntimeException(sprintf(
                'Invalid host configuration: DEFAULT_URI must be a valid absolute URL with a scheme and host '
                . '(e.g. "https://app.example.com"); got %s. See ADR-029 (amends ADR-019).',
                var_export($defaultUri === '' ? null : $defaultUri, true)
            ));
        }

        $adminDomain = self::readEnv('ADMIN_DOMAIN');
        $appDomain = self::readEnv('APP_DOMAIN');
        if ($adminDomain === '' || $appDomain === '') {
            throw new \RuntimeException(
                'Invalid host configuration: ADMIN_DOMAIN and APP_DOMAIN must both be set so trusted_hosts '
                . 'cannot be silently disabled by an empty pattern. Single-domain deployments set both to the '
                . 'same host. See ADR-029 (amends ADR-019).'
            );
        }
    }

    private static function readEnv(string $name): string
    {
        // Symfony Dotenv populates $_ENV and $_SERVER (and, in prod, .env.local.php does too);
        // getenv() is the final fallback for real process-level env vars.
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return \is_string($value) ? trim($value) : '';
    }
}
