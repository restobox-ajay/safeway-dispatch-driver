# FVST Express Driver — web app (PWA)

The browser version of the FVST Express driver Android app (`fvstexpress-driver-app` on GitHub), built on
this auth boilerplate. Every function of the Android app is here; the Android app itself stays in use.
Drivers open it on their phone's browser and can install it to the home screen.

**Theme:** the fvstexpress-admin app's (its `/login` page and `public/assets/css/style.css` tokens); the login page uses the admin sign-in card.

**Screens follow the driver HTML mockups** (`mockup-fvstexpress-admin/public/mockup-driver/`: login, tasks,
route map, task detail pickup / delivery / done / failed, camera, analytics, profile) and their `style.css`.

---

## 1. Architecture

```
phone browser ──► this app (/driver, Symfony) ──► fvstexpress-admin  /driver-api/v1
   PWA: pages,       driver firewall,             (all data: drivers, orders, PINs,
   service worker,   session holds the             tokens, pings, photos…)
   IndexedDB queue   driver's API tokens
```

- **All data lives in the admin app.** This app stores no orders, stops or drivers. It is a
  backend-for-frontend: each page and action calls the admin driver API (`docs/admin-driver-api.md`, a copy of
  the admin repo's `docs/DRIVER_API.md` — that file is the contract).
- **Drivers log in with the same login (employee card # or phone) and PIN as the Android app.** The PIN is
  checked by the admin API (`POST /auth/login`), with its lockouts and rate limit. This app keeps the
  returned access/refresh tokens in the driver's server-side session (never in the browser) and refreshes
  them on `401 TOKEN_INVALID`.
- **The boilerplate's own users and admins are for staff only** (the `/admin` panel to run this app). Drivers
  are not boilerplate users; the `driver` firewall has its own authenticator and session-backed user.
- **Configuration** (`.env.local`): `DRIVER_API_BASE_URL` (ends with `/driver-api/v1/`),
  `DRIVER_API_HOST_HEADER` (optional, for a WAMP vhost reached by IP), `DISPATCH_PHONE` (fallback for
  "Call Dispatch" until `/me` sends one), `DRIVER_GPS_TESTER` (test servers only: `1` adds the hidden GPS tester,
  see `docs/TESTING_LOCATION_PINGS.md`; unset = off).

### 1.1 Client IP

The admin API rate-limits logins per IP (20 / 15 min) and logs the IP. Every call from this app forwards the
driver's IP in `X-Forwarded-For`; the admin app must list this app's server in `TRUSTED_PROXIES` so the limit
applies per driver, not to the whole fleet behind one server IP (ADR-074, OQ in `.agent/OPEN_QUESTIONS.md`).

---

## 2. Functions (parity with the Android app)

| # | Function | Admin API calls |
|---|---|---|
| 2.1 | **Login / logout** at `/login` (the app's front door; `/` and the old `/driver/login` lead there; staff use `/staff/login`) with login + PIN; errors as the API words them | `auth/login`, `auth/refresh`, `auth/logout`, `me` |
| 2.2 | **App shell**: top bar with Online toggle, messages icon with unread badge, menu (drawer), bottom tabs Tasks / Analytics / Call Dispatch | `me`, `messages` |
| 2.3 | **Tasks**: Today / All / Completed, grouped into stops; pull to refresh | `stops?view=` |
| 2.4 | **Route map** for today: stops in order, driver's position. GitHub #48: the default task view — a Google map (browser key + Map ID from `/me` `google_maps`, loaded once per page view; the plain map without a key) with numbered pins as in the list, ▲ pickup / ▼ drop-off, done pins dimmed, the car, a line through the stops still to do, fitted on load; a pin opens its stop; "Navigate to stop N" opens the phone's maps app. Stops Dispatch couldn't locate are listed under the map ("N stops can't be shown on the map"). The header map/list button is remembered on the phone (`fvst-task-view`); the Tasks list opens only when "list" was chosen | `route?date=`, `me` |
| 2.5 | **Stop detail** and order detail (packages, notes, contacts, window, banner from Dispatch) | `stops/{key}`, `orders/{ref}` |
| 2.6 | **Pickup**: Picking Up → Picked Up (checklist, left-behind packages with reason, note); Undo | `picking-up`, `picked-up`, `undo` |
| 2.7 | **Delivery**: In Transit → Delivered with proof photos (in-app camera that burns the time and stop address into the photo; where the browser has no camera access, the phone's camera through a file input, stamped the same way), left at, contact method, recipient name | `in-transit`, `photos`, `photos/{id}` DELETE, `delivered` |
| 2.8 | **Failed** pickup/delivery with reason, note, next step | `failure-reasons`, `failed` |
| 2.9 | **Report a problem** = the failed form (as on Android; the `exceptions` call is allowed through but, like the Android app, has no screen) | `failed` |
| 2.10 | **Messages**: inbox, mark read, star | `messages`, `messages/{id}/read`, `messages/{id}/star` |
| 2.11 | **Hours** (Profile): today's worked time, start, breaks; Start / End break. The day itself starts and ends with the first Picked Up / last Delivered (admin API) | `work`, `work/today` |
| 2.12 | **Online + GPS**: while Online and the app is open, a fix every 15 s / 25 m goes to `presence`, and at least one ping a minute even when standing still or with the app off screen, plus one as each page opens (admin counts 2 minutes without a ping as offline); switched Offline by the driver, it sends `online: false` at once and no pings after; the toggle keeps the driver's last choice across pages and reloads; status taps carry the latest fix. GitHub #47: while Online with a last fix over 2 min old a "GPS not updating" warning shows under the top bar (red "No GPS" past 5 min, or "Location is off" when blocked); tap it for Connectivity | `presence` |
| 2.13 | **Offline**: taps and photos made without signal are saved in the browser and sent when back, once each; pings without signal go to the backlog | `Idempotency-Key`, `client_time`, `presence/history` |
| 2.14 | **Analytics**: this week's stops, on-time %, hours | `analytics/summary` |
| 2.15 | **Profile**: driver details, log out | `me` |
| 2.16 | **Call Dispatch**: `tel:` link to the dispatch phone | `me` (`dispatch_phone`) |
| 2.17 | **Connectivity**: server reachable, last GPS fix (GitHub #47: green "Yes" only within 2 min; "Not updating" in amber up to 5 min, then red "No GPS", with the fix's age and what to do; "Off" when location is blocked for the app), pending offline items; **Report app issue** | `ping`, `app-issues` |
| 2.18 | **Install as app**: web manifest, icons, service worker (scope `/driver/`) | — |

Error wording matches the Android app: no connection → "No connection to Dispatch. Check your signal and try
again."; 401 → "Your session expired. Please log in again."; 404 → "Not found — it may have been
reassigned."; 409 → "This stop changed. Pull down to refresh."; otherwise the API's `error` text.

## 3. Offline behaviour

- **Taps** (status changes, hours, deliveries, failures) are sent from the page with an `Idempotency-Key`
  made when the driver tapped and `client_time`. With no connection they go to an IndexedDB queue and are
  replayed in order (on reconnect, on page load, and by Background Sync where the browser has it). The admin
  API applies a replayed key once. A 4xx on replay drops that tap (as on Android) and the page reloads.
- **Photos** taken offline are kept as blobs with a `client_photo_id`; Delivered refers to them as
  `client:<uuid>` and the server matches them when the upload arrives.
- **Pages**: the service worker serves the last copy of a page when offline (network first), so the task
  list and stop details stay readable.
- **GPS pings** that fail are kept (up to 5000) with the fix time and sent to `POST /presence/history` in
  batches of 200.

## 4. Known limits versus the Android app

- **No background GPS.** Browsers stop location updates when the screen is off or the app is in the
  background, so the driven-route history has gaps there. The app offers to keep the screen awake while
  Online (Screen Wake Lock API). Drivers who need full tracking use the Android app.
- No local-network permission or foreground notification (not needed in a browser).
