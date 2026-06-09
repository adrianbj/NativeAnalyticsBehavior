(function () {
  "use strict";

  var cfg = window.NAB_CONFIG || {};
  var pwnaCfg = window.PWNA_CONFIG || {};
  if (!cfg.collectEndpoint || !cfg.heatmaps) return;

  // --- consent / DNT (mirror NativeAnalytics gating) ---
  function getCookie(name) {
    var m = document.cookie.match(new RegExp("(?:^|;\\s*)" + String(name).replace(/[.*+?^${}()|[\]\\]/g, "\\$&") + "=([^;]*)"));
    return m ? m[1] : null;
  }
  function dntActive() {
    if (!pwnaCfg.respectDnt) return false;
    return navigator.doNotTrack === "1" || window.doNotTrack === "1";
  }
  function hasConsent() {
    if (!pwnaCfg.consentRequired) return true;
    var name = pwnaCfg.consentCookieName || "";
    if (!name) return true;
    return getCookie(name) !== null;
  }
  if (dntActive() || !hasConsent()) return;

  // --- sampling ---
  var rate = typeof cfg.sampleRate === "number" ? cfg.sampleRate : 100;
  if (rate < 100 && (Math.random() * 100) >= rate) return;

  // --- identity (reuse NativeAnalytics IDs) ---
  function getStored(store, key) {
    try { return store.getItem(key); } catch (e) { return null; }
  }
  var pwna = window.PWNA || {};
  var visitorId = pwna.visitorId || getStored(window.localStorage, "pwna_vid") || "";
  var sessionId = pwna.sessionId || getStored(window.sessionStorage, "pwna_sid") || "";

  // --- device class ---
  function deviceClass() {
    var w = window.innerWidth || document.documentElement.clientWidth || 0;
    if (w > 0 && w < 768) return "mobile";
    if (w >= 768 && w < 1024) return "tablet";
    return "desktop";
  }

  // --- stable CSS selector for a click target (structure only, no text) ---
  function cssSelector(el) {
    if (!el || el.nodeType !== 1) return "";
    var parts = [];
    var node = el;
    var depth = 0;
    while (node && node.nodeType === 1 && depth < 5) {
      var part = node.nodeName.toLowerCase();
      if (node.id) { part += "#" + node.id; parts.unshift(part); break; }
      if (node.classList && node.classList.length) {
        part += "." + Array.prototype.slice.call(node.classList, 0, 2).join(".");
      }
      var parent = node.parentNode;
      if (parent && parent.children && parent.children.length > 1) {
        var idx = Array.prototype.indexOf.call(parent.children, node) + 1;
        part += ":nth-child(" + idx + ")";
      }
      parts.unshift(part);
      node = node.parentNode;
      depth++;
    }
    return parts.join(" > ").slice(0, 255);
  }

  function docWidth() {
    return Math.max(document.documentElement.scrollWidth, document.body ? document.body.scrollWidth : 0, window.innerWidth || 0);
  }
  function docHeight() {
    return Math.max(document.documentElement.scrollHeight, document.body ? document.body.scrollHeight : 0, window.innerHeight || 0);
  }

  var path = window.location.pathname || "/";
  var queue = [];
  var maxScrollPct = 0;
  var scrollSent = false;

  function recordClick(e) {
    var dw = docWidth() || 1;
    var xFrac = Math.max(0, Math.min(1000, Math.round((e.pageX / dw) * 1000)));
    queue.push({
      type: "click",
      path: path,
      device: deviceClass(),
      x_frac: xFrac,
      y_px: Math.round(e.pageY),
      vw: Math.round(window.innerWidth || 0),
      dh: Math.round(docHeight()),
      selector: cssSelector(e.target),
      visitorId: visitorId,
      sessionId: sessionId
    });
    if (queue.length >= 20) flush(false);
  }

  function trackScroll() {
    var st = window.pageYOffset || document.documentElement.scrollTop || 0;
    var vh = window.innerHeight || document.documentElement.clientHeight || 0;
    var dh = docHeight() || 1;
    var pct = Math.max(0, Math.min(100, Math.round(((st + vh) / dh) * 100)));
    if (pct > maxScrollPct) maxScrollPct = pct;
  }

  function buildPayload(includeScroll) {
    var events = queue.slice();
    queue = [];
    if (includeScroll && !scrollSent) {
      scrollSent = true;
      events.push({
        type: "scroll",
        path: path,
        device: deviceClass(),
        scroll_pct: maxScrollPct,
        visitorId: visitorId,
        sessionId: sessionId
      });
    }
    if (!events.length) return null;
    return JSON.stringify({ events: events });
  }

  function flush(includeScroll) {
    var body = buildPayload(includeScroll);
    if (!body) return;
    var sent = false;
    if (navigator.sendBeacon) {
      try { sent = navigator.sendBeacon(cfg.collectEndpoint, new Blob([body], { type: "application/json" })); } catch (e) { sent = false; }
    }
    if (!sent) {
      try {
        fetch(cfg.collectEndpoint, { method: "POST", body: body, keepalive: true, headers: { "Content-Type": "application/json" } });
      } catch (e) {}
    }
  }

  function stripScripts(n) {
    if (!n || !n.childNodes || !n.childNodes.length) return;
    n.childNodes = n.childNodes.filter(function (c) {
      return !(c && c.type === 2 && c.tagName === "script");
    });
    for (var i = 0; i < n.childNodes.length; i++) stripScripts(n.childNodes[i]);
  }

  function uploadSnapshot(node) {
    var envelope = JSON.stringify({
      dom: node,
      path: cfg.snapshotPath || path,
      device: deviceClass(),
      capture_width: Math.round(window.innerWidth || document.documentElement.clientWidth || 0),
      pageModified: cfg.pageModified || ""
    });
    try {
      if (navigator.sendBeacon) {
        navigator.sendBeacon(cfg.snapshotEndpoint, new Blob([envelope], { type: "application/json" }));
        return;
      }
    } catch (e) {}
    try {
      fetch(cfg.snapshotEndpoint, { method: "POST", body: envelope, keepalive: true, headers: { "Content-Type": "application/json" } });
    } catch (e) {}
  }

  function doCapture() {
    if (!window.rrwebSnapshot || !window.rrwebSnapshot.snapshot) return;
    var node;
    try {
      node = window.rrwebSnapshot.snapshot(document, {
        blockSelector: "[data-na-block]",
        maskAllInputs: true,
        maskTextSelector: "[data-na-mask]",
        inlineStylesheet: true,
        recordCanvas: false
      });
    } catch (e) { return; }
    if (!node) return;
    stripScripts(node);
    uploadSnapshot(node);
  }

  function maybeCaptureSnapshot() {
    if (!cfg.snapshotEndpoint || !cfg.snapshotFresh) return;
    if (cfg.snapshotFresh[deviceClass()] === true) return;
    if (window.rrwebSnapshot && window.rrwebSnapshot.snapshot) { doCapture(); return; }
    if (!cfg.snapshotLib) return;
    var s = document.createElement("script");
    s.src = cfg.snapshotLib;
    s.onload = doCapture;
    (document.head || document.documentElement).appendChild(s);
  }

  document.addEventListener("click", recordClick, true);
  window.addEventListener("scroll", trackScroll, { passive: true });
  setInterval(function () { flush(false); }, 10000);
  window.addEventListener("pagehide", function () { flush(true); });
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "hidden") flush(true);
  });

  if (document.readyState === "complete") maybeCaptureSnapshot();
  else window.addEventListener("load", maybeCaptureSnapshot);
})();
