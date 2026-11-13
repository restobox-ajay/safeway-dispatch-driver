# Safeway Tire Driver — web app

The browser (PWA) driver app of the Safeway delivery system. Drivers open it on their phone, log in with their phone
number or employee number and PIN, and can add it to the home screen. Every page and tap goes to
**safeway-dispatch**'s Driver API; this app has **no database** and stores no routes, stops or drivers.
Full description: [docs/DRIVER_APP.md](docs/DRIVER_APP.md); the API contract: [docs/driver-api.md](docs/driver-api.md).

## Setup

```bash
composer install
```

`.env.local` (gitignored):

```dotenv
APP_SECRET=<64 random hex characters>
# This app's own host name
APP_DOMAIN=safeway-dispatch-driver.local
# safeway-dispatch's Driver API — must end with /driver-api/v1/
DRIVER_API_BASE_URL=http://safeway-dispatch.local/driver-api/v1/
# Only when the base URL is an IP address: the safeway-dispatch vhost name to send as the Host header
DRIVER_API_HOST_HEADER=
# Proxies in front of this app, if any
TRUSTED_PROXIES=
```

Open `/login`. The web server needs `public/` as the document root (`public/.htaccess` for Apache / LiteSpeed) and
write access to `var/` (cache and session files).

**On safeway-dispatch:** list this app's server in its `TRUSTED_PROXIES`. This app forwards each driver's IP in
`X-Forwarded-For`; without it Dispatch sees every driver as this one server, so the login rate limit (20 per 15 min
per IP) is shared by the whole fleet.

**HTTPS is required** in production: phones allow the camera, GPS, the service worker and "Add to home screen" only
on HTTPS pages (and on `localhost`).

## Local (WAMP)

Vhost `safeway-dispatch-driver.local` → `public/` in `httpd-vhosts.conf`, plus the hosts entry
`127.0.0.1 safeway-dispatch-driver.local`.

## Dev server (push to deploy)

`driver.safeway-dispatch.dev.rp021.webhelplogin.com` → `~/driver` on `sfyappdev@host021.webhelplogin.com:2200` (SSH alias
`safeway-dev`, key `~/.ssh/safeway_dispatch_dev`). Same pattern as rbx-ghl-reporting: a plain git repo in the app folder with
`receive.denyCurrentBranch = updateInstead`, no hook. `git push origin main` goes to GitHub **and** dev (dev is a second push URL
of `origin`); `git push dev-sfyappdev main` goes to dev only. After a push that changes dependencies or config:

```bash
ssh safeway-dev 'cd ~/driver && ~/bin/composer install --no-dev -o && php bin/console cache:clear'
```

The server's `.env.local` (gitignored) points `DRIVER_API_BASE_URL` at the dev Dispatch and has `DRIVER_GPS_TESTER=1`.
