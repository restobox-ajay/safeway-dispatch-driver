# Driver API v1

The contract between the delivery app and the driver app (PWA now, native app in stage 2). Decisions: `docs/safeway/brief.md` §3, D-026. The
driver app talks to nothing else. Everything here is JSON over HTTPS under `/driver-api/v1`, except the photo upload (multipart).

- Times in answers are RFC 3339 with the offset of `app.timezone` (`2026-11-05T10:41:55-08:00`). Times in requests (`client_time`,
  `recorded_at`, `taken_at`) are RFC 3339 **with an offset** (`Z` is fine) or whole epoch seconds.
- Ids are opaque strings: stops `stp_...`, photos `pho_...`, routes `R-YYYYMMDD-NN`. A driver and a message have an integer `id`.
- Code in `src/Driver/` and `src/Controller/DriverApi/`; the writes go through `StopService` / `RouteService` gates with cause `driver`.

## 1. Auth

| Call | Body | Answer |
|---|---|---|
| `POST /auth/login` | `{"phone": "+16045550100", "pin": "2580"}` or `{"employee_number": "E-42", "pin": "2580"}` | `200` tokens |
| `POST /auth/refresh` | `{"refresh_token": "drt_..."}` | `200` new tokens |
| `POST /auth/logout` | none, `Authorization: Bearer <access token>` | `204` |

Tokens answer (`Cache-Control: no-store`):

```json
{ "token_type": "Bearer", "access_token": "dat_...", "access_expires_at": "2026-11-05T02:15:00-07:00", "expires_in": 900,
  "refresh_token": "drt_...", "refresh_expires_at": "2026-12-05T02:00:00-07:00", "driver": { "id": 7, "name": "Raj Singh" } }
```

Every other call sends `Authorization: Bearer <access token>`.

- **Access token 15 minutes, refresh token 30 days.** An access token that is missing, unknown, expired (it is dead at exactly 15:00), revoked or
  otherwise invalid is `401 TOKEN_INVALID`. The app refreshes and repeats the call.
- **Refresh rotates.** The refresh token you send is used up: it and the access token of its pair stop working, and you get a new pair.
  **A lost answer is safe to retry for 60 seconds:** presenting the token that was rotated within the last 60 s (and whose new refresh token
  has not been used yet) gets the **same** new pair again, with the same expiry times, and issues nothing. After that, a rotated token that
  comes back (the just-rotated one at 61 s, or any older one) is taken for a stolen copy: the whole login (every token of that phone) is revoked
  and the app must log in again. Logout, deactivation and a new PIN end the grace too. (The new pair is derived from the presented token with a
  server secret, so only hashes are stored and the repeat is byte-identical.)
- **Logout** ends that phone's tokens only. Another phone of the same driver stays signed in.
- **A new PIN (staff set it) signs the driver out of every phone** (tokens issued before `pin_changed_at` die). So does deactivating the driver.
- **PIN rules** (checked when staff set it): 4 to 8 digits, not one digit repeated, not a run like 1234 / 4321 / 7890.
- **Lockout.** Five wrong PINs lock the login for 15 minutes; each further lockout doubles it (30 min, 1 h, 2 h ... ) up to 24 hours. A right
  PIN clears it. Wrong PINs while locked do not extend the lock.
- **One answer for every bad login.** A wrong PIN, a locked login, an unknown phone or employee number and an inactive driver all give the same
  `401 INVALID_CREDENTIALS` with the same body, and each does the same password check so the timing says nothing either.
  (A request with no PIN or no identifier is `422 VALIDATION_FAILED`: it names no driver.)
- **20 login attempts per IP per 15 minutes**, successful or not, then `429 RATE_LIMITED` with `Retry-After` (seconds), even for a right PIN.

## 2. Offline-safe writes

- Every write (everything except login and refresh) accepts `Idempotency-Key: <1 to 255 printable characters, no spaces>`. Send one per tap and
  reuse it when you retry the same tap.
