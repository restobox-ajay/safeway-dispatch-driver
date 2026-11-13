/* =========================================================================
   Safeway Tire Driver — the taps and forms of the driver pages (needs app.js: window.SWD).
   Follows safeway-dispatch's Driver API (docs/driver-api.md) and its PWA (public/driver/complete.js, photos.js):
     - route Start / Finish                POST routes/{ref}/start|finish
     - On My Way / Arrived / Undo          POST stops/{id}/on-my-way|arrived|undo (one stop on the way at a time)
     - Add Note                            POST stops/{id}/notes {reason_code?, text}
     - Mark Complete                       POST photos (two copies per photo), POST stops/{id}/complete
     - Work Hours                          POST hours {event}
   ========================================================================= */
(function () {
  "use strict";
  var SWD = window.SWD;
  if (!SWD) return;

  var busy = false;
  SWD.holdLive = function () { return busy || !!document.querySelector("[data-complete-form], [data-note-form]"); };

  function showError(form, msg) {
    var box = form.querySelector("[data-form-error], [data-tap-error]");
    if (!box) { if (msg) SWD.toast(msg); return; }
    box.textContent = msg || "";
    box.hidden = !msg;
    if (msg) box.scrollIntoView({ block: "center" });
  }

  // ---------------------------------------------------------------- route Start / Finish, stop steps
  document.addEventListener("click", function (e) {
    var rb = e.target.closest ? e.target.closest("[data-route-tap]") : null;
    if (rb) { routeTap(rb); return; }
    var sb = e.target.closest ? e.target.closest("[data-stop-tap]") : null;
    if (sb) stopTap(sb);
  });

  function routeTap(btn) {
    if (busy) return;
    var tap = btn.getAttribute("data-route-tap");
    var ref = btn.getAttribute("data-route-ref");
    busy = true;
    btn.disabled = true;
    SWD.write("POST", "routes/" + ref + "/" + tap, {}, { stampTime: false, label: (tap === "start" ? "Start " : "Finish ") + ref }).then(function (r) {
      busy = false;
      btn.disabled = false;
      if (r.ok) { SWD.toast(tap === "start" ? "Route started." : "Route finished."); SWD.refreshContent(); return; }
      SWD.toast(r.queued ? r.error : r.error || "Not accepted.");
    });
  }

  function stopTap(btn) {
    if (busy) return;
    var card = btn.closest("[data-stop-id]");
    var id = card.getAttribute("data-stop-id");
    var tap = btn.getAttribute("data-stop-tap");
    var labels = { "on-my-way": "On my way", arrived: "Arrived", undo: "Undo" };
    busy = true;
    btn.disabled = true;
    showError(card, "");
    SWD.write("POST", "stops/" + id + "/" + tap, {}, { stampTime: tap !== "undo", label: labels[tap] + " (" + id + ")" }).then(function (r) {
      busy = false;
      btn.disabled = false;
      if (r.ok) { SWD.toast(labels[tap] + " sent."); SWD.refreshContent(); return; }
      if (r.queued) { SWD.toast(r.error); return; }
      // Only one stop at a time may be "on my way": offer to take the other one back, then try again.
      var other = r.data && r.data.on_my_way_stop_id;
      if (r.status === 409 && other && other !== id && window.confirm(r.error + "\n\nTake that stop back and go to this one instead?")) {
        busy = true;
        SWD.write("POST", "stops/" + other + "/undo", {}, { stampTime: false, queue: false }).then(function (u) {
          busy = false;
          if (!u.ok) { showError(card, u.error); return; }
          stopTap(btn);
        });
        return;
      }
      showError(card, r.error);
    });
  }

  // ---------------------------------------------------------------- Add Note
  document.querySelectorAll("[data-note-form]").forEach(function (form) {
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      if (busy) return;
      var text = form.querySelector("[data-note-text]").value.trim();
      var reason = form.querySelector("[data-note-reason]").value;
      var msg = !reason && !text ? "Write the note, or choose what happened." : reason === "other" && !text ? "Describe what happened (required for “Other issue”)." : "";
      showError(form, msg);
      if (msg) return;
      busy = true;
      var body = { text: text || null, reason_code: reason || null };
      if (!text) delete body.text;
      SWD.write("POST", "stops/" + form.getAttribute("data-stop-id") + "/notes", body, { stampTime: false, label: "Note" }).then(function (r) {
        busy = false;
        if (r.ok || r.queued) { SWD.toast(r.ok ? "Note saved." : r.error); window.location.href = form.getAttribute("data-back"); return; }
        showError(form, r.error);
      });
    });
  });

  // ---------------------------------------------------------------- Work Hours
  var hours = document.querySelector("[data-hours]");
  if (hours) {
    var KEY = "swd-hours-" + SWD.driverId;
    var LABELS = { start: "Start work", break_start: "Start break", break_end: "End break", end: "End work" };
    var last = null;
    try { last = JSON.parse(localStorage.getItem(KEY) || "null"); } catch (e) { last = null; }
    var allowed = function () {
      var ev = last && last.event;
      if (!ev || ev === "end") return ["start"];
      if (ev === "start" || ev === "break_end") return ["break_start", "end"];
      return ["break_end"];
    };
    var draw = function () {
      var ok = allowed();
      hours.querySelectorAll("[data-hours-event]").forEach(function (b) { b.disabled = ok.indexOf(b.getAttribute("data-hours-event")) === -1; });
      hours.querySelector("[data-hours-last]").textContent = last ? "Last: " + LABELS[last.event] + " at " + new Date(last.at).toLocaleTimeString([], { hour: "numeric", minute: "2-digit" }) : "";
    };
    hours.addEventListener("click", function (e) {
      var b = e.target.closest("[data-hours-event]");
      if (!b || busy) return;
      var ev = b.getAttribute("data-hours-event");
      busy = true;
      SWD.write("POST", "hours", { event: ev }, { label: LABELS[ev] }).then(function (r) {
        busy = false;
        // 409 on "event": the server's order differs from this phone's memory (e.g. another phone) — take its word.
        if (r.ok || r.queued || r.status === 409) {
          if (r.status === 409) { SWD.toast(r.error); }
          else { SWD.toast(LABELS[ev] + (r.ok ? " saved." : " — saved on this phone.")); }
          last = { event: ev, at: Date.now() };
          try { localStorage.setItem(KEY, JSON.stringify(last)); } catch (err) { /* this visit only */ }
          draw();
          return;
        }
        SWD.toast(r.error);
      });
    });
    draw();
  }

  // ---------------------------------------------------------------- photos: two copies (docs/driver-api.md 5.1)
  var EMERGENCY_MAX = 100 * 1024;
  function goodSettings() {
    var c = navigator.connection;
    var slow = !!c && (["slow-2g", "2g", "3g"].indexOf(c.effectiveType) !== -1 || c.saveData === true);
    return slow ? { edge: 1024, quality: 0.6 } : { edge: 1280, quality: 0.72 };
  }
  function decode(file) {
    return createImageBitmap(file, { imageOrientation: "from-image" }).catch(function () { return createImageBitmap(file); });
  }
  function encode(bmp, edge, quality) {
    var scale = Math.min(1, edge / Math.max(bmp.width, bmp.height));
    var canvas = document.createElement("canvas");
    canvas.width = Math.max(1, Math.round(bmp.width * scale));
    canvas.height = Math.max(1, Math.round(bmp.height * scale));
    canvas.getContext("2d").drawImage(bmp, 0, 0, canvas.width, canvas.height);
    return new Promise(function (resolve) {
      canvas.toBlob(function (blob) { resolve(blob ? { blob: blob, width: canvas.width, height: canvas.height } : null); }, "image/jpeg", quality);
    });
  }
  /** At most 100 KB: 800 px, JPEG quality 0.7 down to 0.3 until it fits; still too big: 640 px and again. */
  function emergency(bmp) {
    var tries = [];
    [800, 640].forEach(function (edge) { [0.7, 0.6, 0.5, 0.4, 0.3].forEach(function (q) { tries.push([edge, q]); }); });
    var last = null;
    var step = function (i) {
      if (i >= tries.length) return Promise.resolve(last);
      return encode(bmp, tries[i][0], tries[i][1]).then(function (r) {
        if (r) last = r;
        return r && r.blob.size <= EMERGENCY_MAX ? r : step(i + 1);
      });
    };
    return step(0);
  }
  function makeTiers(file) {
    var bmp = null;
    return decode(file).then(function (b) {
      bmp = b;
      return emergency(bmp);
    }).then(function (em) {
      var g = goodSettings();
      return encode(bmp, g.edge, g.quality).then(function (good) {
        if (bmp.close) bmp.close();
        return { emergency: em || { blob: file, width: 0, height: 0 }, good: good && em && good.width * good.height > em.width * em.height ? good : null };
      });
    }).catch(function () {
      if (bmp && bmp.close) bmp.close();
      return { emergency: { blob: file, width: 0, height: 0 }, good: null }; // cannot decode: the original goes as the only copy
    });
  }

  // ---------------------------------------------------------------- Mark Complete
  var SUCCESS = ["delivered", "picked_up", "collected", "done"];
  var FAILED = ["rejected", "wrong_item", "not_delivered", "nothing_to_pick_up", "not_picked_up", "not_collected", "not_done"];
  var MAX_PHOTOS = 10;

  document.querySelectorAll("[data-complete-form]").forEach(function (form) {
    var stopId = form.getAttribute("data-stop-id");
    var reload = form.getAttribute("data-reload") === "1";
    var photos = [];
    var strip = form.querySelector("[data-photo-strip]");
    var input = form.querySelector("[data-photo-input]");

    function outcomeOf(block) { var c = block.querySelector("[data-outcome]:checked"); return c ? c.value : ""; }
    function sync(block) {
      var o = outcomeOf(block);
      var reasonBox = block.querySelector("[data-reason-box]");
      if (reasonBox) reasonBox.hidden = SUCCESS.indexOf(o) !== -1;
      var qty = block.querySelector("[data-qty-box]");
      if (qty) qty.hidden = !(o === "partially_delivered" || o === "picked_up_with_problem");
      var pay = block.querySelector("[data-pay-box]");
      if (pay) pay.hidden = o === "not_collected";
      var method = block.querySelector("[data-method]");
      var cheque = block.querySelector("[data-cheque-box]");
      if (cheque) cheque.hidden = !(method && method.value === "cheque");
      var note = block.querySelector("[data-note]");
      var reason = block.querySelector("[data-reason]");
      if (note) note.closest(".form-group").querySelector("label").textContent = reason && reason.value === "other" && !reasonBox.hidden ? "Describe the issue (required)" : "Add Note (Optional)";
    }
    var blocks = Array.prototype.slice.call(form.querySelectorAll(".task-block"));
    blocks.forEach(function (b) { b.addEventListener("change", function () { sync(b); }); sync(b); });

    function drawPhotos() {
      if (!strip) return;
      strip.innerHTML = "";
      photos.forEach(function (p, i) {
        var t = document.createElement("div");
        t.className = "thumb";
        var img = document.createElement("img");
        img.src = p.url;
        img.alt = "Photo " + (i + 1);
        var x = document.createElement("button");
        x.type = "button";
        x.className = "thumb-x";
        x.setAttribute("aria-label", "Remove photo " + (i + 1));
        x.textContent = "×";
        x.addEventListener("click", function () { URL.revokeObjectURL(p.url); photos.splice(i, 1); drawPhotos(); });
        t.appendChild(img);
        t.appendChild(x);
        strip.appendChild(t);
      });
    }
    if (input) {
      input.addEventListener("change", function () {
        var file = input.files && input.files[0];
        input.value = "";
        if (!file) return;
        if (photos.length >= MAX_PHOTOS) { showError(form, "A stop can have at most " + MAX_PHOTOS + " photos."); return; }
        showError(form, "");
        makeTiers(file).then(function (t) {
          var clientId = ("ph-" + SWD.uuid()).replace(/[^A-Za-z0-9._:-]/g, "").slice(0, 40);
          photos.push({ clientId: clientId, emergency: t.emergency.blob, good: t.good ? t.good.blob : null, takenAt: SWD.isoNow(), url: URL.createObjectURL(t.emergency.blob) });
          drawPhotos();
        });
      });
    }

    function validate() {
      var allFailed = blocks.length > 0;
      for (var i = 0; i < blocks.length; i++) {
        var b = blocks[i], o = outcomeOf(b);
        var title = blocks.length > 1 ? b.querySelector(".task-block-title").textContent.replace(/ — Status Delivery:$/, "") + ": " : "";
        if (FAILED.indexOf(o) === -1) allFailed = false;
        var reason = b.querySelector("[data-reason]");
        if (SUCCESS.indexOf(o) === -1 && !(reason && reason.value)) return title + "choose a reason.";
        if (reason && reason.value === "other" && SUCCESS.indexOf(o) === -1 && !b.querySelector("[data-note]").value.trim()) return title + "describe what happened (the reason is “Other issue”).";
        if (o === "partially_delivered" || o === "picked_up_with_problem") {
          var short = false, total = 0;
          var lines = b.querySelectorAll("[data-line]");
          for (var j = 0; j < lines.length; j++) {
            var v = lines[j].value, of = Number(lines[j].getAttribute("data-of"));
            if (v === "" || isNaN(Number(v))) return title + "enter the quantity for every line.";
            if (Number(v) < 0 || Number(v) > of) return title + "a quantity must be between 0 and " + of + ".";
            if (Number(v) < of) short = true;
            total += Number(v);
          }
          if (o === "partially_delivered" && (!short || total === 0)) return title + (total === 0 ? "something must be delivered (otherwise choose Not delivered)." : "at least one line must be short (otherwise choose Complete).");
        }
        if (b.getAttribute("data-type") === "collect_payment" && o !== "not_collected") {
          var amount = b.querySelector("[data-amount]").value.trim(), method = b.querySelector("[data-method]");
          if (!/^\d+(\.\d{1,2})?$/.test(amount) || Number(amount) <= 0) return title + "enter the amount collected.";
          if (!method || !method.value) return title + "choose how it was paid.";
          if (method.value === "cheque" && !b.querySelector("[data-cheque]").value.trim()) return title + "enter the cheque number.";
        }
      }
      if (!reload && photos.length === 0 && !allFailed) return "Take at least one photo as proof (a stop where nothing was delivered may go without).";
      return "";
    }

    function outcomes() {
      var out = {};
      blocks.forEach(function (b) {
        var o = { outcome: outcomeOf(b) };
        var reason = b.querySelector("[data-reason]");
        if (reason && reason.value && SUCCESS.indexOf(o.outcome) === -1) o.reason_code = reason.value;
        var note = b.querySelector("[data-note]").value.trim();
        if (note) o.note = note;
        if (o.outcome === "partially_delivered" || o.outcome === "picked_up_with_problem") {
          o.items = {};
          b.querySelectorAll("[data-line]").forEach(function (l) { o.items[l.getAttribute("data-line")] = String(Number(l.value)); });
        }
        if (b.getAttribute("data-type") === "collect_payment" && o.outcome !== "not_collected") {
          var method = b.querySelector("[data-method]").value;
          o.collected = { amount: b.querySelector("[data-amount]").value.trim(), method: method };
          if (method === "cheque") o.collected.cheque_number = b.querySelector("[data-cheque]").value.trim();
        }
        out[b.getAttribute("data-task")] = o;
      });
      return out;
    }

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      if (busy) return;
      var problem = validate();
      showError(form, problem);
      if (problem) return;
      busy = true;
      var btn = form.querySelector("button[type=submit]");
      btn.disabled = true;
      btn.textContent = "Sending…";

      // 1. the small copy of every photo first (something arrives on a bad connection); queued when offline
      var uploads = photos.reduce(function (chain, p, i) {
        return chain.then(function () {
          return SWD.upload(p.emergency, { client_photo_id: p.clientId, taken_at: p.takenAt, tier: "emergency" }, { label: "Photo " + (i + 1) + " (" + stopId + ")" });
        });
      }, Promise.resolve());

      // 2. the stop, referencing the photos by their client id (the server matches them, uploaded before or after)
      uploads.then(function () {
        var body = { outcomes: outcomes(), photos: photos.map(function (p) { return p.clientId; }) };
        var rec = form.querySelector("[data-recipient]");
        if (rec && rec.value.trim()) body.recipient_name = rec.value.trim();
        var fix = SWD.gps();
        if (fix) body.location = { latitude: fix.lat, longitude: fix.lng };
        return SWD.write("POST", "stops/" + stopId + "/complete", body, { label: "Complete (" + stopId + ")" });
      }).then(function (r) {
        if (!r.ok && !r.queued) {
          busy = false;
          btn.disabled = false;
          btn.textContent = "Update";
          showError(form, r.error);
          return;
        }
        // 3. the good copies, as upgrades of the same client ids (queued too when offline)
        var goods = photos.filter(function (p) { return p.good; }).reduce(function (chain, p, i) {
          return chain.then(function () {
            return SWD.upload(p.good, { client_photo_id: p.clientId, taken_at: p.takenAt, tier: "good" }, { label: "Photo " + (i + 1) + " full size (" + stopId + ")" });
          });
        }, Promise.resolve());
        return goods.then(function () {
          SWD.toast(r.ok ? "Delivery updated." : "Saved on this phone. It will be sent when there is a connection.");
          busy = false;
          window.location.href = SWD.urls.tasks; // spec: once done, back to the main page
        });
      });
    });
  });
})();
