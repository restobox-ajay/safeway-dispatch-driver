/* =========================================================================
   FVST Express Driver web app — shared code on every signed-in page.
   (docs/DRIVER_APP.md; mockup interactions from mockup-driver/assets/js/app.js)

   window.FVST:
     call(method, path, body, opts)   one call to /driver/api/<path> (the admin driver API)
     write(method, path, body, opts)  a tap: Idempotency-Key + client_time + GPS, queued when offline
     uploadPhoto(file, fields)        a proof photo, kept on the phone when offline
     gps()                            the latest GPS fix {latitude, longitude} or {}
     toast(text) / openModal(name) / closeModal(el)
   ========================================================================= */
(function () {
  "use strict";

  var FVST = (window.FVST = window.FVST || {});
  var API = (FVST.urls && FVST.urls.base ? FVST.urls.base : "/driver") + "/api/";
  var NO_CONNECTION = "No connection to Dispatch. Check your signal and try again.";
  var SAVED_OFFLINE = "No signal — saved on this phone. It'll send when you're back online.";

  // ---------------------------------------------------------------- helpers
  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    var b = new Uint8Array(16);
    crypto.getRandomValues(b);
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    var h = Array.prototype.map.call(b, function (x) { return ("0" + x.toString(16)).slice(-2); }).join("");
    return h.slice(0, 8) + "-" + h.slice(8, 12) + "-" + h.slice(12, 16) + "-" + h.slice(16, 20) + "-" + h.slice(20);
  }
  FVST.uuid = uuid;

  /** ISO 8601 with the phone's offset and whole seconds (what the admin API parses). */
  function isoNow(date) {
    var d = date || new Date();
    var pad = function (n) { return (n < 10 ? "0" : "") + n; };
    var off = -d.getTimezoneOffset();
    var sign = off >= 0 ? "+" : "-";
    off = Math.abs(off);
    return d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate()) + "T" + pad(d.getHours()) + ":" +
      pad(d.getMinutes()) + ":" + pad(d.getSeconds()) + sign + pad(Math.floor(off / 60)) + ":" + pad(off % 60);
  }
  FVST.isoNow = isoNow;

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  FVST.esc = esc;

  FVST.toast = function (text) {
    var t = document.querySelector("[data-toast]");
    if (!t) {
      t = document.createElement("div");
      t.className = "toast";
      t.setAttribute("data-toast", "");
      t.setAttribute("role", "status");
      (document.querySelector(".phone-frame") || document.body).appendChild(t);
    }
    t.textContent = text;
    t.classList.add("is-shown");
    clearTimeout(t._timer);
    t._timer = setTimeout(function () { t.classList.remove("is-shown"); }, 4000);
  };

  // ---------------------------------------------------------------- modals
  FVST.openModal = function (name) {
    var m = document.querySelector('[data-modal="' + name + '"]');
    if (m) m.hidden = false;
    return m;
  };
  FVST.closeModal = function (el) {
    var m = el && el.closest ? el.closest(".confirm-overlay") : null;
    if (m) m.hidden = true;
  };
  document.addEventListener("click", function (e) {
    var close = e.target.closest("[data-close]");
    if (close) { FVST.closeModal(close); return; }
    if (e.target.classList && e.target.classList.contains("confirm-overlay")) { e.target.hidden = true; return; }
    var open = e.target.closest("[data-open]");
    if (open) { FVST.openModal(open.getAttribute("data-open")); }
  });

  // ---------------------------------------------------------------- API calls
  /**
   * What a response to a /driver/api call means. Signed out = the JSON 401, or anything that is not the JSON the
   * proxy always answers with (a redirect followed to the login page, an HTML error page): never a success.
   */
  function readResponse(status, redirected, text) {
    var data = null;
    try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
    var notJson = text !== "" && data === null;
    var signedOut = (status === 401 && !!data && data.code === "SESSION_EXPIRED") || redirected || (status < 300 && notJson);
    return {
      ok: status >= 200 && status < 300 && !signedOut,
      status: signedOut ? 401 : status,
      data: signedOut && !data ? { error: "Your session expired. Please log in again." } : data,
      offline: status === 503 && !!(data && data.offline),
      signedOut: signedOut,
    };
  }
  FVST.readResponse = readResponse;

  /**
   * One call to the admin driver API through /driver/api. Resolves {ok, status, data, offline, signedOut}; never
   * rejects. A signed-out session goes back to the login page. No answer within opts.timeout (15 s) counts as no
   * signal, so a tap is queued rather than left hanging (its Idempotency-Key makes a late duplicate harmless).
   */
  FVST.call = function (method, path, body, opts) {
    opts = opts || {};
    var headers = { Accept: "application/json" };
    var init = { method: method, headers: headers, credentials: "same-origin" };
    var abort = window.AbortController ? new AbortController() : null;
    if (abort) init.signal = abort.signal;
    if (method !== "GET") {
      headers["X-CSRF-Token"] = FVST.csrf;
      headers["Idempotency-Key"] = opts.key || uuid();
    }
    if (body instanceof FormData) {
      init.body = body;
    } else if (body !== undefined && body !== null && method !== "GET") {
      headers["Content-Type"] = "application/json";
      init.body = JSON.stringify(body);
    }
    var url = API + path;
    if (opts.query) url += "?" + new URLSearchParams(opts.query).toString();

    var timer = abort ? setTimeout(function () { abort.abort(); }, opts.timeout || 15000) : null;
    return fetch(url, init).then(function (res) {
      return res.text().then(function (text) {
        clearTimeout(timer);
        var r = readResponse(res.status, res.redirected, text);
        if (r.signedOut) window.location.href = (r.data && r.data.redirect) || FVST.urls.login;
        return r;
      });
    }).catch(function () {
      clearTimeout(timer);
      return { ok: false, status: 0, data: { error: NO_CONNECTION }, offline: true, signedOut: false };
    });
  };

  FVST.errorText = function (r) {
    return (r && r.data && (r.data.error || (r.data.errors && Object.values(r.data.errors)[0]))) || NO_CONNECTION;
  };

  // ---------------------------------------------------------------- offline store (IndexedDB)
  // Every saved tap, photo and ping carries the driver it belongs to. On a shared phone one driver's saved
  // updates are never sent with another driver's session: they wait until their own driver signs in again.
  // (Items saved before this rule have no driver and go with whoever is signed in, as they always did.)
  var DRIVER = FVST.driverId != null ? String(FVST.driverId) : "";
  function mine(item) { return item.driver == null || String(item.driver) === DRIVER; }
  FVST.mine = mine;

  var dbPromise = null;
  function db() {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise(function (resolve, reject) {
      if (!window.indexedDB) { reject(new Error("no IndexedDB")); return; }
      var open = indexedDB.open("fvst-driver", 2);
      open.onupgradeneeded = function () {
        var d = open.result;
        if (!d.objectStoreNames.contains("queue")) d.createObjectStore("queue", { keyPath: "seq", autoIncrement: true });
        if (!d.objectStoreNames.contains("pings")) d.createObjectStore("pings", { keyPath: "seq", autoIncrement: true });
        // Saved updates Dispatch refused when they were finally sent: kept to show the driver, not dropped.
        if (!d.objectStoreNames.contains("parked")) d.createObjectStore("parked", { keyPath: "seq", autoIncrement: true });
      };
      open.onsuccess = function () { resolve(open.result); };
      open.onerror = function () { reject(open.error); };
    });
    dbPromise.catch(function () { dbPromise = null; }); // private mode / blocked: try again next time
    return dbPromise;
  }
  function store(name, mode, fn) {
    return db().then(function (d) {
      return new Promise(function (resolve, reject) {
        var tx = d.transaction(name, mode);
        var result = fn(tx.objectStore(name));
        tx.oncomplete = function () { resolve(result && "result" in result ? result.result : result); };
        tx.onerror = function () { reject(tx.error); };
        tx.onabort = function () { reject(tx.error || new Error("not saved")); }; // e.g. the phone's storage is full
      });
    });
  }
  FVST.store = store;

  var NOT_SAVED = "No signal, and this phone couldn't save it (storage full or blocked). Try again when you have signal.";

  function mineOf(name) {
    return store(name, "readonly", function (s) { return s.getAll(); })
      .then(function (all) { return all.filter(mine); }, function () { return []; });
  }
  function queueAll() { return mineOf("queue"); }

  /** Shows how many taps/photos are waiting (Android "saved offline" note), and any Dispatch refused. */
  var pendingCount = 0;
  function refreshBanner() {
    return Promise.all([queueAll(), mineOf("parked")]).then(function (both) {
      var n = both[0].length, parked = both[1];
      pendingCount = n;
      document.querySelectorAll("[data-offline-banner]").forEach(function (b) {
        b.hidden = n === 0 && parked.length === 0;
        b.textContent = "";
        if (n) {
          b.appendChild(document.createTextNode(n + " update" + (n === 1 ? "" : "s") + " saved on this phone — they'll send to Dispatch once you're back online."));
        }
        if (parked.length) {
          var p = document.createElement("div");
          if (n) p.style.marginTop = "6px";
          p.appendChild(document.createTextNode("Dispatch didn't accept " + parked.length + " update" + (parked.length === 1 ? "" : "s") +
            " saved on this phone — call Dispatch to check: " + parked.map(function (x) { return x.label + " (" + x.error + ")"; }).join("; ") + " "));
          var ok = document.createElement("button");
          ok.type = "button";
          ok.className = "btn btn-sm btn-secondary";
          ok.textContent = "OK";
          ok.addEventListener("click", function () {
            store("parked", "readwrite", function (s) { parked.forEach(function (x) { s.delete(x.seq); }); }).then(refreshBanner, refreshBanner);
          });
          p.appendChild(ok);
          b.appendChild(p);
        }
      });
      var pending = document.querySelector('[data-net-value="pending"]');
      if (pending) pending.textContent = String(n);
      return n;
    });
  }
  FVST.refreshBanner = refreshBanner;

  /** Keeps a tap/photo on the phone. Resolves the answer for the page: saved, or not even that. */
  function enqueue(item, saved) {
    item.driver = DRIVER;
    return store("queue", "readwrite", function (s) { return s.add(item); }).then(function () {
      refreshBanner();
      return saved;
    }, function () {
      return { ok: false, error: NOT_SAVED };
    });
  }

  /**
   * A driver tap. The Idempotency-Key and client_time are fixed now, so a tap replayed from the queue later is
   * the same tap (applied once by the admin API). Resolves {ok, data, queued, error}.
   */
  FVST.write = function (method, path, body, opts) {
    opts = opts || {};
    body = Object.assign({}, body || {});
    if (opts.stampTime !== false) body[opts.timeField || "client_time"] = body[opts.timeField || "client_time"] || isoNow();
    if (opts.gps) {
      var fix = FVST.gps();
      if (fix.latitude != null && body.latitude == null) { body.latitude = fix.latitude; body.longitude = fix.longitude; }
    }
    var item = { kind: "json", method: method, path: path, body: body, key: uuid(), label: opts.label || path, createdAt: Date.now() };
    return FVST.call(method, path, body, { key: item.key }).then(function (r) {
      if (r.ok) return { ok: true, data: r.data };
      // Signed out: the tap is kept and goes once this driver has signed in again (the page is off to log in).
      if (r.offline || r.status >= 500 || r.signedOut) {
        if (opts.queue === false) return { ok: false, error: FVST.errorText(r) };
        return enqueue(item, { ok: false, queued: true, error: SAVED_OFFLINE });
      }
      return { ok: false, status: r.status, error: FVST.errorText(r), data: r.data };
    });
  };

  /** A proof photo: uploaded now, or kept (blob) and sent later with the same client_photo_id. */
  FVST.uploadPhoto = function (file, fields) {
    var clientId = fields.client_photo_id || uuid();
    var all = Object.assign({ taken_at: isoNow(), client_photo_id: clientId }, fields);
    var fix = FVST.gps();
    if (fix.latitude != null) { all.latitude = fix.latitude; all.longitude = fix.longitude; }
    var form = new FormData();
    Object.keys(all).forEach(function (k) { if (all[k] != null) form.append(k, all[k]); });
    form.append("file", file, file.name || "photo.jpg");
    var item = { kind: "photo", method: "POST", path: "photos", fields: all, blob: file, name: file.name || "photo.jpg", key: uuid(), label: "Photo for " + (fields.reference || ""), createdAt: Date.now() };
    // A photo upload gets longer than a tap before it counts as "no signal".
    return FVST.call("POST", "photos", form, { key: item.key, timeout: 60000 }).then(function (r) {
      if (r.ok && r.data && r.data.photo_id) return { ok: true, photoId: r.data.photo_id };
      if (r.offline || r.status >= 500 || r.signedOut) {
        return enqueue(item, { ok: true, queued: true, photoId: "client:" + clientId });
      }
      return { ok: false, error: FVST.errorText(r) };
    });
  };

  /**
   * What to do with a saved item after one try: "done"; "wait" (stop, the next sync tries again: no signal,
   * signed out, page out of date); "retry" (Dispatch failed: try again, up to MAX_TRIES); "refused" (Dispatch
   * said no for good, e.g. the stop changed meanwhile: kept aside and shown to the driver, never just dropped).
   */
  var MAX_TRIES = 5;
  function queueVerdict(r, tries) {
    if (r.ok) return "done";
    if (r.signedOut || r.offline || r.status === 0) return "wait";
    if (r.status === 403 && r.data && r.data.code === "CSRF") return "wait"; // page out of date: reload first
    if (r.status >= 500 || r.status === 429) return (tries || 0) + 1 < MAX_TRIES ? "retry" : "refused";
    return "refused";
  }
  FVST.queueVerdict = queueVerdict;

  function park(item, r) {
    var error = FVST.errorText(r);
    return store("parked", "readwrite", function (s) {
      s.add({ driver: item.driver != null ? item.driver : DRIVER, label: item.label || item.path, path: item.path, status: r.status, error: error, at: Date.now() });
    }).then(function () {
      FVST.toast("Dispatch didn't accept an update saved on this phone — see the note at the top.");
      // Dispatch hears about it too (best effort: the note on the phone is what counts).
      FVST.call("POST", "app-issues", {
        type: "app_function",
        detail: ("Saved offline, refused when sent: " + (item.label || item.path) + " — " + error).slice(0, 1000),
        device: { model: navigator.userAgent.slice(0, 120), os: navigator.platform || "", app_version: "web-1.0.0" },
        net_status: { online: navigator.onLine, server_reachable: true },
      });
    }, function () { /* nowhere to keep it: the toast is all that's left */
      FVST.toast("Dispatch didn't accept an update saved on this phone (" + error + "). Call Dispatch.");
    });
  }

  /** Replays this driver's queue in order; stops at the first item that has to wait (tries again later). */
  var syncing = false;
  FVST.sync = function () {
    if (syncing) return Promise.resolve();
    syncing = true;
    return queueAll().then(function (items) {
      var chain = Promise.resolve(true);
      items.forEach(function (item) {
        chain = chain.then(function (go) {
          if (!go) return false;
          var body = item.body;
          if (item.kind === "photo") {
            body = new FormData();
            Object.keys(item.fields).forEach(function (k) { if (item.fields[k] != null) body.append(k, item.fields[k]); });
            body.append("file", item.blob, item.name);
          }
          return FVST.call(item.method, item.path, body, { key: item.key, timeout: item.kind === "photo" ? 60000 : 15000 }).then(function (r) {
            var verdict = queueVerdict(r, item.tries);
            if (verdict === "wait") return false;
            if (verdict === "retry") {
              // Keeps its place (later taps may depend on it); the next sync tries again.
              item.tries = (item.tries || 0) + 1;
              return store("queue", "readwrite", function (s) { s.put(item); }).then(function () { return false; });
            }
            var removed = store("queue", "readwrite", function (s) { return s.delete(item.seq); });
            return (verdict === "done" ? removed : removed.then(function () { return park(item, r); })).then(function () { return true; });
          });
        });
      });
      return chain;
    }).then(function () {
      syncing = false;
      return refreshBanner();
    }, function () { syncing = false; });
  };

  // ---------------------------------------------------------------- GPS (FEATURE-202)
  // A fix older than this is not "where the driver is": it is never sent as the live position or stamped on a tap.
  var FRESH_FIX = 120000;
  var lastFix = null;
  try { lastFix = JSON.parse(localStorage.getItem("fvst-last-fix") || "null"); } catch (e) { lastFix = null; }
  if (lastFix && String(lastFix.driver) !== DRIVER) lastFix = null; // another driver's, on a shared phone
  function freshFix() { return lastFix && Date.now() - lastFix.at < FRESH_FIX ? lastFix : null; }
  FVST.gps = function () { var f = freshFix(); return f ? { latitude: f.latitude, longitude: f.longitude } : {}; };
  FVST.lastFix = function () { return lastFix; };

  // GitHub #47: how current the GPS is. While Online, a fix older than 2 minutes is "GPS not updating" (warning) and
  // older than 5 minutes — or none at all — "No GPS" (red): the thresholds Dispatch uses (GET /me gps_thresholds).
  var GPS_STALE = 120000, GPS_LOST = 300000;
  var gpsError = null; // why there's no fix: "denied", "unavailable", "timeout"; null once a fix arrives
  var gpsStartedAt = 0; // when the current GPS watch started (a fresh page has had no time to get a fix yet)
  function gpsState() {
    if (!navigator.geolocation) return { level: "lost", age: null, reason: "unsupported" };
    var age = lastFix ? Math.max(0, Date.now() - lastFix.at) : null;
    var level = age === null || age > GPS_LOST ? "lost" : age > GPS_STALE ? "stale" : "ok";
    return { level: level, age: age, reason: gpsError };
  }
  FVST.gpsState = gpsState;
  // Location switched off for the site shows straight away, without waiting for the watch to fail.
  if (navigator.permissions && navigator.permissions.query) {
    navigator.permissions.query({ name: "geolocation" }).then(function (p) {
      var apply = function () {
        if (p.state === "denied") gpsError = "denied"; else if (gpsError === "denied") gpsError = null;
        updateGpsUi();
      };
      p.onchange = apply;
      apply();
    }, function () { /* not supported for geolocation: the watch's error says it */ });
  }
  // GPS tester (test servers only): while a fake location is on, real fixes are ignored. Only while the tester
  // is on this server: a flag left on a phone after the tester was switched off must not block real GPS.
  var fakeGps = false;
  try { fakeGps = !!document.querySelector("[data-gps-tester-overlay]") && localStorage.getItem("fvst-fake-gps") === "1"; } catch (e) { fakeGps = false; }

  var toggle = document.querySelector("[data-online-toggle]");
  var watchId = null;
  var lastSent = null;
  var wakeLock = null;

  function isOnline() { return !!(toggle && toggle.classList.contains("is-online")); }

  function metres(a, b) {
    var R = 6371000, toRad = Math.PI / 180;
    var dLat = (b.latitude - a.latitude) * toRad, dLng = (b.longitude - a.longitude) * toRad;
    var h = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(a.latitude * toRad) * Math.cos(b.latitude * toRad) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return 2 * R * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
  }

  /** Every 15 s and 25 m, as the Android LocationService; failed pings are kept for presence/history. */
  function onFix(pos) {
    if (fakeGps) return;
    lastFix = { latitude: pos.coords.latitude, longitude: pos.coords.longitude, at: pos.timestamp || Date.now(), driver: DRIVER };
    try { localStorage.setItem("fvst-last-fix", JSON.stringify(lastFix)); } catch (e) { /* storage full or blocked */ }
    gpsError = null; // GitHub #47
    updateGpsUi();
    if (!isOnline()) return;
    if (lastSent && Date.now() - lastSentAt < HEARTBEAT && (lastFix.at - lastSent.at < 15000 || metres(lastSent, lastFix) < 25)) return;
    sendPing(lastFix);
  }

  // Admin counts a driver with no ping for 2 minutes as offline, so a driver who hasn't moved still pings once a minute.
  var HEARTBEAT = 60000;
  var lastSentAt = 0;
  function sendPing(fix) {
    lastSent = fix;
    lastSentAt = Date.now();
    var body = fix ? { online: true, latitude: fix.latitude, longitude: fix.longitude } : { online: true };
    return FVST.call("POST", "presence", body).then(function (r) {
      if (r.ok) {
        if (r.data && r.data.online === false) setToggle(false);
        if (fix) {
          try { localStorage.setItem("fvst-last-ping", JSON.stringify({ latitude: fix.latitude, longitude: fix.longitude, at: Date.now() })); } catch (e) { /* ignore */ }
        }
        flushPings();
        return r;
      }
      if (fix && (r.offline || r.status >= 500)) {
        store("pings", "readwrite", function (s) {
          s.add({ driver: DRIVER, latitude: fix.latitude, longitude: fix.longitude, recorded_at: isoNow(new Date(fix.at)) });
        }).then(trimPings).catch(function () {});
      }
      return r;
    });
  }
  // Online: ping every minute while the app is open, on screen or not (with the last fix when GPS is paused).
  // Offline (the driver's own choice): no pings at all.
  setInterval(function () {
    if (isOnline() && Date.now() - lastSentAt >= HEARTBEAT) sendPing(freshFix());
  }, 15000);

  function trimPings() {
    // About 20 hours at one ping per 15 s; past that the oldest are dropped (as on Android).
    return store("pings", "readwrite", function (s) {
      var req = s.getAllKeys();
      req.onsuccess = function () {
        var keys = req.result, extra = keys.length - 5000;
        for (var i = 0; i < extra; i++) s.delete(keys[i]);
      };
    });
  }

  var flushing = false;
  function flushPings() {
    if (flushing) return;
    flushing = true;
    mineOf("pings").then(function (all) { // only this driver's: another driver's route is not this one's history
      var batch = all.slice(0, 200);
      if (!batch.length) { flushing = false; return; }
      var pings = batch.map(function (p) { return { latitude: p.latitude, longitude: p.longitude, recorded_at: p.recorded_at }; });
      return FVST.call("POST", "presence/history", { pings: pings }).then(function (r) {
        if (r.offline || r.status >= 500 || r.status === 0) { flushing = false; return; }
        return store("pings", "readwrite", function (s) { batch.forEach(function (p) { s.delete(p.seq); }); }).then(function () {
          flushing = false;
          if (all.length > batch.length) flushPings();
        });
      });
    }).catch(function () { flushing = false; });
  }
  FVST.flushPings = flushPings;

  // Browsers stop GPS when the screen goes off: keep the screen on while Online (docs/DRIVER_APP.md §4).
  // Separate from the GPS watch, which already runs from page load (taps carry a fix even when Offline):
  // going Online later must still take the wake lock.
  var wakePending = false;
  function keepAwake() {
    if (!isOnline() || document.hidden || !navigator.wakeLock || wakeLock || wakePending) return;
    wakePending = true;
    navigator.wakeLock.request("screen").then(function (l) {
      wakePending = false;
      wakeLock = l;
      l.addEventListener("release", function () { wakeLock = null; });
    }, function () { wakePending = false; });
  }
  function letSleep() {
    if (wakeLock) { wakeLock.release().catch(function () {}); wakeLock = null; }
  }
  function startGps() {
    if (navigator.geolocation && watchId === null && !document.hidden) {
      gpsStartedAt = Date.now(); // GitHub #47
      watchId = navigator.geolocation.watchPosition(onFix, function (err) {
        // GitHub #47: why there's no fix, for the Connectivity panel and the "GPS not updating" warning.
        gpsError = err.code === err.PERMISSION_DENIED ? "denied" : err.code === err.POSITION_UNAVAILABLE ? "unavailable" : "timeout";
        updateGpsUi();
        // Not while the GPS tester is sending a location, or a location went out a moment ago: Dispatch can
        // already see the driver, so the banner would be wrong (GitHub #13).
        if (err.code === err.PERMISSION_DENIED && !fakeGps && !freshFix()) FVST.toast("Allow location so Dispatch can see where you are.");
      }, { enableHighAccuracy: true, maximumAge: 10000, timeout: 30000 });
    }
    keepAwake();
  }
  function stopGps() {
    if (watchId !== null) { navigator.geolocation.clearWatch(watchId); watchId = null; }
    letSleep();
  }

  function setToggle(on) {
    if (!toggle) return;
    toggle.classList.toggle("is-online", on);
    toggle.setAttribute("aria-checked", on ? "true" : "false"); // the CSS draws the Online / Offline label
    updateGpsUi(); // GitHub #47: the warning shows only while Online
  }

  if (toggle) {
    var send = function (next) {
      var fix = FVST.gps();
      FVST.call("POST", "presence", { online: next, latitude: fix.latitude, longitude: fix.longitude }).then(function (r) {
        if (!r.ok) { FVST.toast(FVST.errorText(r)); return; }
        var on = r.data && typeof r.data.online === "boolean" ? r.data.online : next;
        setToggle(on);
        // Offline keeps watching GPS (taps still carry a fix); only the screen may sleep again.
        if (on) { lastSent = null; lastSentAt = Date.now(); startGps(); } else { letSleep(); }
      });
    };
    // Going Offline while holding open jobs asks first: Dispatch keeps them on this driver (GitHub #20).
    var flip = function () {
      var next = !isOnline();
      if (next) { send(true); return; }
      FVST.call("GET", "stops", null, { query: { view: "today" } }).then(function (r) {
        var open = 0;
        if (r.ok && r.data && r.data.days) {
          r.data.days.forEach(function (d) {
            (d.stops || []).forEach(function (st) { if (!st.completed_at && st.status !== "delivered" && st.status !== "failed") open++; });
          });
        }
        if (open > 0 && !window.confirm("You have " + open + " open stop" + (open === 1 ? "" : "s") + ". Dispatch will be told you went offline while holding them. Go offline anyway?")) return;
        send(false);
      });
    };
    toggle.addEventListener("click", flip);
    toggle.addEventListener("keydown", function (e) { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); flip(); } });
  }

  // Taps carry a fix even when Offline, so watch GPS while the page is visible; pings go out only when Online.
  startGps();
  if (isOnline()) sendPing(freshFix()); // the page just opened: show the driver online straight away
  document.addEventListener("visibilitychange", function () {
    if (document.hidden) stopGps(); else { startGps(); FVST.sync(); }
  });

  // ---------------------------------------------------------------- slide-in panels (drawer, messages)
  // As the mockup's app.js: unhide, then add .open on the next frame so the CSS slide runs; reverse to close.
  function slideOpen(el) {
    if (!el) return;
    el.hidden = false;
    requestAnimationFrame(function () { requestAnimationFrame(function () { el.classList.add("open"); }); });
  }
  function slideClose(el) {
    if (!el || el.hidden) return;
    el.classList.remove("open");
    setTimeout(function () { if (!el.classList.contains("open")) el.hidden = true; }, 220);
  }
  FVST.slideOpen = slideOpen;
  FVST.slideClose = slideClose;

  // ---------------------------------------------------------------- drawer
  var drawer = document.querySelector("[data-sidebar-overlay]");
  document.querySelectorAll("[data-sidebar-open]").forEach(function (b) { b.addEventListener("click", function () { slideOpen(drawer); }); });
  document.querySelectorAll("[data-sidebar-close]").forEach(function (b) { b.addEventListener("click", function () { slideClose(drawer); }); });
  if (drawer) drawer.addEventListener("click", function (e) { if (e.target === drawer) slideClose(drawer); });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape") slideClose(drawer); });

  // ---------------------------------------------------------------- back to the same task view
  // Delegated, so cards re-rendered in place by the live refresh work too (GitHub #7).
  document.addEventListener("click", function (e) {
    if (!e.target.closest || !e.target.closest(".task-card, .map-pin")) return;
    try { sessionStorage.setItem("fvst-tasks-return", window.location.pathname + window.location.search); } catch (err) { /* ignore */ }
  });
  // Run again on content swapped in by the live refresh (GitHub #7).
  function backLinks() {
    document.querySelectorAll("[data-back-to-tasks]").forEach(function (a) {
      // Only ever a task view (/driver/tasks or /driver/route), never this stop again.
      try { var back = sessionStorage.getItem("fvst-tasks-return"); if (back && /^\/driver\/(tasks|route)(\?|$)/.test(back)) a.href = back; } catch (e) { /* ignore */ }
    });
  }
  backLinks();

  // ---------------------------------------------------------------- generic API buttons (e.g. breaks)
  document.querySelectorAll("[data-api-action]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var body = {};
      try { body = JSON.parse(btn.getAttribute("data-body") || "{}"); } catch (e) { body = {}; }
      btn.disabled = true;
      FVST.write(btn.getAttribute("data-method") || "POST", btn.getAttribute("data-path"), body, {
        timeField: btn.getAttribute("data-stamp-time") || "client_time",
      }).then(function (r) {
        if (r.ok) { window.location.reload(); return; }
        FVST.toast(r.queued ? "Saved on this phone — it'll send when you're back online." : r.error);
        btn.disabled = false;
      });
    });
  });

  // ---------------------------------------------------------------- messages (FEATURE-200)
  var STAR_SVG = '<svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
  var messages = [];
  var starredOnly = false;
  var overlay = document.querySelector("[data-messages-overlay]");
  var list = overlay ? overlay.querySelector("[data-messages-list]") : null;

  function badge() {
    var n = messages.filter(function (m) { return !m.read; }).length;
    document.querySelectorAll("[data-messages-badge]").forEach(function (b) { b.hidden = n === 0; b.textContent = n > 9 ? "9+" : String(n); });
  }
  function timeLabel(iso) {
    var d = new Date(iso);
    if (isNaN(d)) return "";
    var h = d.getHours(), m = d.getMinutes();
    return (h % 12 || 12) + ":" + (m < 10 ? "0" : "") + m + " " + (h < 12 ? "AM" : "PM");
  }
  function renderMessages() {
    if (!list) return;
    var visible = messages.filter(function (m) { return !starredOnly || m.starred; });
    if (!visible.length) {
      list.innerHTML = '<p class="text-faint" style="text-align:center;padding:20px 14px;">' + (starredOnly ? "No starred messages." : "No messages.") + "</p>";
      return;
    }
    var stopUrl = (FVST.urls.base || "/driver") + "/stops/";
    list.innerHTML = visible.map(function (m) {
      var leg = m.leg === "pickup" ? "pickup" : "delivery";
      return '<div class="message-row" data-read="' + (m.read ? "true" : "false") + '" data-id="' + m.id + '">' +
        '<div class="message-row-top" data-message-toggle>' +
          '<div class="message-row-main"><div class="message-row-order">' + esc(m.order_name || m.reference) + '</div>' +
          '<div class="message-row-subject">' + esc(m.subject) + "</div></div>" +
          '<div class="message-row-side"><span class="message-row-time">' + esc(timeLabel(m.sent_at)) + "</span>" +
          '<button type="button" class="message-star-btn' + (m.starred ? " is-starred" : "") + '" data-message-star aria-label="' + (m.starred ? "Unstar" : "Star") + ' this message">' + STAR_SVG + "</button></div>" +
        "</div>" +
        '<div class="message-row-detail" hidden><p style="margin:0 0 10px;">' + esc(m.body) + "</p>" +
          (m.stop_key ? '<a class="message-row-goto" href="' + stopUrl + encodeURIComponent(m.stop_key) + '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>Go to ' + leg + " page</a>" : "") +
        "</div></div>";
    }).join("");
  }
  function findMessage(el) {
    var id = parseInt(el.closest(".message-row").getAttribute("data-id"), 10);
    return messages.filter(function (m) { return m.id === id; })[0];
  }
  if (list) {
    list.addEventListener("click", function (e) {
      var star = e.target.closest("[data-message-star]");
      if (star) {
        e.stopPropagation();
        var sm = findMessage(star);
        sm.starred = !sm.starred;
        renderMessages();
        FVST.call("PUT", "messages/" + sm.id + "/star", { starred: sm.starred }).then(function (r) {
          if (!r.ok) { sm.starred = !sm.starred; renderMessages(); FVST.toast(FVST.errorText(r)); }
        });
        return;
      }
      var row = e.target.closest("[data-message-toggle]");
      if (!row) return;
      var m = findMessage(row);
      var detail = row.closest(".message-row").querySelector(".message-row-detail");
      detail.hidden = !detail.hidden;
      if (!m.read) {
        m.read = true;
        row.closest(".message-row").setAttribute("data-read", "true");
        badge();
        FVST.call("POST", "messages/" + m.id + "/read");
      }
    });
  }
  function loadMessages() {
    return FVST.call("GET", "messages").then(function (r) {
      if (r.ok && r.data && r.data.messages) { messages = r.data.messages; badge(); renderMessages(); }
    });
  }
  document.querySelectorAll("[data-messages-open]").forEach(function (b) {
    b.addEventListener("click", function () { if (overlay) { slideOpen(overlay); renderMessages(); loadMessages(); } });
  });
  if (overlay) {
    overlay.querySelector("[data-messages-close]").addEventListener("click", function () { slideClose(overlay); });
    var filter = overlay.querySelector("[data-messages-star-filter]");
    filter.addEventListener("click", function () {
      starredOnly = !starredOnly;
      filter.classList.toggle("is-active", starredOnly);
      filter.setAttribute("aria-pressed", starredOnly ? "true" : "false");
      filter.setAttribute("aria-label", starredOnly ? "Show all messages" : "Show starred only");
      renderMessages();
    });
    loadMessages();
  }

  // ---------------------------------------------------------------- connectivity + report issue (FEATURE-204)
  var net = document.querySelector("[data-network-status-overlay]");
  function ago(ms) {
    var s = Math.max(0, Math.round((Date.now() - ms) / 1000));
    return s < 60 ? s + "s ago" : Math.round(s / 60) + " min ago";
  }
  function setNet(name, value, meta) {
    var v = net.querySelector('[data-net-value="' + name + '"]');
    if (v) v.textContent = value;
    var mt = net.querySelector('[data-net-meta="' + name + '"]');
    if (mt && meta !== undefined) mt.textContent = meta;
  }
  // GitHub #47: the GPS row is green only while the last fix is recent; otherwise its age in warning / red, and what to do.
  var GPS_HINT = "Turn on Location, allow this app to use it, and keep the phone where it can see the sky.";
  function gpsText() {
    var st = gpsState();
    if (st.reason === "unsupported") return { value: "No", cls: "is-down", meta: "This browser can't share its location.", st: st };
    if (st.reason === "denied") return { value: "Off", cls: "is-down", meta: "Location is blocked for this app. Allow location for this site in the phone's browser settings, then reopen the app.", st: st };
    var since = lastFix ? "Last fix " + ago(lastFix.at) : "No fix yet";
    if (st.level === "ok") return { value: "Yes", cls: "", meta: since, st: st };
    var why = st.reason === "unavailable" ? " — the phone can't find its position." : "";
    return st.level === "stale"
      ? { value: "Not updating", cls: "is-warn", meta: since + why + ". " + GPS_HINT, st: st }
      : { value: "No GPS", cls: "is-down", meta: since + why + ". " + GPS_HINT, st: st };
  }
  function updateGpsUi() {
    var t = gpsText();
    if (net) {
      var v = net.querySelector('[data-net-value="gps"]');
      if (v) { v.classList.toggle("is-warn", t.cls === "is-warn"); v.classList.toggle("is-down", t.cls === "is-down"); }
      if (!net.hidden) setNet("gps", t.value, t.meta);
    }
    // The warning by the Online toggle: only while Online, and not in the first seconds of a watch that hasn't had
    // time to get a fix (unless the phone already said why there's none).
    var show = isOnline() && t.st.level !== "ok" && (t.st.reason !== null || Date.now() - gpsStartedAt > 20000);
    document.querySelectorAll("[data-gps-stale]").forEach(function (b) {
      b.hidden = !show;
      if (!show) return;
      b.classList.toggle("alert-danger", t.cls === "is-down");
      b.classList.toggle("alert-warning", t.cls !== "is-down");
      b.textContent = (t.st.reason === "denied" ? "Location is off for this app" : t.st.level === "lost" ? "No GPS" : "GPS not updating") +
        (lastFix && t.st.reason !== "denied" ? " — last fix " + ago(lastFix.at) : "") + ". Dispatch can't see where you are. Tap for help.";
    });
  }
  setInterval(updateGpsUi, 15000);
  updateGpsUi();
  document.addEventListener("fvst:content", updateGpsUi); // the live refresh swapped in a hidden warning

  // GitHub #48: map or list — the task view the driver last chose in the header (tasks.html.twig opens the map unless
  // it's "list").
  document.addEventListener("click", function (e) {
    var a = e.target.closest ? e.target.closest("[data-task-view]") : null;
    if (!a) return;
    try { localStorage.setItem("fvst-task-view", a.getAttribute("data-task-view")); } catch (err) { /* this visit only */ }
  });

  var serverReachable = null;
  function checkNet() {
    setNet("internet", navigator.onLine ? "Yes" : "No");
    var g = gpsText(); // GitHub #47
    setNet("gps", g.value, g.meta);
    updateGpsUi();
    refreshBanner();
    var started = Date.now();
    return FVST.call("GET", "ping").then(function (r) {
      serverReachable = r.ok;
      setNet("dispatch", r.ok ? "Yes" : "No", r.ok ? "Answered in " + (Date.now() - started) + " ms" : FVST.errorText(r));
      net.querySelector("[data-network-status-warning]").hidden = r.ok && navigator.onLine;
    });
  }
  document.querySelectorAll("[data-network-status-open]").forEach(function (b) {
    b.addEventListener("click", function () {
      if (drawer) { drawer.classList.remove("open"); drawer.hidden = true; }
      net.hidden = false;
      checkNet();
    });
  });
  if (net) {
    var submit = net.querySelector("[data-network-report-submit]");
    submit.addEventListener("click", function () {
      var detail = net.querySelector("[data-network-report-detail]");
      if (!detail.value.trim()) { FVST.toast("Describe what's going on."); return; }
      submit.disabled = true;
      FVST.write("POST", "app-issues", {
        type: net.querySelector("[data-network-report-type]").value,
        detail: detail.value.trim(),
        device: { model: navigator.userAgent.slice(0, 120), os: navigator.platform || "", app_version: "web-1.0.0" },
        net_status: { online: navigator.onLine, server_reachable: !!serverReachable },
      }, { stampTime: false }).then(function (r) {
        submit.disabled = false;
        if (r.ok || r.queued) {
          detail.value = "";
          net.querySelector("[data-network-report-sent]").hidden = false;
        } else {
          FVST.toast(r.error);
        }
      });
    });
  }

  // ---------------------------------------------------------------- GPS tester (test servers only)
  // Rendered only with DRIVER_GPS_TESTER=1.
  var tester = document.querySelector("[data-gps-tester-overlay]");
  var testerOpen = document.querySelector("[data-gps-tester-open]");
  if (tester && testerOpen) {
    var lastPing = function () {
      try { return JSON.parse(localStorage.getItem("fvst-last-ping") || "null"); } catch (e) { return null; }
    };
    var showTester = function () {
      var p = lastPing();
      tester.querySelector("[data-gps-tester-last]").textContent = p ? p.latitude.toFixed(6) + ", " + p.longitude.toFixed(6) : "None yet";
      tester.querySelector("[data-gps-tester-ago]").textContent = p ? ago(p.at) : "";
      tester.querySelector("[data-gps-tester-mode]").textContent = fakeGps
        ? "Using a test location — real GPS is ignored until you tap Use real GPS."
        : "Using real GPS.";
    };
    // Sends the location now (no 15 s / 25 m throttle) and keeps it as the phone's fix until real GPS is back on.
    var report = function (lat, lng) {
      if (!isFinite(lat) || !isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) { FVST.toast("Enter a valid latitude and longitude."); return; }
      fakeGps = true;
      try { localStorage.setItem("fvst-fake-gps", "1"); } catch (e) { /* this page only */ }
      lastFix = { latitude: lat, longitude: lng, at: Date.now(), driver: DRIVER };
      try { localStorage.setItem("fvst-last-fix", JSON.stringify(lastFix)); } catch (e) { /* ignore */ }
      sendPing(lastFix).then(function (r) {
        if (r.ok) {
          if (!(r.data && r.data.online === false) && !isOnline()) { setToggle(true); startGps(); }
          FVST.toast("Sent " + lat.toFixed(6) + ", " + lng.toFixed(6));
        } else {
          FVST.toast(r.offline || r.status >= 500 ? "No connection — kept for presence/history." : FVST.errorText(r));
        }
        showTester();
      });
    };
    var from = function () {
      var p = lastPing() || lastFix;
      if (p) return p;
      var v = tester.querySelector("[data-gps-tester-place]").value.split(",");
      return { latitude: parseFloat(v[0]), longitude: parseFloat(v[1]) };
    };

    testerOpen.addEventListener("click", function () {
      if (drawer) { drawer.classList.remove("open"); drawer.hidden = true; }
      tester.hidden = false;
      showTester();
    });
    tester.querySelector("[data-gps-tester-place-go]").addEventListener("click", function () {
      var v = tester.querySelector("[data-gps-tester-place]").value.split(",");
      report(parseFloat(v[0]), parseFloat(v[1]));
    });
    // Move: the chosen distance (number × m or km) in the chosen direction from the last location.
    tester.querySelector("[data-gps-tester-move]").addEventListener("click", function () {
      var meters = parseFloat(tester.querySelector("[data-gps-tester-distance]").value) *
        parseFloat(tester.querySelector("[data-gps-tester-unit]").value);
      var p = from(), d = meters / 111320; // metres in degrees of latitude
      var lat = p.latitude, lng = p.longitude, dir = tester.querySelector("[data-gps-tester-direction]").value;
      if (dir === "north") lat += d;
      if (dir === "south") lat -= d;
      if (dir === "east") lng += d / Math.cos(lat * Math.PI / 180);
      if (dir === "west") lng -= d / Math.cos(lat * Math.PI / 180);
      report(lat, lng);
    });
    // Sidebar zone dropdown (testing only): the admin's delivery zones; picking one reports its centre.
    var zoneSelect = document.querySelector("[data-gps-test-zone]");
    if (zoneSelect) {
      fetch((FVST.urls.base || "/driver") + "/test/zones", { credentials: "same-origin", headers: { Accept: "application/json" } })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          (data.zones || []).forEach(function (z) {
            zoneSelect.add(new Option(z.name, z.latitude + "," + z.longitude));
          });
          if (!(data.zones || []).length) zoneSelect.options[0].text = data.error || "No zones from Dispatch";
        })
        .catch(function () { zoneSelect.options[0].text = "Couldn't load zones"; });
      zoneSelect.addEventListener("change", function () {
        if (!zoneSelect.value) return;
        var v = zoneSelect.value.split(",");
        report(parseFloat(v[0]), parseFloat(v[1]));
      });
    }
    tester.querySelector("[data-gps-tester-custom-go]").addEventListener("click", function () {
      report(parseFloat(tester.querySelector("[data-gps-tester-lat]").value), parseFloat(tester.querySelector("[data-gps-tester-lng]").value));
    });
    tester.querySelector("[data-gps-tester-real]").addEventListener("click", function () {
      fakeGps = false;
      try { localStorage.removeItem("fvst-fake-gps"); } catch (e) { /* ignore */ }
      stopGps();
      startGps();
      FVST.toast("Back on real GPS.");
      showTester();
    });
  }

  // ---------------------------------------------------------------- logout (shared phones)
  // Saved updates stay on the phone for this driver's next sign-in; the driver is told before leaving them.
  // This driver's location and screen positions are cleared, so the next driver starts clean.
  document.querySelectorAll('a[href$="/driver/logout"]').forEach(function (a) {
    a.addEventListener("click", function (e) {
      if (pendingCount > 0 && !window.confirm(pendingCount + " update" + (pendingCount === 1 ? " is" : "s are") +
          " saved on this phone and not yet with Dispatch. They'll send when you log in again on this phone. Log out anyway?")) {
        e.preventDefault();
        return;
      }
      try { ["fvst-last-fix", "fvst-last-ping", "fvst-fake-gps"].forEach(function (k) { localStorage.removeItem(k); }); } catch (err) { /* ignore */ }
      try { sessionStorage.clear(); } catch (err) { /* ignore */ }
    });
  });

  // ---------------------------------------------------------------- start-up
  // "No signal?" notes show only while the phone is actually offline (GitHub #19).
  function showOfflineNotes() {
    document.querySelectorAll("[data-offline-only]").forEach(function (el) { el.hidden = navigator.onLine; });
  }
  showOfflineNotes();
  window.addEventListener("offline", showOfflineNotes);
  window.addEventListener("online", showOfflineNotes);
  window.addEventListener("online", function () { FVST.sync(); flushPings(); });
  FVST.sync();
  flushPings();
  setInterval(function () { if (navigator.onLine) { FVST.sync(); flushPings(); } }, 30000);

  // ---------------------------------------------------------------- live refresh (GitHub #7)
  // No page reloads: the task list, route and stop screens ask Dispatch for this driver's version (GET /version)
  // about every 15 s, and only when it moved fetch the same page again and swap its content in place.
  var liveVersion = null;
  var liveBusy = false;
  // GitHub #53: this driver's live file on the Dispatch server (a static file, named by a random token; its "v" is the
  // same version GET /version gives). Polled directly — the web server answers it, 304 when unchanged — and the
  // API is asked again only when the file is gone (the folder was renamed) or can't be read.
  var liveUrl = null;

  function apiVersion() {
    return FVST.call("GET", "version", null, { timeout: 10000 }).then(function (r) {
      if (r.ok && r.data && r.data.live_url) liveUrl = r.data.live_url;
      return r.ok && r.data ? r.data.version : null;
    });
  }
  function readVersion() {
    if (!liveUrl) return apiVersion();
    return fetch(liveUrl, { cache: "no-cache", mode: "cors", credentials: "omit" }).then(function (res) {
      if (!res.ok) { liveUrl = null; return apiVersion(); }
      return res.json().then(function (d) { return d && d.v ? d.v : null; });
    }).catch(function () { liveUrl = null; return apiVersion(); });
  }

  /** Swaps .app-content (all but the offline banner, which stays) for the same part of `url`'s page. */
  function swapContent(url) {
    return fetch(url, { credentials: "same-origin", headers: { Accept: "text/html" } }).then(function (res) {
      // Signed out (the login page) or an error page: leave the screen as it is.
      if (!res.ok || (res.redirected && /\/login(\?|$)/.test(res.url))) return false;
      return res.text().then(function (html) {
        var doc = new DOMParser().parseFromString(html, "text/html");
        var next = doc.querySelector(".app-content");
        var here = document.querySelector(".app-content");
        if (!next || !here) return false;
        var banner = here.querySelector("[data-offline-banner]");
        var nextBanner = next.querySelector("[data-offline-banner]");
        if (nextBanner) nextBanner.remove();
        var scrollTop = here.scrollTop, scrollY = window.scrollY;
        here.textContent = "";
        if (banner) here.appendChild(banner);
        Array.prototype.slice.call(next.childNodes).forEach(function (n) { here.appendChild(document.adoptNode(n)); });
        here.scrollTop = scrollTop;
        window.scrollTo(0, scrollY);
        if (doc.title) document.title = doc.title;
        backLinks();
        showOfflineNotes();
        // The page's own script (stop.js) binds the new content.
        document.dispatchEvent(new CustomEvent("fvst:content"));
        return true;
      });
    }).catch(function () { return false; });
  }

  /**
   * Re-renders this screen (or `url`, e.g. the new key of a finished pickup) in place; resolves true when done. The
   * version is read first, so the page fetched after it is at least that new and the next poll doesn't redo it.
   */
  FVST.refreshContent = function (url) {
    return apiVersion().then(function (v) {
      return swapContent(url || window.location.href).then(function (ok) {
        if (ok && v) liveVersion = v;
        return ok;
      });
    });
  };

  /** Not now: a change still only saved on this phone, a modal / the camera / a menu open, or the page's own hold. */
  function liveHeld() {
    if (pendingCount > 0) return true;
    if (document.querySelector(".confirm-overlay:not([hidden]), [data-camera]:not([hidden]), [data-messages-overlay]:not([hidden]), [data-sidebar-overlay]:not([hidden])")) return true;
    var active = document.activeElement;
    var content = document.querySelector(".app-content");
    if (active && content && content.contains(active) && /^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName)) return true;
    // stop.js: a tap in flight, a status saved on this phone, photos taken, the proof form or checklist touched.
    return typeof FVST.holdLive === "function" && !!FVST.holdLive();
  }

  function livePoll() {
    if (liveBusy || document.hidden || !navigator.onLine) return;
    liveBusy = true;
    readVersion().then(function (v) {
      if (!v) return;
      if (liveVersion === null) { liveVersion = v; return; }
      if (v === liveVersion) return;
      if (overlay) loadMessages(); // a new Dispatch message moves the version too: keep the badge current
      if (liveHeld()) return; // the version isn't taken, so the next poll tries again
      return swapContent(window.location.href).then(function (ok) { if (ok) liveVersion = v; });
    }).then(function () { liveBusy = false; }, function () { liveBusy = false; });
  }

  if (/\/driver\/(tasks|route|stops\/[PD]-\d+)\/?$/.test(window.location.pathname)) {
    livePoll();
    setInterval(livePoll, 15000);
    document.addEventListener("visibilitychange", function () { if (!document.hidden) livePoll(); });
  }

  if ("serviceWorker" in navigator) {
    navigator.serviceWorker.register((FVST.urls.base || "/driver") + "/sw.js", { scope: (FVST.urls.base || "/driver") + "/" }).catch(function () {});
  }
})();