- The first `2xx` answer is stored for **7 days** per (driver, key). A repeat gets that same answer, byte for byte, with `Idempotent-Replay: true`.
- The same key on a **different call** (another path or a different body) is `422 IDEMPOTENCY_KEY_REUSED`. The same key while the first request is
  still running is `409 IDEMPOTENCY_IN_PROGRESS` (`Retry-After: 1`). An answer that was not `2xx` is not stored, so a retry runs again.
- **`client_time`** on status taps (`on-my-way`, `arrived`, `complete`, message `read`, `hours`, problem reports): when the driver actually tapped,
  for taps sent late. It is stored (epoch seconds) on the stop's history. A time **more than 5 minutes in the future is replaced with the server
  time** (the event is marked `client_time_adjusted`); exactly +5:00 is kept. A `client_time` that is not a time is `422`.
  The 10 minute undo window is measured on the server's clock, from when the server received the step.

## 3. Reading

| Call | Answer |
|---|---|
| `GET /me` | `{driver, today, time_zone, routes: [route without stops], unread_messages}` |
| `GET /routes/today` | `{date, time_zone, routes: [route with stops], unread_messages}` - the driver's routes dated today in `app.timezone` |
| `GET /stops/{id}` | `{stop, unread_messages}` |
| `GET /messages` | see section 7 |

Only the driver's **own** routes and stops exist for them. Another driver's stop, a cancelled stop that left the route, and a stop that does not
exist all give the same `404 NOT_FOUND` body.

