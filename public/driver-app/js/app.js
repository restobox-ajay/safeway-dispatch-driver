/* =========================================================================
   Safeway Tire Driver web app — shared code on every signed-in page.
   Every call goes to /driver/api/<path>, which this app passes to safeway-dispatch's Driver API
   (docs/driver-api.md) as the signed-in driver.

   window.SWD:
     call(method, path, body, opts)   one call; resolves {ok, status, data, offline, signedOut}, never rejects
     write(method, path, body, opts)  a tap: Idempotency-Key + client_time fixed now, kept on the phone when offline
     upload(blob, fields, opts)       a photo upload (multipart), kept on the phone when offline
     gps()                            the latest fix {lat, lng, accuracy} or null
     toast(text)
   ========================================================================= */
(function () {
  "use strict";

  var SWD = (window.SWD = window.SWD || {});
  var API = (SWD.urls && SWD.urls.base ? SWD.urls.base : "/driver") + "/api/";
  var NO_CONNECTION = "No connection to Dispatch. Check your signal and try again.";
  var SAVED_OFFLINE = "No signal — saved on this phone. It will be sent when you are back online.";
  var APP_VERSION = "safeway-web-1.0.0";

  // ---------------------------------------------------------------- helpers
  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    var b = new Uint8Array(16);
    crypto.getRandomValues(b);
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    var x = Array.prototype.map.call(b, function (v) { return ("0" + v.toString(16)).slice(-2); }).join("");
    return x.slice(0, 8) + "-" + x.slice(8, 12) + "-" + x.slice(12, 16) + "-" + x.slice(16, 20) + "-" + x.slice(20);
  }
  SWD.uuid = uuid;

  /** RFC 3339 with the phone's offset and whole seconds (what the Driver API parses). */
  function isoNow(date) {
    var d = date || new Date();
    var pad = function (n) { return (n < 10 ? "0" : "") + n; };
    var off = -d.getTimezoneOffset();
    var sign = off >= 0 ? "+" : "-";
    off = Math.abs(off);
    return d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate()) + "T" + pad(d.getHours()) + ":" +
      pad(d.getMinutes()) + ":" + pad(d.getSeconds()) + sign + pad(Math.floor(off / 60)) + ":" + pad(off % 60);
  }
  SWD.isoNow = isoNow;

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  SWD.esc = esc;

  SWD.toast = function (text) {
    var t = document.querySelector("[data-toast]");
    if (!t) {
      t = document.createElement("div");
      t.className = "toast";
      t.setAttribute("data-toast", "");
      t.setAttribute("role", "status");
      document.body.appendChild(t);
    }
    t.textContent = text;
    t.classList.add("is-shown");
    clearTimeout(t._timer);
    t._timer = setTimeout(function () { t.classList.remove("is-shown"); }, 4000);
  };

  // ---------------------------------------------------------------- overlays
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
  SWD.slideOpen = slideOpen;
  SWD.slideClose = slideClose;

  document.addEventListener("click", function (e) {
    var close = e.target.closest("[data-close]");
    if (close) { var m = close.closest(".confirm-overlay"); if (m) { m.hidden = true; m.classList.remove("open"); } return; }
    if (e.target.classList && e.target.classList.contains("confirm-overlay")) { e.target.hidden = true; e.target.classList.remove("open"); }
    // the driver-name menu closes when tapping elsewhere
    document.querySelectorAll("details.user-menu[open]").forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute("open"); });
  });

  var drawer = document.querySelector("[data-sidebar-overlay]");
  document.querySelectorAll("[data-sidebar-open]").forEach(function (b) { b.addEventListener("click", function () { slideOpen(drawer); }); });
  document.querySelectorAll("[data-sidebar-close]").forEach(function (b) { b.addEventListener("click", function () { slideClose(drawer); }); });
  if (drawer) drawer.addEventListener("click", function (e) { if (e.target === drawer) slideClose(drawer); });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape") slideClose(drawer); });
  function closeDrawerNow() { if (drawer) { drawer.classList.remove("open"); drawer.hidden = true; } }

  // ---------------------------------------------------------------- API calls
  /**
   * What a response to a /driver/api call means. Signed out = the JSON 401, or anything that is not the JSON the
   * proxy always answers with (a redirect followed to the login page): never a success.
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

  /** No answer within opts.timeout (15 s) counts as no signal, so a tap is queued rather than left hanging. */
  SWD.call = function (method, path, body, opts) {
    opts = opts || {};
    var headers = { Accept: "application/json" };
    var init = { method: method, headers: headers, credentials: "same-origin" };
    var abort = window.AbortController ? new AbortController() : null;
    if (abort) init.signal = abort.signal;
    if (method !== "GET") {
      headers["X-CSRF-Token"] = SWD.csrf;
      headers["Idempotency-Key"] = opts.key || uuid();
    }
    if (body instanceof FormData) {
      init.body = body;
    } else if (body !== undefined && body !== null && method !== "GET") {
      headers["Content-Type"] = "application/json";
      init.body = JSON.stringify(body);
    }
    var timer = abort ? setTimeout(function () { abort.abort(); }, opts.timeout || 15000) : null;
    return fetch(API + path, init).then(function (res) {
      return res.text().then(function (text) {
        clearTimeout(timer);
        var r = readResponse(res.status, res.redirected, text);
        if (r.signedOut) window.location.href = (r.data && r.data.redirect) || SWD.urls.login;
        return r;
      });
    }).catch(function () {
      clearTimeout(timer);
      return { ok: false, status: 0, data: { error: NO_CONNECTION }, offline: true, signedOut: false };
    });
  };

  SWD.errorText = function (r) {
    if (!r || !r.data) return NO_CONNECTION;
    if (r.data.error) return r.data.error;
    var errs = r.data.errors;
    return (errs && Object.keys(errs).length && errs[Object.keys(errs)[0]]) || NO_CONNECTION;
  };

  // ---------------------------------------------------------------- offline store (IndexedDB)
  // Every saved tap, photo and position carries the driver it belongs to: on a shared phone one driver's saved
  // updates are never sent with another driver's session.
  var DRIVER = SWD.driverId != null ? String(SWD.driverId) : "";
  function mine(item) { return item.driver == null || String(item.driver) === DRIVER; }

  var dbPromise = null;
  function db() {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise(function (resolve, reject) {
      if (!window.indexedDB) { reject(new Error("no IndexedDB")); return; }
      var open = indexedDB.open("swd-driver", 1);
      open.onupgradeneeded = function () {
        var d = open.result;
        if (!d.objectStoreNames.contains("queue")) d.createObjectStore("queue", { keyPath: "seq", autoIncrement: true });
        if (!d.objectStoreNames.contains("positions")) d.createObjectStore("positions", { keyPath: "seq", autoIncrement: true });
        // Saved updates Dispatch refused when they were finally sent: kept to show the driver, not dropped.
        if (!d.objectStoreNames.contains("parked")) d.createObjectStore("parked", { keyPath: "seq", autoIncrement: true });
      };
      open.onsuccess = function () { resolve(open.result); };
      open.onerror = function () { reject(open.error); };
    });
    dbPromise.catch(function () { dbPromise = null; });
    return dbPromise;
  }
  function store(name, mode, fn) {
    return db().then(function (d) {
      return new Promise(function (resolve, reject) {
        var tx = d.transaction(name, mode);
        var result = fn(tx.objectStore(name));
        tx.oncomplete = function () { resolve(result && "result" in result ? result.result : result); };
        tx.onerror = function () { reject(tx.error); };
        tx.onabort = function () { reject(tx.error || new Error("not saved")); };
      });
    });
  }
  function mineOf(name) {
    return store(name, "readonly", function (s) { return s.getAll(); })
      .then(function (all) { return all.filter(mine); }, function () { return []; });
  }
  var NOT_SAVED = "No signal, and this phone couldn't save it (storage full or blocked). Try again when you have signal.";

  // Ask the browser not to clear what is saved here when the phone runs low on space.
  if (navigator.storage && navigator.storage.persist) navigator.storage.persist().catch(function () {});

  /** How many taps/photos are waiting, and any Dispatch refused. */
  var pendingCount = 0;
  function refreshBanner() {
    return Promise.all([mineOf("queue"), mineOf("parked")]).then(function (both) {
      var n = both[0].length, parked = both[1];
      pendingCount = n;
      document.querySelectorAll("[data-offline-banner]").forEach(function (b) {
        b.hidden = n === 0 && parked.length === 0;
        b.textContent = "";
        if (n) b.appendChild(document.createTextNode(n + " update" + (n === 1 ? "" : "s") + " saved on this phone — sent to Dispatch once you are back online."));
        if (parked.length) {
          var p = document.createElement("div");
          p.appendChild(document.createTextNode("Dispatch did not accept " + parked.length + " saved update" + (parked.length === 1 ? "" : "s") +
            " — call Dispatch to check: " + parked.map(function (x) { return x.label + " (" + x.error + ")"; }).join("; ") + " "));
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
  SWD.refreshBanner = refreshBanner;

  function enqueue(item, saved) {
    item.driver = DRIVER;
    item.queuedAt = Date.now();
    return store("queue", "readwrite", function (s) { return s.add(item); }).then(function () {
      refreshBanner();
      return saved;
    }, function () { return { ok: false, error: NOT_SAVED }; });
  }

  /**
   * A driver tap. The Idempotency-Key and client_time are fixed now, so a tap replayed from the queue later is the
   * same tap (applied once by the Driver API). Resolves {ok, data, queued, error, status}.
   */
  SWD.write = function (method, path, body, opts) {
    opts = opts || {};
    body = Object.assign({}, body || {});
    if (opts.stampTime !== false && !body.client_time) body.client_time = isoNow();
    var item = { kind: "json", method: method, path: path, body: body, key: uuid(), label: opts.label || path };
    return SWD.call(method, path, body, { key: item.key }).then(function (r) {
      if (r.ok) return { ok: true, data: r.data };
      if (r.offline || r.status >= 500 || r.signedOut) {
        if (opts.queue === false) return { ok: false, error: SWD.errorText(r) };
        return enqueue(item, { ok: false, queued: true, error: SAVED_OFFLINE });
      }
      return { ok: false, status: r.status, error: SWD.errorText(r), data: r.data };
    });
  };

  function photoForm(fields, blob) {
    var form = new FormData();
    Object.keys(fields).forEach(function (k) { if (fields[k] != null) form.append(k, fields[k]); });
    form.append("file", blob, (fields.client_photo_id || "photo") + ".jpg");
    return form;
  }

  /** A photo upload (POST /photos): sent now, or kept (blob) and sent later with the same client_photo_id. */
  SWD.upload = function (blob, fields, opts) {
    opts = opts || {};
    var item = { kind: "photo", method: "POST", path: "photos", fields: fields, blob: blob, key: uuid(), label: opts.label || "Photo" };
    return SWD.call("POST", "photos", photoForm(fields, blob), { key: item.key, timeout: 60000 }).then(function (r) {
      if (r.ok) return { ok: true, data: r.data };
      if (r.offline || r.status >= 500 || r.signedOut) return enqueue(item, { ok: false, queued: true });
      return { ok: false, error: SWD.errorText(r) };
    });
  };

  /**
   * What to do with a saved item after one try: "done"; "wait" (no signal, signed out, page out of date: the next
   * sync tries again); "retry" (Dispatch failed: up to MAX_TRIES); "refused" (kept aside and shown, never dropped).
   */
  var MAX_TRIES = 5;
  function queueVerdict(r, tries) {
    if (r.ok) return "done";
    if (r.signedOut || r.offline || r.status === 0) return "wait";
    if (r.status === 403 && r.data && r.data.code === "CSRF") return "wait";
    if (r.status === 409 && r.data && r.data.code === "IDEMPOTENCY_IN_PROGRESS") return "wait";
    if (r.status >= 500 || r.status === 429) return (tries || 0) + 1 < MAX_TRIES ? "retry" : "refused";
    return "refused";
  }

  function park(item, r) {
    var error = SWD.errorText(r);
    return store("parked", "readwrite", function (s) {
      s.add({ driver: item.driver != null ? item.driver : DRIVER, label: item.label || item.path, path: item.path, status: r.status, error: error, at: Date.now() });
    }).then(function () {
      SWD.toast("Dispatch did not accept an update saved on this phone — see the note at the top.");
      SWD.call("POST", "problems", {
        kind: "other",
        text: ("Saved offline, refused when sent: " + (item.label || item.path) + " — " + error).slice(0, 2000),
        device: device(),
        client_time: isoNow(),
      });
    }, function () { SWD.toast("Dispatch did not accept an update saved on this phone (" + error + "). Call Dispatch."); });
  }

  /** Replays this driver's queue in order; stops at the first item that has to wait. */
  var syncing = false;
  SWD.sync = function () {
    if (syncing) return Promise.resolve();
    syncing = true;
    return mineOf("queue").then(function (items) {
      var chain = Promise.resolve(true);
      items.forEach(function (item) {
        chain = chain.then(function (go) {
          if (!go) return false;
          var body = item.kind === "photo" ? photoForm(item.fields, item.blob) : item.body;
          return SWD.call(item.method, item.path, body, { key: item.key, timeout: item.kind === "photo" ? 60000 : 15000 }).then(function (r) {
            var verdict = queueVerdict(r, item.tries);
            if (verdict === "wait") return false;
            if (verdict === "retry") {
              item.tries = (item.tries || 0) + 1;
              return store("queue", "readwrite", function (s) { s.put(item); }).then(function () { return false; });
            }
            var removed = store("queue", "readwrite", function (s) { return s.delete(item.seq); });
            return (verdict === "done" ? removed : removed.then(function () { return park(item, r); })).then(function () { return true; });
          });
        });
      });
      return chain;
    }).then(function () { syncing = false; return refreshBanner(); }, function () { syncing = false; });
  };

  function device() {
    return { model: navigator.userAgent.slice(0, 120), os: (navigator.platform || "").slice(0, 60), app_version: APP_VERSION };
  }
  SWD.device = device;

  // ---------------------------------------------------------------- GPS (POST /positions, docs/driver-api.md section 6)
  // While the app is open: a position on every 15 s / 25 m move and at least once a minute standing still
  // (Dispatch counts a driver online while a position is under 2 minutes old). Positions that could not be sent
  // are kept and uploaded later in batches of up to 500.
  var HEARTBEAT = 60000, MIN_GAP = 15000, MIN_MOVE = 25, GPS_STALE = 120000, GPS_LOST = 300000;
  var lastFix = null, lastSent = null, lastSentAt = 0, gpsError = null, gpsStartedAt = 0, watchId = null, wakeLock = null;
  try { lastFix = JSON.parse(localStorage.getItem("swd-last-fix") || "null"); } catch (e) { lastFix = null; }
  if (lastFix && String(lastFix.driver) !== DRIVER) lastFix = null;
  // GPS tester (test servers only): while a test location is on, the phone's real fixes are ignored. Only while the
  // tester is on this server: a flag left on a phone after the tester was switched off must not block real GPS.
  var fakeGps = false;
  try { fakeGps = !!document.querySelector("[data-gps-tester-overlay]") && localStorage.getItem("swd-fake-gps") === "1"; } catch (e) { fakeGps = false; }

  SWD.gps = function () { return lastFix && Date.now() - lastFix.at < GPS_STALE ? { lat: lastFix.lat, lng: lastFix.lng, accuracy: lastFix.accuracy } : null; };

  function metres(a, b) {
    var R = 6371000, k = Math.PI / 180;
    var dLat = (b.lat - a.lat) * k, dLng = (b.lng - a.lng) * k;
    var h = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(a.lat * k) * Math.cos(b.lat * k) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return 2 * R * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
  }

  function positionOf(fix, at) {
    var p = { lat: Math.round(fix.lat * 1e7) / 1e7, lng: Math.round(fix.lng * 1e7) / 1e7, recorded_at: isoNow(new Date(at)) };
    if (fix.accuracy != null && isFinite(fix.accuracy)) p.accuracy = Math.round(fix.accuracy * 10) / 10;
    return p;
  }

  function sendPosition(fix, at) {
    lastSent = fix;
    lastSentAt = Date.now();
    var p = positionOf(fix, at);
    return SWD.call("POST", "positions", { positions: [p] }).then(function (r) {
      if (r.ok) { flushPositions(); return r; }
      if (r.offline || r.status >= 500 || r.status === 0) {
        store("positions", "readwrite", function (s) { s.add({ driver: DRIVER, p: p }); }).catch(function () {});
      }
      return r;
    });
  }

  function onFix(pos) {
    if (fakeGps) return;
    lastFix = { lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: pos.coords.accuracy, at: pos.timestamp || Date.now(), driver: DRIVER };
    try { localStorage.setItem("swd-last-fix", JSON.stringify(lastFix)); } catch (e) { /* storage full or blocked */ }
    gpsError = null;
    updateGpsUi();
    if (lastSent && Date.now() - lastSentAt < HEARTBEAT && (Date.now() - lastSentAt < MIN_GAP || metres(lastSent, lastFix) < MIN_MOVE)) return;
    sendPosition(lastFix, lastFix.at);
  }
  // The heartbeat: at least one position a minute, the latest fix again when standing still.
  setInterval(function () {
    // A test location stays "where the driver is" until real GPS is switched back on: keep it fresh.
    if (fakeGps && lastFix && Date.now() - lastSentAt >= HEARTBEAT) lastFix.at = Date.now();
    var f = SWD.gps();
    if (f && Date.now() - lastSentAt >= HEARTBEAT) sendPosition(f, Date.now());
  }, 15000);

  var flushing = false;
  function flushPositions() {
    if (flushing) return;
    flushing = true;
    mineOf("positions").then(function (all) {
      var batch = all.slice(0, 500);
      if (!batch.length) { flushing = false; return; }
      return SWD.call("POST", "positions", { positions: batch.map(function (x) { return x.p; }) }).then(function (r) {
        // 422: a batch the server will never take is dropped, not kept forever.
        if (!r.ok && r.status !== 422) { flushing = false; return; }
        return store("positions", "readwrite", function (s) { batch.forEach(function (x) { s.delete(x.seq); }); }).then(function () {
          flushing = false;
          if (all.length > batch.length) flushPositions();
        });
      });
    }).catch(function () { flushing = false; });
  }

  // Browsers stop GPS when the screen goes off: keep the screen on while a route page is open.
  function keepAwake() {
    if (document.hidden || !navigator.wakeLock || wakeLock) return;
    navigator.wakeLock.request("screen").then(function (l) { wakeLock = l; l.addEventListener("release", function () { wakeLock = null; }); }, function () {});
  }
  function startGps() {
    if (navigator.geolocation && watchId === null && !document.hidden) {
      gpsStartedAt = Date.now();
      watchId = navigator.geolocation.watchPosition(onFix, function (err) {
        if (fakeGps) return; // the GPS tester's location stands in for the phone's: its errors don't apply
        gpsError = err.code === err.PERMISSION_DENIED ? "denied" : err.code === err.POSITION_UNAVAILABLE ? "unavailable" : "timeout";
        updateGpsUi();
        if (err.code === err.PERMISSION_DENIED) SWD.toast("Allow location so Dispatch can see where you are.");
      }, { enableHighAccuracy: true, maximumAge: 10000, timeout: 30000 });
    }
    keepAwake();
  }
  function stopGps() {
    if (watchId !== null) { navigator.geolocation.clearWatch(watchId); watchId = null; }
    if (wakeLock) { wakeLock.release().catch(function () {}); wakeLock = null; }
  }
  startGps();
  document.addEventListener("visibilitychange", function () {
    if (document.hidden) stopGps(); else { startGps(); SWD.sync(); }
  });

  function gpsState() {
    if (fakeGps && lastFix) return { level: "ok", reason: null, age: Math.max(0, Date.now() - lastFix.at) };
    if (!navigator.geolocation) return { level: "lost", reason: "unsupported" };
    var age = lastFix ? Math.max(0, Date.now() - lastFix.at) : null;
    return { level: age === null || age > GPS_LOST ? "lost" : age > GPS_STALE ? "stale" : "ok", reason: gpsError, age: age };
  }
  function ago(ms) {
    var s = Math.max(0, Math.round((Date.now() - ms) / 1000));
    return s < 60 ? s + "s ago" : Math.round(s / 60) + " min ago";
  }
  var GPS_HINT = "Turn on Location, allow this app to use it, and keep the phone where it can see the sky.";
  function gpsText() {
    var st = gpsState();
    if (st.reason === "unsupported") return { value: "No", cls: "is-down", meta: "This browser can't share its location.", st: st };
    if (st.reason === "denied") return { value: "Off", cls: "is-down", meta: "Location is blocked for this app. Allow location for this site in the browser settings, then reopen the app.", st: st };
    var since = lastFix ? "Last fix " + ago(lastFix.at) : "No fix yet";
    if (st.level === "ok") return { value: "Yes", cls: "", meta: since, st: st };
    return st.level === "stale"
      ? { value: "Not updating", cls: "is-warn", meta: since + ". " + GPS_HINT, st: st }
      : { value: "No GPS", cls: "is-down", meta: since + ". " + GPS_HINT, st: st };
  }

  // ---------------------------------------------------------------- connectivity + report a problem
  var net = document.querySelector("[data-network-status-overlay]");
  function setNet(name, value, meta, cls) {
    if (!net) return;
    var v = net.querySelector('[data-net-value="' + name + '"]');
    if (v) {
      v.textContent = value;
      if (cls !== undefined) { v.classList.toggle("is-warn", cls === "is-warn"); v.classList.toggle("is-down", cls === "is-down"); }
    }
    var mt = net.querySelector('[data-net-meta="' + name + '"]');
    if (mt && meta !== undefined) mt.textContent = meta;
  }
  function updateGpsUi() {
    var t = gpsText();
    if (net && !net.hidden) setNet("gps", t.value, t.meta, t.cls);
    // The warning under the title, not in the first seconds of a watch that has not had time to get a fix.
    var show = t.st.level !== "ok" && (t.st.reason !== null || Date.now() - gpsStartedAt > 20000);
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

  function checkNet() {
    setNet("internet", navigator.onLine ? "Yes" : "No", undefined, navigator.onLine ? "" : "is-down");
    var g = gpsText();
    setNet("gps", g.value, g.meta, g.cls);
    refreshBanner();
    var started = Date.now();
    return SWD.call("GET", "me", null, { timeout: 10000 }).then(function (r) {
      setNet("dispatch", r.ok ? "Yes" : "No", r.ok ? "Answered in " + (Date.now() - started) + " ms" : SWD.errorText(r), r.ok ? "" : "is-down");
      net.querySelector("[data-network-status-warning]").hidden = r.ok && navigator.onLine;
    });
  }
  document.querySelectorAll("[data-network-status-open]").forEach(function (b) {
    b.addEventListener("click", function () {
      closeDrawerNow();
      if (!net) return;
      net.hidden = false;
      net.classList.add("open");
      checkNet();
    });
  });
  if (net) {
    var submit = net.querySelector("[data-problem-submit]");
    submit.addEventListener("click", function () {
      var text = net.querySelector("[data-problem-text]");
      if (!text.value.trim()) { SWD.toast("Write a few words about the problem."); return; }
      submit.disabled = true;
      SWD.write("POST", "problems", {
        kind: net.querySelector("[data-problem-kind]").value,
        text: text.value.trim(),
        device: device(),
      }, { label: "Problem report" }).then(function (r) {
        submit.disabled = false;
        if (r.ok || r.queued) { text.value = ""; net.querySelector("[data-problem-sent]").hidden = false; }
        else SWD.toast(r.error);
      });
    });
  }

  // ---------------------------------------------------------------- messages (GET /messages; a tap confirms it was read)
  var messages = [];
  var overlay = document.querySelector("[data-messages-overlay]");
  var list = overlay ? overlay.querySelector("[data-messages-list]") : null;

  function badge(unread) {
    document.querySelectorAll("[data-messages-badge]").forEach(function (b) { b.hidden = unread === 0; b.textContent = unread > 9 ? "9+" : String(unread); });
  }
  function timeLabel(iso) {
    var d = new Date(iso);
    if (isNaN(d)) return "";
    return d.toLocaleString([], { month: "short", day: "numeric", hour: "numeric", minute: "2-digit" });
  }
  function renderMessages() {
    if (!list) return;
    if (!messages.length) { list.innerHTML = '<p class="empty">No messages.</p>'; return; }
    list.innerHTML = messages.map(function (m) {
      var unread = !m.read_at;
      return '<div class="message-row' + (unread ? " is-unread" : "") + '" data-id="' + m.id + '" role="button" tabindex="0">' +
        '<div class="message-text">' + esc(m.text) + "</div>" +
        '<div class="message-meta">' + (m.from === "system" ? "Schedule change" : "Dispatch") + " · " + esc(timeLabel(m.created_at)) +
        (unread ? " · tap to confirm you read it" : " · read") + "</div></div>";
    }).join("");
  }
  if (list) {
    list.addEventListener("click", function (e) {
      var row = e.target.closest(".message-row");
      if (!row) return;
      var id = parseInt(row.getAttribute("data-id"), 10);
      var m = messages.filter(function (x) { return x.id === id; })[0];
      if (!m || m.read_at) return;
      m.read_at = isoNow();
      renderMessages();
      badge(messages.filter(function (x) { return !x.read_at; }).length);
      SWD.write("POST", "messages/" + id + "/read", {}, { label: "Message read" });
    });
  }
  var lastUnread = null;
  function loadMessages() {
    return SWD.call("GET", "messages", null, { timeout: 10000 }).then(function (r) {
      if (!r.ok || !r.data || !r.data.messages) return;
      messages = r.data.messages;
      var unread = typeof r.data.unread === "number" ? r.data.unread : messages.filter(function (m) { return !m.read_at; }).length;
      badge(unread);
      renderMessages();
      // A new schedule change or cancellation: the page refreshes so the driver sees it (spec: "they just refresh").
      if (lastUnread !== null && unread > lastUnread) { SWD.toast("New message from Dispatch."); liveRefresh(true); }
      lastUnread = unread;
    });
  }
  document.querySelectorAll("[data-messages-open]").forEach(function (b) {
    b.addEventListener("click", function () { closeDrawerNow(); if (overlay) { slideOpen(overlay); renderMessages(); loadMessages(); } });
  });
  if (overlay) {
    overlay.querySelector("[data-messages-close]").addEventListener("click", function () { slideClose(overlay); });
    loadMessages();
    setInterval(function () { if (!document.hidden && navigator.onLine) loadMessages(); }, 30000);
  }

  // ---------------------------------------------------------------- live refresh
  // Upcoming / Completed / Destination Details re-read their page every minute (and after a new message) and swap
  // the content in place, unless the driver is in the middle of something.
  function liveHeld() {
    if (pendingCount > 0) return true;
    if (document.querySelector(".confirm-overlay:not([hidden]), [data-messages-overlay]:not([hidden]), [data-sidebar-overlay]:not([hidden])")) return true;
    var active = document.activeElement;
    return !!(active && /^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName)) || (typeof SWD.holdLive === "function" && !!SWD.holdLive());
  }
  function swapContent(url) {
    return fetch(url, { credentials: "same-origin", headers: { Accept: "text/html" } }).then(function (res) {
      if (!res.ok || (res.redirected && /\/login(\?|$)/.test(res.url))) return false;
      return res.text().then(function (html) {
        var doc = new DOMParser().parseFromString(html, "text/html");
        var next = doc.querySelector(".app-content"), here = document.querySelector(".app-content");
        if (!next || !here) return false;
        var keep = here.querySelectorAll("[data-offline-banner], [data-gps-stale]");
        next.querySelectorAll("[data-offline-banner], [data-gps-stale]").forEach(function (n) { n.remove(); });
        // The page scrolls inside the phone frame's .app-screen (and the window on old layouts): keep both.
        var scroller = document.querySelector(".app-screen");
        var y = window.scrollY, sy = scroller ? scroller.scrollTop : 0;
        here.textContent = "";
        keep.forEach(function (n) { here.appendChild(n); });
        Array.prototype.slice.call(next.childNodes).forEach(function (n) { here.appendChild(document.adoptNode(n)); });
        window.scrollTo(0, y);
        if (scroller) scroller.scrollTop = sy;
        document.dispatchEvent(new CustomEvent("swd:content"));
        refreshBanner();
        return true;
      });
    }).catch(function () { return false; });
  }
  SWD.refreshContent = function () { return swapContent(window.location.href); };
  var LIVE = /^\/driver\/(tasks|completed|stops\/stp_[A-Za-z0-9_-]+)\/?$/.test(window.location.pathname);
  function liveRefresh(force) {
    if (!LIVE || document.hidden || !navigator.onLine || liveHeld()) return;
    swapContent(window.location.href);
  }
  if (LIVE) setInterval(function () { liveRefresh(false); }, 60000);

  // ---------------------------------------------------------------- GPS tester (test servers only, DRIVER_GPS_TESTER=1)
  var tester = document.querySelector("[data-gps-tester-overlay]");
  if (tester) {
    var lastPing = function () {
      try { return JSON.parse(localStorage.getItem("swd-last-ping") || "null"); } catch (e) { return null; }
    };
    var q = function (sel) { return tester.querySelector(sel); };
    var showTester = function () {
      var p = lastPing();
      q("[data-gps-tester-last]").textContent = p ? p.lat.toFixed(6) + ", " + p.lng.toFixed(6) : "None yet";
      q("[data-gps-tester-ago]").textContent = p ? ago(p.at) : "";
      q("[data-gps-tester-mode]").textContent = fakeGps ? "Using a test location — real GPS is ignored until you tap Use real GPS." : "Using real GPS.";
    };
    // Sends the location now (no 15 s / 25 m throttle) and keeps it as the phone's fix until real GPS is back on.
    var report = function (lat, lng) {
      if (!isFinite(lat) || !isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) { SWD.toast("Enter a valid latitude and longitude."); return; }
      fakeGps = true;
      try { localStorage.setItem("swd-fake-gps", "1"); } catch (e) { /* this page only */ }
      lastFix = { lat: lat, lng: lng, accuracy: 5, at: Date.now(), driver: DRIVER };
      try { localStorage.setItem("swd-last-fix", JSON.stringify(lastFix)); } catch (e) { /* ignore */ }
      gpsError = null;
      updateGpsUi();
      sendPosition(lastFix, lastFix.at).then(function (r) {
        if (r && r.ok) {
          try { localStorage.setItem("swd-last-ping", JSON.stringify({ lat: lat, lng: lng, at: Date.now() })); } catch (e) { /* ignore */ }
          SWD.toast("Sent " + lat.toFixed(6) + ", " + lng.toFixed(6));
        } else {
          SWD.toast(r && (r.offline || r.status >= 500) ? "No connection — kept and sent later." : SWD.errorText(r));
        }
        showTester();
      });
    };
    var from = function () {
      var p = lastPing() || lastFix;
      if (p) return p;
      var v = q("[data-gps-tester-place]").value.split(",");
      return { lat: parseFloat(v[0]), lng: parseFloat(v[1]) };
    };
    // "Test location: stop": today's stops and the warehouse, from GET routes/today.
    var loadStops = function () {
      var sel = q("[data-gps-test-stop]");
      SWD.call("GET", "routes/today").then(function (r) {
        while (sel.options.length > 1) sel.remove(1);
        if (!r.ok || !r.data) { sel.options[0].text = "Couldn't load your stops"; return; }
        var n = 0;
        (r.data.routes || []).forEach(function (route) {
          var wh = route.warehouse;
          if (wh && wh.latitude != null && wh.longitude != null) { sel.add(new Option(route.name + " — Warehouse (" + wh.name + ")", wh.latitude + "," + wh.longitude)); n++; }
          (route.stops || []).forEach(function (s) {
            if (s.latitude == null || s.longitude == null) return;
            var name = s.kind === "warehouse" ? "Warehouse" : (s.ship_to && s.ship_to.company_name) || (s.customer && s.customer.name) || "Stop";
            sel.add(new Option("#" + s.position + " " + name, s.latitude + "," + s.longitude));
            n++;
          });
        });
        sel.options[0].text = n ? "Choose a stop…" : "No stop with a position today";
      });
    };

    document.querySelectorAll("[data-gps-tester-open]").forEach(function (b) {
      b.addEventListener("click", function () {
        closeDrawerNow();
        tester.hidden = false;
        tester.classList.add("open");
        showTester();
        loadStops();
      });
    });
    // "Test location: zone": a fixed list of zones across Canada (templates/driver/_gps_zones.html.twig).
    var zone = q("[data-gps-test-zone]");
    if (zone) zone.addEventListener("change", function (e) {
      if (!e.target.value) return;
      var v = e.target.value.split(",");
      report(parseFloat(v[0]), parseFloat(v[1]));
    });
    q("[data-gps-test-stop]").addEventListener("change", function (e) {
      if (!e.target.value) return;
      var v = e.target.value.split(",");
      report(parseFloat(v[0]), parseFloat(v[1]));
    });
    q("[data-gps-tester-place-go]").addEventListener("click", function () {
      var v = q("[data-gps-tester-place]").value.split(",");
      report(parseFloat(v[0]), parseFloat(v[1]));
    });
    // Move: the chosen distance (number × m or km) in the chosen direction from the last location.
    q("[data-gps-tester-move]").addEventListener("click", function () {
      var meters = parseFloat(q("[data-gps-tester-distance]").value) * parseFloat(q("[data-gps-tester-unit]").value);
      var p = from(), d = meters / 111320; // metres in degrees of latitude
      var lat = p.lat, lng = p.lng, dir = q("[data-gps-tester-direction]").value;
      if (dir === "north") lat += d;
      if (dir === "south") lat -= d;
      if (dir === "east") lng += d / Math.cos(lat * Math.PI / 180);
      if (dir === "west") lng -= d / Math.cos(lat * Math.PI / 180);
      report(lat, lng);
    });
    q("[data-gps-tester-custom-go]").addEventListener("click", function () {
      report(parseFloat(q("[data-gps-tester-lat]").value), parseFloat(q("[data-gps-tester-lng]").value));
    });
    q("[data-gps-tester-real]").addEventListener("click", function () {
      fakeGps = false;
      try { localStorage.removeItem("swd-fake-gps"); } catch (e) { /* ignore */ }
      stopGps();
      startGps();
      SWD.toast("Back on real GPS.");
      showTester();
    });
  }

  // ---------------------------------------------------------------- logout (shared phones)
  document.querySelectorAll("[data-logout]").forEach(function (a) {
    a.addEventListener("click", function (e) {
      if (pendingCount > 0 && !window.confirm(pendingCount + " update" + (pendingCount === 1 ? " is" : "s are") +
          " saved on this phone and not yet with Dispatch. They will be sent when you log in again on this phone. Log out anyway?")) {
        e.preventDefault();
        return;
      }
      try { ["swd-last-fix", "swd-last-ping", "swd-fake-gps"].forEach(function (k) { localStorage.removeItem(k); }); sessionStorage.clear(); } catch (err) { /* ignore */ }
    });
  });

  // ---------------------------------------------------------------- live list (D-116)
  // A page that shows Dispatch's list (Upcoming Destinations) asks for itself again every 45 seconds and when the app comes back to the
  // front, and redraws the list when Dispatch's answer changed: a cancelled or moved destination leaves the screen without a reload.
  var live = document.querySelector("[data-live-refresh]");
  if (live) {
    var checking = false;
    var normal = function (html) { return html.replace(/\s+/g, " ").trim(); };
    var recheck = function () {
      if (checking || document.hidden || !navigator.onLine) return;
      checking = true;
      fetch(location.href, { credentials: "same-origin", cache: "no-store", headers: { Accept: "text/html" } }).then(function (res) {
        if (!res.ok || res.redirected) return null; // signed out or an error page: keep what is on screen
        return res.text();
      }).then(function (html) {
        if (!html) return;
        var fresh = new DOMParser().parseFromString(html, "text/html").querySelector("[data-live-refresh]");
        if (fresh && normal(fresh.innerHTML) !== normal(live.innerHTML)) live.innerHTML = fresh.innerHTML;
      }).catch(function () { /* no signal: the next check tries again */ }).then(function () { checking = false; });
    };
    setInterval(recheck, 45000);
    document.addEventListener("visibilitychange", function () { if (!document.hidden) recheck(); });
  }

  // ---------------------------------------------------------------- start-up
  window.addEventListener("online", function () { SWD.sync(); flushPositions(); });
  SWD.sync();
  flushPositions();
  setInterval(function () { if (navigator.onLine) { SWD.sync(); flushPositions(); } }, 30000);

  if ("serviceWorker" in navigator) {
    navigator.serviceWorker.register((SWD.urls.base || "/driver") + "/sw.js", { scope: (SWD.urls.base || "/driver") + "/" }).catch(function () {});
  }
})();
