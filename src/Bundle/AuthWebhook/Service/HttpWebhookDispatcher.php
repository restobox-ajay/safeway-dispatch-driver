<?php

declare(strict_types=1);

namespace App\Bundle\AuthWebhook\Service;

use App\Bundle\AuthWebhook\Entity\WebhookDelivery;
use App\Bundle\AuthWebhook\Security\SsrfGuard;
use App\Bundle\AuthWebhook\Security\SsrfInspection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The single-attempt webhook sender invoked by SendWebhookMessageHandler on the Messenger worker.
 *
 * It performs exactly ONE outbound HTTP POST (behind the SSRF guard), records one WebhookDelivery
 * audit row, and returns it. Retries and backoff are NOT its concern anymore — Messenger's
 * retry_strategy owns them (ADR-023). The SSRF guard (ADR-027) resolves the target once, refuses
 * it if any resolved address is in a blocked range (default ON), and PINS the connection to the
 * validated IP (CURLOPT_RESOLVE) with redirects refused, closing the DNS-rebind TOCTOU.
 */
class HttpWebhookDispatcher
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        // Nullable so the ControlledHttpWebhookDispatcher test double (which overrides sendHttp)
        // can still be constructed with just the EntityManager. In prod/dev it is autowired.
        private readonly ?SsrfGuard $guard = null,
    ) {}

    /**
     * Perform one delivery attempt, log it, and return the persisted WebhookDelivery.
     * A non-2xx (or SSRF-blocked / unreachable, both yield code 0) result is logged as 'failed';
     * the caller (handler) decides whether to throw so Messenger retries.
     *
     * @param array<string,mixed> $payload
     */
    public function send(string $url, array $payload): WebhookDelivery
    {
        $eventType    = (string) ($payload['event_type'] ?? 'unknown');
        $json         = json_encode($payload, JSON_THROW_ON_ERROR);
        $responseCode = $this->sendHttp($url, $json);
        $success      = $responseCode >= 200 && $responseCode < 300;

        $delivery = new WebhookDelivery(
            url: $url,
            eventType: $eventType,
            payload: $json,
            status: $success ? 'delivered' : 'failed',
        );
        $delivery->setResponseCode($responseCode > 0 ? $responseCode : null);

        $this->em->persist($delivery);
        $this->em->flush();

        return $delivery;
    }

    protected function sendHttp(string $url, string $json): int
    {
        // SSRF guard (ADR-027): resolve + validate ONCE. When a guard is wired we get back the
        // validated IPs to pin the connection to; without one (should not happen in prod) fall
        // back to the static ON-posture check. Non-http(s) schemes are refused unconditionally.
        $inspection = $this->guard?->inspect($url);
        if ($inspection !== null) {
            if (!$inspection->allowed) {
                return 0;
            }
        } elseif (!self::isAllowedUrl($url)) {
            return 0;
        }

        return $this->post($url, $json, $inspection);
    }

    /**
     * Perform the POST via cURL, pinning the connection to the already-validated IP(s) so no
     * second (unchecked) DNS resolution happens at connect. The Host header / TLS SNI stay the
     * original hostname. Redirects are refused outright (a 3xx to a fresh host would re-open SSRF).
     */
    private function post(string $url, string $json, ?SsrfInspection $inspection): int
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return 0;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($json),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, // AC7: never follow a 3xx to an unchecked host
            CURLOPT_MAXREDIRS      => 0,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        // AC6: pin DNS to the validated IP(s). CURLOPT_RESOLVE overrides resolution for
        // host:port only, leaving the Host header and SNI as the original hostname.
        if ($inspection !== null && $inspection->host !== null && $inspection->pinnedIps !== []) {
            curl_setopt($ch, CURLOPT_RESOLVE, [
                sprintf('%s:%d:%s', $inspection->host, $inspection->port, implode(',', $inspection->pinnedIps)),
            ]);
        }

        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $code;
    }

    /**
     * True only for an http/https URL whose host resolves exclusively to publicly-routable
     * addresses (blocking ON). Delegates to the hard-coded {@see SsrfGuard} blocklist (ADR-027).
     */
    public static function isAllowedUrl(string $url): bool
    {
        return SsrfGuard::isAllowedUrl($url);
    }
}