A **route**: `ref, name, date, planned_start, status (planned|out|completed), warehouse{name,address,latitude,longitude}, truck{name,plate}|null,
stop_count, next_allowed, start_blocked, stops[]`. `next_allowed` is `["start"]` on a planned route that can leave, `["finish"]` on an out route whose customer
stops are all done, else `[]`. `start_blocked` (D-097) is null, or, on a planned route that cannot leave yet, the reason as text (for example "The
departure checklist is not done: the warehouse must mark the load as loaded before the truck leaves."); the app shows it instead of Start.

A **stop**: `id, kind (customer|warehouse), route_ref, position, status, driver_state (null|on_my_way|arrived), next_allowed, can_undo` and, for a
customer stop, `result, customer{name,phone}, ship_to{...}, requested_date, time_window{start,end}|null, priority, latitude, longitude, maps_url`
(Google Maps directions), `eta` ("HH:MM", the stop's planned arrival from the Route Schedule (D-099): departure time, gaps, breaks and dispatch's manual times; null when the route has no departure time or the stop is done), `instructions` (the Source's note for the driver), `notes[]` (dispatch notes meant for the
driver and the driver's own: `{reason_code, text, mine, created_at}`, led by the shop's own "for driver" notes (D-069, `reason_code` null, `mine` false); staff-only notes never appear), `tasks[]`, `photos[]`, `completed_at`,
`recipient_name`. A warehouse stop has `address, latitude, longitude, maps_url`, `result`, `completed_at` and, when dispatch listed a reload (D-039), `reload{summary}` ("12 tires, 4 rims") and `tasks[]` with one `reload` task (items as for a delivery). Its only tap is `complete`, with `outcomes {"reload": {"outcome": "done" | "not_done", reason_code?, note?}}` (`not_done` needs a reason code); no photo or recipient is needed. Without a reload task a warehouse stop has no taps.

- **`next_allowed`** is the taps the app may show, from `on_my_way`, `arrived`, `complete`, `note`. It follows the rules below: nothing but `note`
  before the route starts or after the stop is done; `on_my_way` is left out while the driver is on the way to another stop; `on_my_way` is left
  out once the driver is on the way or has arrived; `arrived` is left out once arrived. A tap is allowed from any earlier state (a driver can
  tap `arrived` or `complete` without `on-my-way`).
- **`can_undo`** is true while the last driver step can be taken back (section 4).
- A task: `{task_ref, type, instructions, items[] | amount/currency/methods, outcome?}`.
- A photo: `{photo_id, client_photo_id, pending, taken_at}`; `pending` is true while only the client id is known (section 5).

## 4. Taps

All are `POST`, accept `Idempotency-Key`, and answer `200` with `{"stop": ...}` (or `{"route": ...}` with stops) as read after the tap.

| Call | Body | Rules |
|---|---|---|
| `/routes/{ref}/start` | none | The driver's own planned route, with a driver and a truck. The stops go `out_for_delivery` (event `stop.out_for_delivery`, cause `driver`). |
| `/stops/{id}/on-my-way` | `{client_time?}` | Route out, stop open. **Only one stop at a time per driver** may be on my way: another one is `409 CONFLICT`, and the body names it: `on_my_way_stop_id` (the stop id; also `errors[]` field `on_my_way_stop`) so the app can offer to `undo` it. Already on the way or arrived: `409`. |
| `/stops/{id}/arrived` | `{client_time?}` | Route out, stop open, not already arrived. Arriving at (or completing) a stop **clears the driver's on-my-way on every other stop** (internal event `stop.step_undone`, reason `driver_went_elsewhere`), so a driver who went to B instead of A is not stuck. |
| `/stops/{id}/complete` | see below | Route out, stop open. |
| `/stops/{id}/undo` | none | The last step only (arrived goes back to on my way or nothing, on my way goes back to nothing), **within 10 minutes**, **never after the stop is completed** (the driver cannot reopen; the office can). |
| `/routes/{ref}/finish` | none | Every customer stop completed or cancelled, else `409`. |
| `/stops/{id}/notes` | see section 8 | Any status. |

`complete` body:

```json
{ "outcomes": { "deliver": {"outcome": "partially_delivered", "reason_code": "customer_refused", "items": {"L1": "2"}},
                "pay": {"outcome": "collected", "collected": {"amount": "100.00", "method": "cheque", "cheque_number": "0042"}} },
  "photos": ["pho_9xK2aB3cD4eF5gH6", "ph-queued-0007"],
  "recipient_name": "D. Kim",
  "location": {"latitude": 49.2661, "longitude": -123.0012},
  "client_time": "2026-11-05T10:41:55-08:00" }
```

- `outcomes`: one per task, keyed by `task_ref`, with exactly the office's rules (`OutcomeCheck`, sync API section 6.3): the outcomes of the task type,
  `reason_code` for every outcome but the good ones (`other` needs a `note`), quantities, payment amount and method, cheque number. Errors are
  `outcomes.<task_ref>.<field>`.
- `photos`: **1 to 10**, each a server id (`pho_...`, uploaded by this driver) or the app's client id of a photo still in its queue. 0 photos is
  accepted only when the stop **failed** (every task not delivered / not collected). More than 10, repeats and unknown ids are `422 photos...`.
- `recipient_name` up to 120 characters; `location` both coordinates or neither.
- The stop's `completed_at` is the (clamped) `client_time`.

Events written (`stop_event`, cause `driver`): `stop.on_my_way`, `stop.arrived`, `stop.step_undone` are **internal** and are never sent to a
Source's webhook (they are not in the sync API's event list). `stop.out_for_delivery` and `stop.completed` are sent as for the office.

## 5. Photos

`POST /photos`, `multipart/form-data`: `file` (required), `client_photo_id` (optional, the app's id, 1 to 64 of `A-Za-z0-9._:-`, never starting
`pho_`), `taken_at` (optional). Answer `201` (or `200` with the stored one when that client id was already uploaded by this driver):

```json
{ "photo_id": "pho_9xK2aB3cD4eF5gH6", "client_photo_id": "ph-queued-0007", "content_type": "image/jpeg", "size": 183204, "taken_at": "...",
  "tier": "good", "width": 1280, "height": 960, "replaced": false }
```

