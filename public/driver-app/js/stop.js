/* =========================================================================
   Stop page (FEATURE-196…198) — the Android StopViewModel in the browser.
   Every tap goes through FVST.write (Idempotency-Key, client_time, GPS; kept
   on the phone when there's no signal).
   ========================================================================= */
(function () {
  "use strict";

  var FVST = window.FVST;

  // GitHub #7: the page is no longer reloaded. init() binds the stop shown; after a tap, or when Dispatch changed
  // this driver's route (app.js live refresh), the content is re-rendered in place and init() runs again on the new
  // content ("fvst:content"). Its body below keeps its old indentation, so the diff stays reviewable.
  function init() {
  FVST.holdLive = null; // the previous content's hold goes with it (set again at the end for this stop)
  var dataEl = document.getElementById("stop-data");
  if (!dataEl) return;
  var stop = JSON.parse(dataEl.textContent);
  var key = stop.stop_key;
  var firstRef = stop.orders.length ? stop.orders[0].reference : null;
  var busy = false;
  var savedLocally = false; // a status change saved on this phone: no second tap until the stop is shown again

  function itemsLabel(n) { return n + " item" + (n === 1 ? "" : "s"); }
  function stopUrl(k) { return (FVST.urls.base || "/driver") + "/stops/" + encodeURIComponent(k); }

  /**
   * After a tap: show the stop as the server has it now (Android load()) — re-rendered in place, not reloaded
   * (GitHub #7); offline, show the change locally.
   */
  function after(r, localStatus, onOk) {
    busy = false;
    if (r.ok) {
      var next = onOk ? onOk(r.data) : null;
      // A finished pickup can move to a new key (an order left behind leaves the stop): show that stop, not a
      // "no longer on your route" page (GitHub #16) — the address bar follows it (GitHub #7).
      var target = typeof next === "string" && next !== key ? stopUrl(next) : null;
      if (target) history.replaceState(history.state, "", target);
      busy = true; // no second tap on this content while the new one loads
      updateDelivered();
      FVST.refreshContent(target || window.location.href).then(function (done) {
        busy = false;
        // Couldn't fetch it in place (signal dropped just now): load it the old way.
        if (!done) { if (target) window.location.replace(target); else window.location.reload(); }
      });
      return;
    }
    var blocking = r.status === 409 && r.data && r.data.blocking_stop && lastTap;
    if (!blocking) FVST.toast(r.error); // GitHub #49: the banner says it instead
    if (r.queued && localStatus) {
      var stepper = document.querySelector(".status-stepper");
      if (stepper) stepper.insertAdjacentHTML("afterend", '<div class="offline-note" style="margin-top:8px;">⚠ Marked ' + FVST.esc(stop.labels[localStatus] || localStatus) + " on this phone — it'll send when you're back online.</div>");
      savedLocally = true;
      document.querySelectorAll("[data-advance], [data-undo], [data-mark-picked-up], [data-mark-delivered]").forEach(function (b) { b.disabled = true; });
    } else if (blocking) {
      showBlocker(r.data.blocking_stop, lastTap);
    } else if (r.status === 409) {
      // The stop changed meanwhile: show it as it is now, in place (GitHub #7).
      setTimeout(function () {
        FVST.refreshContent().then(function (done) { if (!done) window.location.reload(); });
      }, 1500);
    }
  }

  /**
   * GitHub #49: another stop is Picking Up / In Transit. A banner at the top of this stop names it, opens it in one
   * tap, or switches to this stop (that one steps back a step on the server, logged like an undo).
   */
  var lastTap = null;
  function showBlocker(b, retry) {
    var old = document.querySelector("[data-blocking-stop]");
    if (old) old.remove();
    var label = stop.labels[b.status] || b.status;
    var box = document.createElement("div");
    box.className = "alert alert-warning blocking-stop-banner";
    box.setAttribute("data-blocking-stop", "");
    box.setAttribute("role", "alert");
    box.innerHTML =
      "<div>You're still on <strong>" + FVST.esc(b.order_name || b.stop_key) + "</strong>" +
        (b.orders > 1 ? " (+" + (b.orders - 1) + ")" : "") + ", " + FVST.esc(b.address || "") + " — " + FVST.esc(label) + ".</div>" +
      '<div class="blocking-stop-actions">' +
        '<a class="btn btn-secondary btn-sm" href="' + stopUrl(b.stop_key) + '">Open that stop</a>' +
        '<button type="button" class="btn btn-primary btn-sm" data-switch-stop>Switch to this stop</button>' +
      "</div>" +
      '<div class="blocking-stop-hint">Switching puts that stop back to ' +
        FVST.esc(b.status === "in_transit" ? (stop.labels.picked_up || "Picked Up") : (stop.labels.assigned || "Assigned")) + ".</div>";
    dataEl.insertAdjacentElement("afterend", box);
    box.scrollIntoView({ block: "nearest" });
    box.querySelector("[data-switch-stop]").addEventListener("click", function () {
      box.remove();
      var body = {};
      for (var k in retry.body) body[k] = retry.body[k];
      body["switch"] = true;
      tap(retry.action, body, retry.localStatus, retry.onOk);
    });
  }

  function tap(action, body, localStatus, onOk) {
    if (busy) return;
    lastTap = { action: action, body: body || {}, localStatus: localStatus, onOk: onOk };
    busy = true;
    FVST.write("POST", "stops/" + key + "/" + action, body || {}, { gps: true, label: action + " " + key }).then(function (r) {
      after(r, localStatus, onOk);
    });
  }

  // ---------------------------------------------------------------- confirm gate (every status change)
  var pendingConfirm = null;
  function confirmThen(target, fn) {
    var m = FVST.openModal("confirm");
    m.querySelector("[data-confirm-body]").textContent = "Please confirm order change to " + (stop.labels[target] || target) + "?";
    pendingConfirm = fn;
  }
  var ok = document.querySelector("[data-confirm-ok]");
  if (ok) ok.addEventListener("click", function () {
    FVST.closeModal(ok);
    var fn = pendingConfirm;
    pendingConfirm = null;
    if (fn) fn();
  });

  var ACTIONS = { picking_up: "picking-up", in_transit: "in-transit" };
  document.querySelectorAll("[data-advance]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var target = btn.getAttribute("data-advance");
      confirmThen(target, function () { tap(ACTIONS[target] || target.replace("_", "-"), {}, target); });
    });
  });

  // ---------------------------------------------------------------- undo
  document.querySelectorAll("[data-undo]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      tap("undo", { expected_status: stop.status }, null, function () {
        try { sessionStorage.removeItem("fvst-pickup-result-" + key); } catch (e) { /* ignore */ }
      });
    });
  });

  // ---------------------------------------------------------------- pickup checklist
  var checklist = document.querySelector("[data-pickup-checklist]");
  var pickedBtn = document.querySelector("[data-mark-picked-up]");
  function checkedRefs() {
    return checklist ? Array.prototype.filter.call(checklist.querySelectorAll(".pickup-checklist-row"), function (row) {
      return row.querySelector("[data-pickup-check]").checked;
    }).map(function (row) { return row.getAttribute("data-reference"); }) : [];
  }
  function updatePicked() {
    if (!checklist || !pickedBtn) return;
    var refs = checkedRefs();
    var items = 0;
    checklist.querySelectorAll(".pickup-checklist-row").forEach(function (row) {
      var on = row.querySelector("[data-pickup-check]").checked;
      row.classList.toggle("is-checked", on);
      if (on) items += parseInt(row.getAttribute("data-items"), 10) || 0;
    });
    pickedBtn.disabled = refs.length === 0;
    pickedBtn.textContent = refs.length === 0 ? "Mark Picked Up"
      : "Mark Picked Up " + refs.length + " order" + (refs.length === 1 ? "" : "s") + ", " + itemsLabel(items);
  }
  if (checklist) {
    checklist.addEventListener("change", updatePicked);
    updatePicked();
  }

  /** Keeps the Picked Up answer for the result card, under the key the finished pickup now has; returns that key. */
  function rememberResult(data) {
    var ids = ((data && data.picked_up) || []).map(function (p) { var m = /^JOB-(\d+)$/i.exec(p.reference || ""); return m ? parseInt(m[1], 10) : null; })
      .filter(function (n) { return n !== null; });
    // GitHub #36: the server says which stop the finished pickup is (its stored key); the lowest-order-id guess is
    // only for a server that doesn't send it yet.
    var doneKey = (data && typeof data.stop_key === "string" && data.stop_key) ? data.stop_key
      : (ids.length ? "P-" + Math.min.apply(null, ids) : key);
    try { sessionStorage.setItem("fvst-pickup-result-" + doneKey, JSON.stringify(data)); } catch (e) { /* ignore */ }
    return doneKey;
  }

  var leftBehind = {};
  if (pickedBtn) pickedBtn.addEventListener("click", function () {
    var refs = checkedRefs();
    var unchecked = stop.orders.filter(function (o) { return refs.indexOf(o.reference) === -1; });
    if (unchecked.length === 0) {
      confirmThen("picked_up", function () { tap("picked-up", { picked_up: refs, left_behind: [] }, "picked_up", rememberResult); });
      return;
    }
    // "Are you sure? N orders not picked up." — reason + Restart / Fully Fail per order.
    leftBehind = {};
    unchecked.forEach(function (o) { leftBehind[o.reference] = "restart"; });
    var m = FVST.openModal("left-behind");
    m.querySelector("[data-left-behind-title]").innerHTML = "Are you sure? <strong>" + unchecked.length + "</strong> orders not picked up.";
    m.querySelector("[data-left-behind-list]").innerHTML = unchecked.map(function (o) {
      return '<div class="pickup-confirm-unchecked-row">' + // padding from the CSS rule (the list bleeds to the modal edges)
        '<span style="flex:1;"><strong>' + FVST.esc(o.name) + ',</strong> <span class="text-faint">' + itemsLabel(o.package_count) + "</span></span>" +
        '<button type="button" class="restart-fail-toggle" data-lb="' + FVST.esc(o.reference) + '">Restart</button></div>';
    }).join("");
    var note = m.querySelector("[data-left-behind-note]");
    note.value = "";
    m.querySelector("[data-left-behind-ok]").disabled = true;
  });
  var lbModal = document.querySelector('[data-modal="left-behind"]');
  if (lbModal) {
    lbModal.addEventListener("click", function (e) {
      var t = e.target.closest("[data-lb]");
      if (!t) return;
      var ref = t.getAttribute("data-lb");
      leftBehind[ref] = leftBehind[ref] === "fail" ? "restart" : "fail";
      t.classList.toggle("is-fail", leftBehind[ref] === "fail");
      t.textContent = leftBehind[ref] === "fail" ? "Fully Fail" : "Restart";
    });
    var lbNote = lbModal.querySelector("[data-left-behind-note]");
    var lbOk = lbModal.querySelector("[data-left-behind-ok]");
    lbNote.addEventListener("input", function () { lbOk.disabled = lbNote.value.trim() === ""; });
    lbOk.addEventListener("click", function () {
      FVST.closeModal(lbOk);
      var lb = Object.keys(leftBehind).map(function (ref) { return { reference: ref, action: leftBehind[ref] }; });
      tap("picked-up", { picked_up: checkedRefs(), left_behind: lb, note: lbNote.value.trim() }, "picked_up", rememberResult);
    });
  }

  // ---------------------------------------------------------------- order info (ⓘ)
  var infoRef = null;
  document.querySelectorAll("[data-order-info]").forEach(function (btn) {
    btn.addEventListener("click", function (e) {
      e.preventDefault(); // inside the checklist <label>: don't tick the box
      infoRef = btn.getAttribute("data-order-info");
      FVST.call("GET", "orders/" + encodeURIComponent(infoRef)).then(function (r) {
        if (!r.ok) { FVST.toast(FVST.errorText(r)); return; }
        var o = r.data;
        var m = FVST.openModal("order-info");
        m.querySelector("[data-order-info-title]").textContent = o.name || infoRef;
        var sizes = [];
        (o.packages || []).forEach(function (p) { for (var i = 0; i < Math.max(1, p.quantity || 1); i++) sizes.push(p.size || "Item"); });
        m.querySelector("[data-order-info-sizes]").innerHTML = sizes.map(function (s, i) {
          return '<div class="order-info-row"><span>' + (sizes.length === 1 ? "Item size" : "Item " + (i + 1) + " size") + "</span><span>" + FVST.esc(s) + "</span></div>";
        }).join("");
        m.querySelector("[data-order-info-sender]").textContent = (o.sender && o.sender.name) || "—";
        m.querySelector("[data-order-info-recipient]").textContent = (o.recipient && o.recipient.name) || "—";
        var toggleBtn = m.querySelector("[data-order-info-toggle]");
        if (toggleBtn && checklist) {
          var box = checklist.querySelector('[data-reference="' + infoRef + '"] [data-pickup-check]');
          toggleBtn.textContent = box && box.checked ? "Unmark Picked Up" : "Mark Picked Up";
        }
      });
    });
  });
  var infoToggle = document.querySelector("[data-order-info-toggle]");
  if (infoToggle) infoToggle.addEventListener("click", function () {
    var box = checklist.querySelector('[data-reference="' + infoRef + '"] [data-pickup-check]');
    if (box) { box.checked = !box.checked; updatePicked(); }
    FVST.closeModal(infoToggle);
  });

  // ---------------------------------------------------------------- proof of delivery (in transit)
  var proof = document.querySelector("[data-proof-of-delivery]");
  var deliveredBtn = document.querySelector("[data-mark-delivered]");
  var photos = []; // {id, uploading, failed, error, file, url}
  var contact = "not_needed";

  /** Why Mark Delivered can't be tapped yet, in the driver's words; null when it can (GitHub #8, #9). */
  function deliverBlocker() {
    if (savedLocally || stop.status !== "in_transit") return null;
    if (photos.some(function (p) { return p.uploading; })) return "Waiting for the photo to upload…";
    if (photos.some(function (p) { return p.failed; }) && !photos.some(function (p) { return p.id; })) return "The photo didn't upload. Tap Retry on it, or take another.";
    if (!photos.some(function (p) { return p.id; })) return "Take at least 1 photo to deliver.";
    var leftAt = proof ? proof.querySelector("[data-left-where]").value.trim() : "";
    if (leftAt === "") return "Fill in \"Where was it left?\" to deliver.";
    return null;
  }
  function canDeliver() {
    return !savedLocally && stop.status === "in_transit" && deliverBlocker() === null;
  }
  var deliverHint = document.querySelector("[data-deliver-hint]");
  function updateDelivered() {
    if (deliveredBtn) deliveredBtn.disabled = busy || !canDeliver();
    if (deliverHint) {
      var why = busy ? null : deliverBlocker();
      deliverHint.textContent = why || "";
      deliverHint.hidden = !why;
    }
  }

  function renderPhotos() {
    var grid = proof.querySelector("[data-photo-grid]");
    grid.innerHTML = photos.map(function (p, i) {
      return '<div class="photo-thumb filled' + (p.failed ? " is-failed" : "") + '" style="background-image:url(' + p.url + ');background-size:cover;background-position:center;">' +
        // No ✕ while uploading: the photo isn't settled yet (removing it then lost track of which one went).
        (p.uploading ? '<span class="text-faint" style="font-size:11px;">Uploading…</span>' : '<button type="button" class="remove-photo" data-remove-photo="' + i + '" aria-label="Remove photo">✕</button>') +
        // A failed upload keeps its tile with the reason and a Retry, instead of vanishing (GitHub #8).
        (p.failed ? '<span class="photo-upload-error">' + FVST.esc(p.error || "Upload failed") + '</span><button type="button" class="photo-retry" data-retry-photo="' + i + '">Retry</button>' : "") +
        "</div>";
    }).join("");
    var label = proof.querySelector("[data-photo-button-label]");
    label.textContent = photos.length === 0 ? "📷 Take Photo (Minimum 1)" : "📷 Take Photo";
    proof.querySelector("[data-photo-input]").disabled = photos.length >= 3;
    proof.querySelector("[data-photo-button]").disabled = photos.length >= 3;
    updateDelivered();
  }

  // ---------------------------------------------------------------- camera (task-camera.html)
  // The time and address are burned into every proof photo: it's what shows the photo was taken here, now.
  var MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
  function stampText(d) {
    var h = d.getHours() % 12 || 12;
    var m = d.getMinutes();
    return MONTHS[d.getMonth()] + " " + d.getDate() + ", " + d.getFullYear() + " · " + h + ":" + (m < 10 ? "0" : "") + m + " " + (d.getHours() >= 12 ? "PM" : "AM");
  }

  /** Draws a source (video frame or image) to a JPEG with the stamp in the bottom-left corner, as on the camera screen. */
  function stampedJpeg(source, width, height, when) {
    var scale = Math.min(1, 1600 / Math.max(width, height));
    var canvas = document.createElement("canvas");
    canvas.width = Math.round(width * scale);
    canvas.height = Math.round(height * scale);
    var ctx = canvas.getContext("2d");
    ctx.drawImage(source, 0, 0, canvas.width, canvas.height);
    var size = Math.max(14, Math.round(canvas.width / 34));
    var pad = Math.round(size * 1.1);
    ctx.font = size + "px ui-monospace, Menlo, Consolas, monospace";
    ctx.textBaseline = "bottom";
    ctx.fillStyle = "#ffe07a";
    ctx.shadowColor = "rgba(0, 0, 0, 0.8)";
    ctx.shadowBlur = Math.round(size / 4);
    ctx.shadowOffsetY = 1;
    var lines = [stampText(when), stop.address || ""];
    lines.forEach(function (line, i) {
      ctx.fillText(line, pad, canvas.height - pad - (lines.length - 1 - i) * size * 1.6);
    });
    return new Promise(function (resolve) {
      canvas.toBlob(function (blob) {
        resolve(blob ? new File([blob], "proof-" + when.getTime() + ".jpg", { type: "image/jpeg", lastModified: when.getTime() }) : null);
      }, "image/jpeg", 0.85);
    });
  }

  /** A photo from the phone's own camera (file input fallback): stamp it too; if it can't be read, send it as is. */
  function stampFile(file) {
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        stampedJpeg(img, img.naturalWidth, img.naturalHeight, new Date(file.lastModified || Date.now())).then(function (f) {
          URL.revokeObjectURL(url);
          resolve(f || file);
        });
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }

  var camera = document.querySelector("[data-camera]");
  var cameraStream = null;
  var cameraClock = null;
  var cameraFailed = false; // refused once: go straight to the phone's camera next time
  function cameraSupported() {
    return !!(camera && !cameraFailed && window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
  }
  function closeCamera() {
    if (cameraStream) { cameraStream.getTracks().forEach(function (t) { t.stop(); }); cameraStream = null; }
    if (cameraClock) { clearInterval(cameraClock); cameraClock = null; }
    if (camera) {
      camera.hidden = true;
      camera.querySelector("[data-camera-video]").srcObject = null;
    }
  }
  function openCamera(fallback) {
    if (!cameraSupported()) { fallback(); return; }
    var video = camera.querySelector("[data-camera-video]");
    var hint = camera.querySelector("[data-camera-hint]");
    var shutter = camera.querySelector("[data-camera-shutter]");
    var time = camera.querySelector("[data-camera-time]");
    hint.hidden = false;
    hint.textContent = "Starting camera…";
    shutter.disabled = true;
    camera.hidden = false;
    time.textContent = stampText(new Date());
    cameraClock = setInterval(function () { time.textContent = stampText(new Date()); }, 1000);
    navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: "environment" }, width: { ideal: 1920 }, height: { ideal: 1080 } }, audio: false })
      .then(function (stream) {
        if (camera.hidden) { stream.getTracks().forEach(function (t) { t.stop(); }); return; }
        cameraStream = stream;
        video.srcObject = stream;
        return video.play().then(function () { hint.hidden = true; shutter.disabled = false; });
      })
      .catch(function () {
        // Permission refused or no camera: use the phone's camera app instead.
        cameraFailed = true;
        closeCamera();
        FVST.toast("Camera not available here — using your phone's camera.");
        fallback();
      });
  }
  if (camera) {
    camera.querySelector("[data-camera-close]").addEventListener("click", closeCamera);
    camera.querySelector("[data-camera-shutter]").addEventListener("click", function () {
      var video = camera.querySelector("[data-camera-video]");
      if (!cameraStream || !video.videoWidth) return;
      var shutter = this;
      shutter.disabled = true;
      stampedJpeg(video, video.videoWidth, video.videoHeight, new Date()).then(function (file) {
        closeCamera();
        if (file) addPhoto(file); else FVST.toast("Couldn't take the photo. Try again.");
      });
    });
    document.addEventListener("visibilitychange", function () { if (document.hidden && cameraStream) closeCamera(); });
  }

  function upload(p) {
    p.uploading = true;
    p.failed = false;
    p.error = null;
    renderPhotos();
    FVST.uploadPhoto(p.file, { reference: firstRef }).then(function (r) {
      p.uploading = false;
      if (r.ok) {
        p.id = r.photoId;
        p.file = null;
        if (r.queued) FVST.toast("No signal — photo saved on this phone. It'll upload when you're back online.");
      } else {
        p.failed = true;
        p.error = r.error;
      }
      renderPhotos();
    });
  }

  function addPhoto(file) {
    if (!file || photos.length >= 3) return;
    var p = { id: null, uploading: true, failed: false, error: null, file: file, url: URL.createObjectURL(file) };
    photos.push(p);
    upload(p);
  }

  if (proof) {
    var input = proof.querySelector("[data-photo-input]");
    input.addEventListener("change", function () {
      var file = input.files && input.files[0];
      input.value = "";
      if (!file || photos.length >= 3) return;
      stampFile(file).then(addPhoto);
    });
    proof.querySelector("[data-open-camera]").addEventListener("click", function () {
      if (photos.length >= 3) return;
      openCamera(function () { input.click(); });
    });
    proof.querySelector("[data-photo-grid]").addEventListener("click", function (e) {
      var retry = e.target.closest("[data-retry-photo]");
      if (retry) {
        var again = photos[parseInt(retry.getAttribute("data-retry-photo"), 10)];
        if (again && again.failed && again.file) upload(again);
        return;
      }
      var rm = e.target.closest("[data-remove-photo]");
      if (!rm) return;
      var p = photos.splice(parseInt(rm.getAttribute("data-remove-photo"), 10), 1)[0];
      if (!p) return;
      URL.revokeObjectURL(p.url);
      if (p.id && p.id.indexOf("client:") !== 0) FVST.call("DELETE", "photos/" + encodeURIComponent(p.id));
      renderPhotos();
    });
    var preset = proof.querySelector("[data-left-where-preset]");
    var leftAt = proof.querySelector("[data-left-where]");
    preset.addEventListener("change", function () { if (preset.value) leftAt.value = preset.value; updateDelivered(); });
    leftAt.addEventListener("input", updateDelivered);
    proof.querySelector("[data-contact-toggle]").addEventListener("click", function (e) {
      var b = e.target.closest("button[data-value]");
      if (!b) return;
      contact = b.getAttribute("data-value");
      proof.querySelectorAll("[data-contact-toggle] button").forEach(function (x) { x.classList.toggle("active", x === b); });
    });
    renderPhotos();
  }

  if (deliveredBtn) deliveredBtn.addEventListener("click", function () {
    if (!canDeliver()) return;
    confirmThen("delivered", function () {
      tap("delivered", {
        reference: firstRef,
        photos: photos.map(function (p) { return p.id; }).filter(Boolean),
        left_at: proof.querySelector("[data-left-where]").value.trim(),
        customer_contact: contact,
        recipient_name: proof.querySelector("[data-recipient-name]").value.trim() || null,
      }, "delivered");
    });
  });
  updateDelivered();

  // ---------------------------------------------------------------- pickup result (finished pickup)
  var summary = document.querySelector("[data-pickup-summary]");
  if (summary) {
    var saved = null;
    try { saved = JSON.parse(sessionStorage.getItem("fvst-pickup-result-" + key) || "null"); } catch (e) { saved = null; }
    if (saved) {
      var name = {};
      stop.orders.forEach(function (o) { name[o.reference] = o.name; });
      var html = "";
      if (saved.picked_up && saved.picked_up.length) {
        html += '<div class="pickup-summary-section"><div class="pickup-summary-title is-success">Picked up (' + saved.picked_up.length + ")</div>" +
          saved.picked_up.map(function (p) {
            return '<div class="pickup-summary-item pickup-summary-item-linked"><span>' + FVST.esc(name[p.reference] || p.reference) + "</span>" +
              (p.dropoff_stop_key ? '<a class="pickup-summary-goto-btn" href="' + stopUrl(p.dropoff_stop_key) + '" aria-label="Go to delivery"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg></a>' : "") +
              "</div>";
          }).join("") + "</div>";
      }
      [["restarted", "Restarted", "is-restart"], ["failed", "Fully failed", "is-fail"]].forEach(function (s) {
        var refs = saved[s[0]] || [];
        if (refs.length) {
          html += '<div class="pickup-summary-section"><div class="pickup-summary-title ' + s[2] + '">' + s[1] + " (" + refs.length + ")</div>" +
            refs.map(function (r) { return '<div class="pickup-summary-item">' + FVST.esc(name[r] || r) + "</div>"; }).join("") + "</div>";
        }
      });
      summary.querySelector("[data-pickup-summary-content]").innerHTML = html;
      summary.hidden = html === "";
    }
  }

  // GitHub #7: app.js's live refresh leaves this stop alone while the driver is in the middle of something on it —
  // a tap in flight, a status saved on this phone, photos taken, the camera open, the proof form or the pickup
  // checklist changed. (Replaced by the next init, so a stop no longer shown never holds it.)
  var checksAtLoad = checklist ? checkedRefs().join(",") : "";
  FVST.holdLive = function () {
    return busy || savedLocally || photos.length > 0 || !!cameraStream ||
      (!!checklist && checkedRefs().join(",") !== checksAtLoad) ||
      (!!proof && (proof.querySelector("[data-left-where]").value.trim() !== "" || proof.querySelector("[data-recipient-name]").value.trim() !== ""));
  };
  }

  init();
  document.addEventListener("fvst:content", init); // GitHub #7
})();
