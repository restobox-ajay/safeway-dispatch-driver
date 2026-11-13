<?php

declare(strict_types=1);

namespace App\Bundle\AuthWebhook\Entity;

use App\Bundle\AuthWebhook\Repository\WebhookDeliveryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WebhookDeliveryRepository::class)]
#[ORM\Table(name: 'webhook_delivery')]
#[ORM\Index(name: 'idx_webhook_delivery_event_type', columns: ['event_type'])]
#[ORM\Index(name: 'idx_webhook_delivery_status', columns: ['status'])]
class WebhookDelivery
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 2048)]
    private string $url;

    #[ORM\Column(length: 100)]
    private string $eventType;

    #[ORM\Column(type: 'text')]
    private string $payload;

    #[ORM\Column(length: 20)]
    private string $status;

    #[ORM\Column(nullable: true)]
    private ?int $responseCode = null;

    #[ORM\Column]
    private \DateTimeImmutable $attemptedAt;

    public function __construct(
        string $url,
        string $eventType,
        string $payload,
        string $status,
        ?\DateTimeImmutable $attemptedAt = null,
    ) {
        $this->url         = $url;
        $this->eventType   = $eventType;
        $this->payload     = $payload;
        $this->status      = $status;
        $this->attemptedAt = $attemptedAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getResponseCode(): ?int
    {
        return $this->responseCode;
    }

    public function setResponseCode(?int $responseCode): self
    {
        $this->responseCode = $responseCode;

        return $this;
    }

    public function getAttemptedAt(): \DateTimeImmutable
    {
        return $this->attemptedAt;
    }
}
