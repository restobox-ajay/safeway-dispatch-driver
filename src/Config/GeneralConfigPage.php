<?php

declare(strict_types=1);

namespace App\Config;

class GeneralConfigPage implements ConfigPageProviderInterface
{
    public function getSlug(): string
    {
        return 'general';
    }

    public function getTitle(): string
    {
        return 'General';
    }

    public function getFields(): array
    {
        return [
            'registration.mode' => [
                'label'   => 'Registration Mode',
                'type'    => 'enum',
                'options' => ['open', 'invitation-only'],
                'default' => 'open',
            ],
            'audit_log.retention_days' => [
                'label'   => 'Audit Log Retention (days)',
                'type'    => 'int',
                'min'     => 1,
                'default' => '90',
            ],
            'login_notifications.enabled' => [
                'label'   => 'Enable Login Notifications',
                'type'    => 'bool',
                'default' => '1',
            ],
            'login_notifications.recognition_mode' => [
                'label'   => 'Login Notification Recognition Mode (fingerprint, ip_only)',
                'type'    => 'enum',
                'options' => ['fingerprint', 'ip_only'],
                'default' => 'fingerprint',
            ],
            // FEATURE-108: admin login notifications are configured with their OWN explicit keys
            // (not silently reusing the user keys above), so the two realms can be tuned separately.
            'login_notifications.admin_enabled' => [
                'label'   => 'Enable Admin Login Notifications',
                'type'    => 'bool',
                'default' => '1',
            ],
            'login_notifications.admin_recognition_mode' => [
                'label'   => 'Admin Login Notification Recognition Mode (fingerprint, ip_only)',
                'type'    => 'enum',
                'options' => ['fingerprint', 'ip_only'],
                'default' => 'fingerprint',
            ],
            'email_verification.mode' => [
                'label'   => 'Email Verification Mode (disabled, optional, required)',
                'type'    => 'enum',
                'options' => ['disabled', 'optional', 'required'],
                'default' => 'disabled',
            ],
            // ADR-051 / session lifetime. The session is the authoritative credential in the admin
            // realm, so these two knobs decide how long a signed-in principal stays signed in.
            // `idle_lifetime_minutes` is the baseline sliding idle window for every session;
            // `remember_me_lifetime_days` replaces it for a session whose admin ticked "Remember me".
            // Both are read per request by App\Session\SessionTtlResolver and applied as the
            // PdoSessionHandler TTL, so a change here takes effect on the next request — no redeploy.
            'session.idle_lifetime_minutes' => [
                'label'   => 'Session Idle Lifetime (minutes)',
                'type'    => 'int',
                'min'     => 1,
                'default' => '180',
            ],
            'session.remember_me_lifetime_days' => [
                'label'   => 'Admin "Remember Me" Session Lifetime (days)',
                'type'    => 'int',
                'min'     => 1,
                'default' => '21',
            ],
            'remember_me.lifetime_days' => [
                'label'   => 'Remember Me Lifetime (days)',
                'type'    => 'int',
                'min'     => 1,
                'default' => '30',
            ],
            'invitation.expiry_days' => [
                'label'   => 'Invitation Expiry (days)',
                'type'    => 'int',
                'min'     => 1,
                'default' => '7',
            ],
            // FEATURE-147 / ADR-048: retention grace before app:prune reclaims a terminal
            // (expired-or-used) invitation. Invitations are not logs; provenance is in the audit log.
            'invitation.retention_days' => [
                'label'   => 'Invitation Retention (days)',
                'type'    => 'int',
                'min'     => 1,
                'default' => '30',
            ],
        ];
    }
}
