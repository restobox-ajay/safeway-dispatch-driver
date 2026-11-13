# FEATURE-092 — ROLE_ADMIN / ROLE_SUPER_ADMIN Usage Audit

**Date:** 2026-06-21
**Context:** After FEATURE-081 (security review H2), admin authorization is
entity-based: the `admin` (`^/admin`) and `admin_api` (`^/admin-api`) firewalls
authenticate **Admin** entities via the `app_admins` provider; the `user` and
`api` firewalls authenticate **User** entities via `app_users`. A `User` can no
longer hold an admin role (`User::ALLOWED_ROLES = ['ROLE_USER']`, enforced as an
allowlist in `User::setRoles()`).

This audit enumerates **every** `ROLE_ADMIN` / `ROLE_SUPER_ADMIN` reference in
`src/`, `templates/`, and `config/` and classifies each as **CORRECT** or
**SUSPECT**.

## Firewall map (config/packages/security.yaml)

| Firewall   | Pattern        | Provider     | Authenticates |
|------------|----------------|--------------|---------------|
| admin_api  | `^/admin-api`  | app_admins   | Admin         |
| admin      | `^/admin`      | app_admins   | Admin         |
| api        | `^/api`        | app_users    | User          |
| user       | (default)      | app_users    | User          |

`role_hierarchy: ROLE_SUPER_ADMIN: [ROLE_ADMIN]` is global, but only **Admin**
entities ever hold either role (the User allowlist forbids them).

## src/

| # | Location | Reference | Classification |
|---|----------|-----------|----------------|
| 1 | `Entity/Admin.php:70,75` | `ALLOWED_ROLES`, getRoles() appends `ROLE_ADMIN` | **CORRECT** — Admin role single-source-of-truth; setRoles() allowlist. |
| 2 | `Entity/User.php:99` | doc comment ("admin roles MUST NOT be listed") | **CORRECT** — documents the boundary; `User::ALLOWED_ROLES` excludes admin roles. |
| 3 | `Command/CreateSuperAdminCommand.php:61` | `setRoles(['ROLE_SUPER_ADMIN'])` on an Admin | **CORRECT** — provisions an Admin entity. |
| 4 | `Twig/InstanceOfExtension.php:14` | doc comment | **CORRECT** — describes the `instanceof` test that replaced `is_granted('ROLE_ADMIN')` in nav. |
| 5 | `Controller/AdminInvitationController.php:21` | `#[IsGranted('ROLE_ADMIN')]` on `/admin/users/*` | **CORRECT** — admin firewall. |
| 6 | `Controller/AdminConfigController.php:15` | `#[IsGranted('ROLE_ADMIN')]` on `/admin/config` | **CORRECT** — admin firewall. |
| 7 | `Controller/AdminUserController.php:23` | `#[IsGranted('ROLE_ADMIN')]` on `/admin/users` | **CORRECT** — admin firewall. |
| 8 | `Controller/AdminDashboardController.php:12` | `#[IsGranted('ROLE_ADMIN')]` on `/admin/dashboard` | **CORRECT** — admin firewall. |
| 9 | `Controller/AdminAuditLogController.php:14` | `#[IsGranted('ROLE_ADMIN')]` on `/admin/audit-log` | **CORRECT** — admin firewall. |
| 10 | `Controller/SuperAdminController.php:13` | `#[IsGranted('ROLE_SUPER_ADMIN')]` on `/admin/superadmin` | **CORRECT** — admin firewall. |
| 11 | `Controller/AdminAdminManagementController.php:17` | `#[IsGranted('ROLE_SUPER_ADMIN')]` on `/admin/superadmin/admins` | **CORRECT** — admin firewall. |
| 12 | `Controller/ImpersonationController.php:54` | `#[IsGranted('ROLE_ADMIN')]` on `/admin/impersonate-admin-exit` | **CORRECT** — route prefixed `^/admin` → admin firewall (Admin entity), not the user-firewall actions in the same controller (`/impersonate/*`, which use `ROLE_USER`). |
| 13 | `Controller/Api/AdminApiUserController.php:24` | `#[IsGranted('ROLE_ADMIN')]` on `/admin-api/users` | **CORRECT** — admin_api firewall. |
| 14 | `Controller/Api/AdminApiAuditLogController.php:15` | `#[IsGranted('ROLE_ADMIN')]` on `/admin-api/audit-log` | **CORRECT** — admin_api firewall. |
| 15 | `Controller/Api/AdminApiInvitationController.php:21` | `#[IsGranted('ROLE_ADMIN')]` on `/admin-api/invitations` | **CORRECT** — admin_api firewall. |

Every `IsGranted('ROLE_ADMIN'/'ROLE_SUPER_ADMIN')` sits on a route prefixed
`^/admin` or `^/admin-api` — i.e. only on the admin / admin_api firewalls, which
authenticate Admin entities. **None on the user firewall.** (AC2 ✓)

## templates/

| # | Location | Reference | Classification |
|---|----------|-----------|----------------|
| 16 | `layout/_admin_nav.html.twig:12` | `is_granted('ROLE_SUPER_ADMIN')` | **CORRECT** — `base.html.twig:55-59` includes `_admin_nav` only when `app.user is instanceof('App\Entity\Admin')`, so this check only ever runs against an Admin token. A User can never hold ROLE_SUPER_ADMIN. Identity-safe. |
| 17 | `admin/superadmin/admins.html.twig:24` | `'ROLE_SUPER_ADMIN' not in admin.roles` | **CORRECT** — `admin` is a listed-row Admin entity; this is a data-display decision (hide the "Impersonate" button for superadmins), not an authorization decision about the current session. |

## config/

| # | Location | Reference | Classification |
|---|----------|-----------|----------------|
| 18 | `security.yaml:6` | `role_hierarchy: ROLE_SUPER_ADMIN: [ROLE_ADMIN]` | **CORRECT** — only Admin entities hold these roles. |
| 19 | `security.yaml:73` | access_control `^/admin-api → ROLE_ADMIN` | **CORRECT** — enforced within the admin_api firewall (Admin entity). |
| 20 | `security.yaml:74` | access_control `^/admin → ROLE_ADMIN` | **CORRECT** — enforced within the admin firewall (Admin entity). |

## Conclusion

**No conflation of role-string with entity identity found. No production code
changes required.** Every reference is one of: (a) an admin-firewall route guard,
(b) the Admin entity's own role source-of-truth, (c) provisioning of an Admin,
(d) entity-keyed nav inclusion, or (e) data display over a listed Admin's roles.
No user-firewall / user-context code grants behavior off a User's `ROLE_ADMIN`.

The invariants are locked in by tests:
- `tests/Functional/Security/AdminApiBoundaryTest.php` (FEATURE-081) — `/admin-api`
  boundary holds against a DB-injected admin-role User with a valid user PAT.
- `tests/Functional/Security/RoleBoundaryAuditTest.php` (FEATURE-092) — the web
  `^/admin` boundary and the entity-keyed sidebar hold against a DB-injected
  admin-role User; and `is_granted('ROLE_SUPER_ADMIN')` in `_admin_nav` reflects
  the authenticated Admin's actual identity (regular admin vs superadmin).