- **JPEG or PNG only, at most 30 MB (31,457,280 bytes, D-042).** What the file is comes from its bytes (signature, structure, a real image header),
  never from its name or the type the phone claims: a PHP file named `photo.jpg` is `422` on the field `file`. The PHP host must allow it:
  `upload_max_filesize >= 30M` and `post_max_size > upload_max_filesize` (`public/.user.ini` sets 30M / 32M; cPanel PHP reads `.user.ini` per directory, and `tests/Unit/Driver/UserIniTest.php` keeps it in step with the cap). The driver app itself sends far smaller files (section 5.1). A file PHP refuses first for size is the same `422` too large.
- Stored under `var/uploads/photos/<yyyy>/<mm>/<photo_id>.<jpg|png>`, **not public**. `GET /photos/{id}` returns the image to the driver who
  uploaded it (others: `404`). The owning Source downloads it with `GET /integration/v1/stops/{source_ref}/photos/{photo_id}`; it sees it in the
  stop's `outcome.photos`. Photos are kept at least 400 days and no job prunes them.
- **A photo may be referenced before it is uploaded.** `complete` with `photos: ["ph-queued-0007"]`, then upload with the same
  `client_photo_id` when the connection is back: it attaches by itself. A stop shows `pending: true` until then. **If it has not arrived 6 hours
  after the stop was completed, an alert for dispatch is raised** (once).
- With an `Idempotency-Key` the upload is replayed from the key; the same key with another file is `422`.

### 5.1 Tiers and upgrades

The phone sends **two copies** of every photo under one `client_photo_id`, the small one first so that something arrives on a bad connection:

| `tier` | What it is | Made by the app |
| --- | --- | --- |
| `emergency` | at most 100 KB | 800 px long edge, JPEG quality stepped from 0.7 down to 0.3 until it fits; if still too big, 640 px and again. Upright (EXIF applied), never enlarged. |
| `good` | the picture dispatch looks at | 1280 px long edge at quality 0.72; 1024 px at 0.6 when the browser reports `slow-2g`/`2g`/`3g` or data saver (D-042). |

- `tier` is an optional form field (`emergency` or `good`; anything else is `422` on `tier`; absent means `good`). The server records `tier`, `width` and `height`
  (photos stored before tiers have `null` for the three).
- **Upload again with the same `client_photo_id`** (same driver) and the stored image is **replaced only if the new one has more pixels**
  (width x height). An equal or smaller copy changes nothing (never a downgrade). The answer is `200` with the photo as stored and `replaced: true|false`.
  The photo **keeps its `photo_id`**, so `outcome.photos` of the sync API, the admin views and the tracking page show the better image with no further work.
  `GET /photos/{id}` returns whichever is stored now. The old file is deleted.
- Each upload is idempotent per `Idempotency-Key` as before; the app uses a different key for the upgrade than for the emergency copy.

## 6. GPS

`POST /positions`:

```json
{ "positions": [ {"lat": 49.2661, "lng": -123.0012, "accuracy": 8.5, "recorded_at": "2026-11-05T10:41:55-08:00"} ] }
```

- 1 to 500 positions per batch. `lat` -90..90, `lng` -180..180, `accuracy` metres (optional), `recorded_at` required (the phone's time; a time more
  than 5 minutes ahead becomes the server time). The batch is **all or nothing**: one bad position is `422` (`positions.N.lat` ...) and nothing is
  stored. Answer `201 {"stored": n, "skipped": m}`; a position the server already has for that driver and second is skipped, so a retry is harmless.
- While online the app sends a position at least once a minute, even standing still. **Online = a position recorded within 2 minutes.** Positions
  recorded offline are uploaded later in one batch, in any order; they are stored at their own `recorded_at`.
