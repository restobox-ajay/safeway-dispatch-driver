# Claude Code Project Rules

## Mission

The Safeway Tire driver web app (PWA): `docs/DRIVER_APP.md`. It follows safeway-dispatch's spec
(`../safeway-dispatch/docs/safeway/design-spec-SWT100.md`, "Driver Web App"), its Figma driver frames
(`../safeway-dispatch/docs/safeway/wireframes/`) and its Driver API (`docs/driver-api.md`, copied from safeway-dispatch,
whose copy is the contract).

## Rules

- **No database.** All data comes from safeway-dispatch's Driver API through `App\Driver\Api\DriverApiClient`. The
  driver's API tokens live only in the PHP session (`DriverTokenStore`), never in the browser.
- Every call the browser may make is listed in `DriverApiProxyController::ALLOWED`; add a call there and nowhere else.
- When safeway-dispatch's Driver API changes, copy its `docs/driver-api.md` here and follow it.
- No npm / node. Plain JS in `public/driver-app/js/`, plain CSS in `public/driver-app/css/safeway.css`.
- Times sent to the API are RFC 3339 with an offset (`SWD.isoNow()`).
- Work on one feature at a time; keep diffs small; commit in steps.
- Tests: none yet (owner, 2026-11-13: "we do tests later"). Do not skip, delete, weaken or fake tests once they exist.
- Do not touch production `.env`, live servers, or real driver data.
