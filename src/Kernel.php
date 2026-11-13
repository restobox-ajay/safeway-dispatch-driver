<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /** The logger (config/services.yaml) writes to var/log/<env>.log and cannot create the folder itself. */
    public function boot(): void
    {
        $logs = $this->getLogDir();
        if (!is_dir($logs)) {
            @mkdir($logs, 0o775, true);
        }
        parent::boot();
    }
}