- **Stationary alert** (a scheduled job, every minute): the driver is online, has stops left on a route that is out, and every position since the
  last one recorded at least 5 minutes ago is within 25 metres of it: an alert for dispatch (one open alert per driver). A driver who tapped
  `arrived` less than 30 minutes ago is at that customer and is not alerted; standing there longer than that does raise it.
- For dispatch's map: `App\Driver\DriverPositionReader::latestForDrivers()` (the newest position per driver and whether it is online).

## 7. Messages

- `GET /messages` -> `{unread, messages: [{id, text, from (dispatch|system), created_at, read_at}]}`, newest first, up to 100.
- `POST /messages/{id}/read` `{client_time?}` -> `200 {message}`. Reading again keeps the first time. Dispatch sees `read_at` as the confirmation.
  Another driver's message is `404`.
- Dispatch sends a message with `DriverMessageService::send()`; the driver page to send one comes later.
- **Schedule changes and cancellations make a message by themselves**, one per change, from the stop events flagged `driver_notice` (a change on a
  route that is out): "Stop 4 (Kim's Auto) was moved to position 2.", "Stop 3 (...) was cancelled.", "... was taken off your route.", "... was added
  to your route as stop 3.", "... was changed by dispatch: address.", "... was reopened by dispatch." The scheduled job `driver_api_minute` makes them
  within a minute; a change is announced once however often the job runs (a message is unique per event and driver). Each new message is handed to
  `SmsNotifier`; the SMS (Plivo / GoHighLevel, a later sprint) only points to the message. `App\Sms\SmsGateway` is bound (docs/sms.md).

## 8. Notes, problems, hours

