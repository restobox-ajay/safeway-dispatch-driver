<?php

declare(strict_types=1);

namespace App\Htaccess;

/**
 * Requests the site over the loopback interface (the host name is pinned to 127.0.0.1), so the web
 * server sees a connection from 127.0.0.1 regardless of DNS, CDNs or NAT. That known source address is
 * what lets the self-test insert "the server's own IP" into the whitelist and predict the outcome.
 */
final class CurlLoopbackProbe implements HtaccessProbeInterface
{
    public function status(string $scheme, string $host, int $port, string $path): ?int
    {
        $curl = curl_init(sprintf('%s://%s:%d%s', $scheme, $host, $port, $path));
        if ($curl === false) {
            return null;
        }

        curl_setopt_array($curl, [
            CURLOPT_RESOLVE => [sprintf('%s:%d:127.0.0.1', $host, $port)],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'htaccess-lock-self-test',
        ]);

        curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return $code > 0 ? $code : null;
    }
}
