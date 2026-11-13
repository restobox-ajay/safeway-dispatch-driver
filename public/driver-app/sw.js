/* FVST Express Driver — service worker (FEATURE-194/203, scope /driver/).
   Pages: network first, the last copy when there's no signal (task list and stop details stay readable).
   App files: cache first. API calls (/driver/api/), the login page and logout are never cached.
   Taps made offline are queued by the page itself (IndexedDB, app.js), not here. */
"use strict";

var VERSION = "fvst-driver-fc29f95523a9"; // hash of the STATIC files — ServiceWorkerVersionTest gives the new value
var STATIC = [
  "/driver-app/css/style.css",
  "/driver-app/css/app.css",
  "/driver-app/css/route-map.css",
  "/driver-app/js/app.js",
  "/driver-app/js/stop.js",
  "/driver-app/js/failed.js",
  "/driver-app/js/route-map.js", // GitHub #48
  "/driver-app/img/dm-car.svg",
  "/driver-app/img/icon.svg",
];

self.addEventListener("install", function (event) {
  // cache: "reload" skips the browser's HTTP cache (app files are served with a week's max-age).
  event.waitUntil(caches.open(VERSION).then(function (c) { return c.addAll(STATIC.map(function (u) { return new Request(u, { cache: "reload" }); })); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener("activate", function (event) {
  event.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k !== VERSION; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

// The login page asks for this after logout: no driver's pages stay on a shared phone.
self.addEventListener("message", function (event) {
  if (event.data === "clear-pages") {
    // waitUntil: the worker may otherwise be stopped before the pages are gone.
    event.waitUntil(caches.open(VERSION).then(function (c) {
      return c.keys().then(function (reqs) {
        return Promise.all(reqs.filter(function (r) { return STATIC.indexOf(new URL(r.url).pathname) === -1; }).map(function (r) { return c.delete(r); }));
      });
    }));
  }
});

self.addEventListener("fetch", function (event) {
  var req = event.request;
  if (req.method !== "GET") return;
  var url = new URL(req.url);
  if (url.origin !== self.location.origin || !url.pathname.startsWith("/driver")) return;
  if (url.pathname.startsWith("/driver/api/") || url.pathname === "/driver/login" || url.pathname === "/driver/logout") return;

  if (STATIC.indexOf(url.pathname) !== -1) {
    event.respondWith(caches.match(req).then(function (hit) { return hit || fetch(req); }));
    return;
  }

  if (req.mode === "navigate") {
    event.respondWith(fetch(req).then(function (res) {
      // Keep a copy of real pages only (not a redirect to the login page, not an error screen).
      if (res.ok && !res.redirected && res.type === "basic") {
        var copy = res.clone();
        caches.open(VERSION).then(function (c) { c.put(req, copy); });
      }
      return res;
    }, function () {
      return caches.match(req).then(function (hit) {
        return hit || new Response(
          '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">' +
          '<link rel="stylesheet" href="/driver-app/css/style.css"><link rel="stylesheet" href="/driver-app/css/app.css">' +
          '<div class="phone-frame-wrap"><div class="phone-frame"><div class="app-screen"><div class="app-content" style="text-align:center;padding-top:48px;">' +
          "<p>No connection to Dispatch. Check your signal and try again.</p>" +
          '<a class="btn btn-primary" href="">Try again</a> <a class="btn btn-secondary" href="/driver/tasks">Task list</a>' +
          "</div></div></div></div>",
          { status: 503, headers: { "Content-Type": "text/html; charset=utf-8" } }
        );
      });
    }));
  }
});