- `POST /stops/{id}/notes` `{"reason_code": "customer_refused", "text": "Took 2 of 4 tires"}` -> `201 {note}`. `reason_code` is optional (omit it or send null for a
  plain note, then `text` is required) and is from the sync API's reason list (`customer_closed, customer_refused, no_one_available, address_not_found, no_access, damaged, wrong_item,
  insufficient_funds, out_of_time, vehicle_issue, other`); `text` is required for `other`, optional otherwise (then it is the reason's words), at
  most 1000 characters. A StopNote with audience `driver`, authored by the driver. It changes no status and writes no event. Notes the driver
  wrote appear in the Source's `outcome.driver_notes`.
- `POST /problems` `{"kind": "connectivity|camera|other", "text": "...", "device": {"model": "...", "os": "...", "app_version": "..."}, "client_time?"}` ->
  `201`. `device` is an object of up to 20 short text / number / boolean values. Stored for dispatch.
- `POST /hours` `{"event": "start|break_start|break_end|end", "client_time?"}` -> `201`. The order: `start`, then any number of `break_start` /
  `break_end` pairs, then `end`; a new `start` after an `end`. A refused order is `409` on field `event`; an event earlier than the last one stored
  is `409` on `client_time`.

## 8a. Field diagnostics

`POST /diagnostics` `{"events": [ ... ]}` -> `201 {"stored": n}`. The app's field diagnostics feed (D-045): what really happens on the phones, for the
admin "Driver app field report" (`/admin/dispatch/driver-report`). It is an ordinary idempotent write (`Idempotency-Key`), and a native app sends the same.

- 1 to 200 events, a body of at most 256 KB (`413 PAYLOAD_TOO_LARGE` over that; more than 200 events is `422`). The batch is **all or nothing**: one
  bad event, unknown field or out-of-range value is `422` (`events.N.data.seconds` ...) and nothing is stored. The sender drops a batch the server
  will never take (400, 413, 422) and keeps one it could not deliver (network, 5xx).
- Every event: `event` (a name below), `client_time` (RFC 3339 or epoch seconds, the phone's time; more than 5 minutes ahead becomes the server's),
  `app_version` (text, 1..32), `session_id` (1..64 of `A-Za-z0-9_-`, one per app launch), `data` (an object with exactly the fields of the event;
  omit it for an event without fields). No other key is accepted. Control characters are refused in every text.
- The names are a closed list so the schema stays known: **an unknown `event` is `422`.**

| `event` | `data` (all required unless marked `?`) |
|---|---|
| `app_start` | `device` (user agent, 300), `platform` (60), `screen` ("390x844", 20), `standalone` (bool), `persisted?` (bool: storage persisted) |
| `offline_period` | `seconds` (0..2678400), `queued_count` (0..100000: rows waiting when the connection came back) |
| `outbox_retry` | `path` (the label of the queued call, 60), `attempt` (>= 1), and one of `http_status` (100..599) or `network` (true) |
| `outbox_conflict` | `code` (60), `choice` (`shown`, `undo_other`, `retry`, `discard`) |
| `upload` | `kind` (`photo`), `tier?` (20), `photo_id?` (64), `bytes`, `ms`, `attempts` (>= 1), `result` (`ok`, `network`, `rejected`, `server_error`) |
| `gps_gap` | `seconds`: more than 120 s without a fix while a route was out |
| `gps_denied` | none |
| `storage_persist_refused` | none |
| `queue_restored_from_backup` | `count` |
| `js_error` | `message` (200), `source?` (200), `line?`, `stack?` (500) |

- Kept 180 days by `app:prune` (`driver_diagnostics`). Owner: `DriverDiagnosticsService`; the field report reads through `DriverFieldReport`.

## 9. Errors

`application/problem+json`: `{type, title, status, code, detail, errors?: [{field, message}]}`.

| Status | `code` | When |
|---|---|---|
| 400 | `MALFORMED_JSON` | The body is not a JSON object. |
| 401 | `TOKEN_INVALID` | Missing / expired / revoked / invalid access or refresh token. Refresh, then log in again. |
| 401 | `INVALID_CREDENTIALS` | Login failed (any reason, one answer). |
| 403 | `FORBIDDEN` | Not allowed. |
| 404 | `NOT_FOUND` | Not found, or not the driver's. Unknown endpoint. |
| 405 | `METHOD_NOT_ALLOWED` | |
| 409 | `CONFLICT` | The state does not allow it (already on the way, route not started, undo expired, route has open stops ...). `detail` says which. |
| 409 | `IDEMPOTENCY_IN_PROGRESS` | |
| 413 | `PAYLOAD_TOO_LARGE` | A diagnostics body over 256 KB (photos have their own limit, section 5). |
| 422 | `VALIDATION_FAILED` | Field errors in `errors[]` (`field` is a dotted path). |
| 422 | `IDEMPOTENCY_KEY_REUSED` | The key was used for another call. |
| 429 | `RATE_LIMITED` | 20 login attempts per IP per 15 minutes; `Retry-After`. |
| 500 | `SERVER_ERROR` | |

## 10. Operations

- Jobs: `driver_api_minute` on the one cron (`messenger:consume --all`): driver messages from schedule changes, the stationary alert, the missing
  photo alert.
- Pruning (`app:prune`): `driver_tokens` (a day after expiry), `driver_idempotency_records` (7 days), `driver_diagnostics` (180 days after receipt). Photos, positions, messages, alerts are kept.
- Tables: `driver_token`, `driver_login_state`, `driver_idempotency_record`, `photo`, `stop_photo`, `driver_position`, `driver_message`, `alert`,
  `driver_problem_report`, `driver_work_event`, `driver_diagnostic_event`; `stop.driver_state*` and `stop_note.author_driver_id`.
- Owners (one writer each): `DriverAuthService` (tokens, login state), `DriverIdempotencyService`, `PhotoService`, `DriverPositionService`,
  `DriverMessageService`, `AlertService`, `DriverReportService` (problems, hours), `DriverDiagnosticsService` (field diagnostics); `StopService` (stop sub-state, `StopPhoto`, driver notes,
  taps) and `RouteService` (start / finish).
