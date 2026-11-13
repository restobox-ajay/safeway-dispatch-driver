// Driver web app (public/driver-app/js/app.js): the rules that keep a driver's offline taps safe.
// Run by DriverJsTest (PHPUnit) through `node --test tests/js/`.
import { test } from "node:test";
import assert from "node:assert/strict";
import { loadApp } from "./harness.mjs";

const plainOf = (v) => JSON.parse(JSON.stringify(v));

test("a JSON answer is read as it is", () => {
  const { FVST } = loadApp();
  const r = FVST.readResponse(200, false, '{"status":"delivered"}');
  assert.equal(r.ok, true);
  assert.equal(r.signedOut, false);
  assert.deepEqual(plainOf(r.data), { status: "delivered" });
});

test("signed out is never a success: JSON 401, a followed redirect, or HTML instead of JSON", () => {
  const { FVST } = loadApp();
  for (const [status, redirected, text] of [
    [401, false, '{"code":"SESSION_EXPIRED","redirect":"/login"}'],
    [200, true, "<!DOCTYPE html><title>Log in</title>"],
    [200, false, "<!DOCTYPE html><title>Log in</title>"],
  ]) {
    const r = FVST.readResponse(status, redirected, text);
    assert.equal(r.ok, false, text);
    assert.equal(r.signedOut, true, text);
    assert.equal(FVST.queueVerdict(r, 0), "wait", "a saved tap waits for the driver to sign in again");
  }
});

test("an empty 204 is a success", () => {
  const { FVST } = loadApp();
  const r = FVST.readResponse(204, false, "");
  assert.equal(r.ok, true);
  assert.equal(r.signedOut, false);
});

test("saved taps: done, wait, retry a few times, then kept aside — never silently dropped", () => {
  const { FVST } = loadApp();
  const v = FVST.queueVerdict;
  assert.equal(v({ ok: true, status: 200 }, 0), "done");
  assert.equal(v({ ok: false, status: 0, offline: true }, 0), "wait");
  assert.equal(v({ ok: false, status: 503, offline: true }, 0), "wait");
  assert.equal(v({ ok: false, status: 403, data: { code: "CSRF" } }, 0), "wait");
  assert.equal(v({ ok: false, status: 502 }, 0), "retry");
  assert.equal(v({ ok: false, status: 502 }, 4), "refused", "after 5 failures it stops blocking the rest");
  assert.equal(v({ ok: false, status: 409, data: { code: "NOT_NEXT_STOP" } }, 0), "refused");
  assert.equal(v({ ok: false, status: 422 }, 0), "refused");
});

test("saved items belong to the driver who saved them", () => {
  const { FVST } = loadApp({ driverId: 7 });
  assert.equal(FVST.mine({ driver: "7" }), true);
  assert.equal(FVST.mine({ driver: 8 }), false);
  assert.equal(FVST.mine({}), true, "items saved before the rule go with whoever is signed in");
});

test("a GPS fix older than 2 minutes, or another driver's, is not used", () => {
  const now = Date.now();
  assert.deepEqual(plainOf(loadApp({ driverId: 7, lastFix: { latitude: 49.1, longitude: -123.1, at: now - 10000, driver: "7" } }).FVST.gps()), { latitude: 49.1, longitude: -123.1 });
  assert.deepEqual(plainOf(loadApp({ driverId: 7, lastFix: { latitude: 49.1, longitude: -123.1, at: now - 3 * 60000, driver: "7" } }).FVST.gps()), {});
  assert.deepEqual(plainOf(loadApp({ driverId: 7, lastFix: { latitude: 49.1, longitude: -123.1, at: now - 10000, driver: "8" } }).FVST.gps()), {});
});

test("with no signal and nowhere to save, a tap answers instead of hanging", async () => {
  const { FVST } = loadApp({ fetch: () => Promise.reject(new TypeError("Failed to fetch")) }); // no IndexedDB here
  const r = await FVST.write("POST", "stops/D-1/delivered", {});
  assert.equal(r.ok, false);
  assert.equal(r.queued, undefined);
  assert.match(r.error, /couldn't save/);
});

test("a photo upload answered without a photo id is not taken as uploaded", async () => {
  const { FVST } = loadApp({ fetch: () => Promise.resolve(new Response("{}", { status: 200 })) });
  const r = await FVST.uploadPhoto(new Blob(["x"], { type: "image/jpeg" }), { reference: "JOB-1" });
  assert.equal(r.ok, false);
});

test("a fake-GPS flag left on the phone is ignored when the tester is not on this server", () => {
  const app = loadApp({ driverId: 7, storage: { "fvst-fake-gps": "1" } });
  app.gpsFix(49.2, -123.0);
  assert.deepEqual(app.plain(app.FVST.gps()), { latitude: 49.2, longitude: -123.0 }, "real GPS is used");
});
