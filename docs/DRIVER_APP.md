# Safeway Tire Driver — web app (PWA)

The driver side of the Safeway delivery system ("Driver Web App - Do Mobile Only For now" in safeway-dispatch's
`docs/safeway/design-spec-SWT100.md`). Built from fvstexpress-driver's structure; the screens follow Safeway's Figma
driver frames (safeway-dispatch `docs/safeway/wireframes/`: List Deliveries, Destination Details, Mark Delivery
Complete, Completed Deliveries, Add Notes).

## 1. Architecture

```
phone browser ──► this app (Symfony, no database) ──► safeway-dispatch  /driver-api/v1
   pages, service worker,     driver firewall; the session         (all data: drivers, routes, stops,
   IndexedDB offline queue    file keeps the driver's API tokens    PINs, tokens, photos, messages…)
```

- **All data lives in safeway-dispatch.** This app has no database. Each page and tap calls the Driver API
  (`docs/driver-api.md`, a copy of safeway-dispatch's; that repo's file is the contract).
- **Login**: phone number or employee number + PIN, checked by `POST /auth/login` (lockouts, rate limit and the one
  answer for every bad login are the API's). The tokens are kept server-side in the driver's PHP session (session
  files in `var/sessions/`), never in the browser; the access token is refreshed once on `401 TOKEN_INVALID`.
  PHP's file session lock runs one driver's requests one after the other, so the rotating refresh token is never
  used twice at once.
- **/driver/api/<path>** passes only the calls the app makes (`DriverApiProxyController::ALLOWED`); writes need the
  page's CSRF token and carry the tap's own `Idempotency-Key`.
- **Configuration** (`.env.local`): `APP_SECRET`, `APP_DOMAIN` (this app's host), `DISPATCH_URL` (the Dispatch address;
  `.env` defaults to the dev Dispatch; the app adds `/driver-api/v1/`), `DRIVER_API_HOST_HEADER` (only for an address that is an IP), `TRUSTED_PROXIES`.

## 2. Pages (spec + Figma)

| Page | Figma frame | API |
|---|---|---|
| Login `/login` | — | `auth/login` |
| Upcoming Destinations `/driver/tasks` (main page: next deliveries from the immediate next; Company and Address open Google Maps; Type, Products, ETA; Details / Add Note / Complete; route Start / Finish) | List Deliveries | `routes/today`, `routes/{ref}/start|finish` |
| Destination Details `/driver/stops/{id}` (company, stop #, address → Maps, phone, contact, ETA, special instructions, note from dispatch, products; On My Way / Arrived / Undo; Mark Complete / Set Status) | Destination Details | `stops/{id}`, `on-my-way`, `arrived`, `undo` |
| Add Note `/driver/stops/{id}/note` | Add Notes | `stops/{id}/notes` |
| Mark Complete `/driver/stops/{id}/complete` (status per task, reason, note, photo; back to the main page) | Mark Delivery Complete | `photos`, `stops/{id}/complete` |
| Completed Destinations `/driver/completed` | Completed Deliveries | `routes/today` |
| Work Hours `/driver/hours` | — | `hours` |
| Messages (top bar / menu): schedule changes and cancellations, tap to confirm read | — | `messages`, `messages/{id}/read` |
| Connectivity & Report a Problem (menu) | — | `me`, `problems` |

## 3. Offline

- Taps (and problem reports, hours, notes, message reads) get an `Idempotency-Key` and `client_time` when tapped.
  Without a connection they go to an IndexedDB queue and are replayed in order (on reconnect, on page load, every
  30 s). A refusal on replay is kept aside and shown to the driver (and reported to Dispatch), never dropped.
- Photos: two copies under one `client_photo_id` — at most 100 KB first, then the good copy as an upgrade
  (Driver API 5.1). `complete` references the client ids; the server matches them whenever the uploads arrive.
- GPS: while the app is open, a position on a 15 s / 25 m move and at least once a minute (`POST /positions`);
  positions that could not be sent go up later in batches of up to 500.
- The service worker serves the last copy of a page when offline (network first).

## 4. Known limits

- No background GPS: browsers stop location when the screen is off. The app keeps the screen awake while open.
- Not built (spec + Figma scope, 2026-11-13): the Figma card's red "Exclamation Mark" button (its action is not
  specified), analytics, the route map.
