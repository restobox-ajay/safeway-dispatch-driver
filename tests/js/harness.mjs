// Loads public/driver-app/js/app.js in a bare page: no elements, no IndexedDB, fetch and storage as given.
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import vm from "node:vm";

const source = readFileSync(fileURLToPath(new URL("../../public/driver-app/js/app.js", import.meta.url)), "utf8");

function memoryStorage(initial) {
  const data = new Map(Object.entries(initial || {}));
  return {
    getItem: (k) => (data.has(k) ? data.get(k) : null),
    setItem: (k, v) => { data.set(k, String(v)); },
    removeItem: (k) => { data.delete(k); },
    clear: () => { data.clear(); },
  };
}

/**
 * @param {{driverId?: number, lastFix?: object, storage?: object, fetch?: Function}} opts
 * @returns {{FVST: object, gpsFix: (lat: number, lng: number) => void}}
 */
export function loadApp(opts = {}) {
  const storage = Object.assign({}, opts.storage);
  if (opts.lastFix) storage["fvst-last-fix"] = JSON.stringify(opts.lastFix);
  let onFix = null;

  const element = () => ({ style: {}, classList: { add() {}, remove() {}, toggle() {}, contains: () => false }, setAttribute() {}, appendChild() {}, addEventListener() {} });
  const document = {
    hidden: false,
    querySelector: () => null,
    querySelectorAll: () => [],
    addEventListener() {},
    createElement: element,
    createTextNode: (t) => ({ textContent: t }),
    body: element(),
  };
  const window = {
    FVST: { csrf: "t", driverId: opts.driverId ?? 7, urls: { login: "/login", base: "/driver" } },
    location: { href: "/driver/tasks", pathname: "/driver/tasks", search: "" },
    addEventListener() {},
    confirm: () => true,
    AbortController,
  };
  const context = {
    window, document,
    navigator: {
      onLine: true, userAgent: "test", platform: "test",
      geolocation: { watchPosition: (cb) => { onFix = cb; return 1; }, clearWatch() {} },
    },
    localStorage: memoryStorage(storage),
    sessionStorage: memoryStorage(),
    fetch: opts.fetch || (() => Promise.reject(new TypeError("Failed to fetch"))),
    crypto: globalThis.crypto,
    setTimeout, clearTimeout,
    setInterval: () => 0,
    requestAnimationFrame: () => 0,
    AbortController, FormData, Blob, Response, URLSearchParams, URL, Promise, JSON, Date, Math, Object, String, Array, Error, TypeError,
    console,
  };
  vm.createContext(context);
  vm.runInContext(source, context, { filename: "app.js" });

  return {
    FVST: window.FVST,
    // Objects made inside the page come from its own realm: compare them as plain data.
    plain: (v) => JSON.parse(JSON.stringify(v)),
    gpsFix: (latitude, longitude) => onFix && onFix({ coords: { latitude, longitude }, timestamp: Date.now() }),
  };
}
