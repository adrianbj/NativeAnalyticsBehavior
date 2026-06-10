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

  // Skip headless automation (Selenium, Playwright, headless Chrome, etc.),
  // which sets navigator.webdriver. Catches the high-signal automated clients
  // without a UA blocklist; it won't catch every bot, but it keeps obvious
  // synthetic traffic out of the heatmaps.
  if (navigator.webdriver === true) return;

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

  // Redirect a click on a label (or a non-interactive node inside one) to the
  // control it labels: a bare label box — especially an empty Inputfield header
  // strip — sits in dead space and produces misleading heat. Genuine
  // interactive targets (links, buttons, form fields) are kept as-is so e.g. the
  // "terms and conditions" link inside the agree label isn't blamed on the box.
  function isToggle(node) {
    return node && node.nodeName.toLowerCase() === "input" && (node.type === "radio" || node.type === "checkbox");
  }
  function resolveTarget(el) {
    if (!el || el.nodeType !== 1) return el;
    var tag = el.nodeName.toLowerCase();
    // A radio/checkbox is usually visually hidden behind a styled label (e.g.
    // plan cards); anchor heat to the visible label, not the tiny input box.
    if (isToggle(el)) {
      var ownLabel = el.closest ? el.closest("label") : null;
      return ownLabel || el;
    }
    if (tag === "a" || tag === "button" || tag === "select" || tag === "textarea") return el;
    // Bubble a click on a non-interactive child (e.g. the title <span> inside an
    // accordion <a>, or an <svg> icon inside a button) up to the interactive
    // ancestor, so the heat lands on the whole control rather than splitting
    // across the inner node and the wrapper.
    var clickable = el.closest ? el.closest("a, button") : null;
    if (clickable) return clickable;
    var label = el.closest ? el.closest("label") : null;
    if (label) {
      var control = null;
      var forId = label.getAttribute("for");
      if (forId) control = document.getElementById(forId);
      if (!control) control = label.querySelector("input, select, textarea, button");
      // For radio/checkbox controls the label is the real click surface, so keep
      // the label; for other controls (text fields, selects) redirect to them.
      if (control) return isToggle(control) ? label : control;
    }
    return el;
  }

  // Escape class/id tokens for use in a selector. UIKit's responsive classes
  // (e.g. "uk-width-1-1@m") contain "@", which is invalid raw in a selector and
  // makes querySelector throw on the dashboard, dropping the click as unmatched.
  function cssEscape(s) {
    if (window.CSS && CSS.escape) return CSS.escape(s);
    return String(s).replace(/[^a-zA-Z0-9_-]/g, function (c) { return "\\" + c; });
  }

  // UIKit assigns runtime IDs like "uk-accordion-5" whose numbering isn't stable
  // between the snapshot capture and a later click, so anchoring on them
  // guarantees a miss. Treat any uk-* id containing a digit as volatile and fall
  // through to the structural path instead.
  function volatileId(id) {
    return /^uk-/.test(id) && /\d/.test(id);
  }

  // --- stable CSS selector for a click target (structure only, no text) ---
  function cssSelector(el) {
    if (!el || el.nodeType !== 1) return "";
    el = resolveTarget(el);
    var parts = [];
    var node = el;
    var depth = 0;
    while (node && node.nodeType === 1 && depth < 5) {
      var part = node.nodeName.toLowerCase();
      if (node.id && !volatileId(node.id)) { part += "#" + cssEscape(node.id); parts.unshift(part); break; }
      if (node.classList && node.classList.length) {
        part += "." + Array.prototype.slice.call(node.classList, 0, 2).map(cssEscape).join(".");
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

  // --- human-readable label for a click target, for the dashboard table ---
  // The structural selector is unreadable ("ul.uk-accordion > li > a:nth-child"),
  // so capture the control's visible meaning. Skip masked/blocked subtrees so we
  // never lift PII text off this platform; the dashboard falls back to the
  // selector when the label is empty.
  // Visible text only: clone and drop <style>/<script>/<svg> first, since
  // textContent otherwise concatenates CSS from inline SVG icons (e.g. UIKit's
  // accordion-icon <style>) onto the real label.
  function visibleText(el) {
    var clone = el.cloneNode(true);
    var junk = clone.querySelectorAll("style, script, svg");
    for (var i = 0; i < junk.length; i++) {
      if (junk[i].parentNode) junk[i].parentNode.removeChild(junk[i]);
    }
    return clone.textContent || "";
  }

  // Associated <label> text for a form control, so a click on a bare text field
  // reads "First name" rather than "input#register_first_name". Resolves both
  // label[for=id] and a wrapping <label>; nested controls are stripped so we get
  // the prompt text, not the field's own value/options.
  function fieldLabel(el) {
    var tag = el.nodeName.toLowerCase();
    if (tag !== "input" && tag !== "select" && tag !== "textarea") return "";
    var lbl = null;
    var id = el.getAttribute("id");
    if (id) {
      try { lbl = el.ownerDocument.querySelector("label[for=\"" + cssEscape(id) + "\"]"); } catch (e) {}
    }
    if (!lbl && el.closest) lbl = el.closest("label");
    if (!lbl) return "";
    var clone = lbl.cloneNode(true);
    var junk = clone.querySelectorAll("style, script, svg, input, select, textarea");
    for (var i = 0; i < junk.length; i++) {
      if (junk[i].parentNode) junk[i].parentNode.removeChild(junk[i]);
    }
    return clone.textContent || "";
  }

  function clickLabel(el) {
    if (!el || el.nodeType !== 1) return "";
    if (el.closest && el.closest("[data-na-block], [data-na-mask]")) return "";
    var txt = el.getAttribute("aria-label")
      || (el.nodeName.toLowerCase() === "img" ? el.getAttribute("alt") : "")
      || el.getAttribute("title")
      || visibleText(el)
      || fieldLabel(el)
      || el.getAttribute("placeholder")
      || el.getAttribute("value")
      || "";
    txt = txt.replace(/\s+/g, " ").trim();
    return txt.slice(0, 100);
  }

  // A click is "dead" when nothing on the element (or its ancestors) makes it
  // actionable — a sign a visitor expected a non-interactive thing (image,
  // styled text, icon) to do something. Clicks on the bare page body/html are
  // empty-area clicks, not frustration, so they don't count.
  var INTERACTIVE_SEL = "a, button, input, select, textarea, label, summary, " +
    "[role=button], [role=link], [role=tab], [role=menuitem], [role=checkbox], " +
    "[role=radio], [onclick], [tabindex], [contenteditable]";
  function isDeadClick(el) {
    if (!el || el.nodeType !== 1) return false;
    var tag = el.nodeName.toLowerCase();
    if (tag === "html" || tag === "body") return false;
    return !(el.closest && el.closest(INTERACTIVE_SEL));
  }

  // Third-party widgets inject their own chrome into the page (e.g.
  // LiveHelperChat's status widget / chat box). Those nodes aren't part of the
  // site, are late-injected so they never appear in the captured backdrop, and
  // their real behaviour lives inside a cross-origin iframe we can't observe — so
  // a click on them is meaningless noise that also gets mis-flagged as "dead".
  // Drop such clicks entirely. Extend this list as new widgets are added.
  var IGNORE_SEL = "[id^=\"lhc-\"], [id^=\"lhc_\"]";
  function isIgnoredClick(el) {
    return !!(el && el.closest && el.closest(IGNORE_SEL));
  }

  // Rage click: 3+ clicks within 1s landing within ~30px of each other — a
  // classic frustration signal. Keep a tiny rolling buffer of recent clicks.
  var recentClicks = [];
  var RAGE_MS = 1000, RAGE_PX = 30, RAGE_MIN = 3;
  function isRageClick(now, x, y) {
    recentClicks.push({ t: now, x: x, y: y });
    while (recentClicks.length && now - recentClicks[0].t > RAGE_MS) recentClicks.shift();
    var near = 0;
    for (var i = 0; i < recentClicks.length; i++) {
      var dx = recentClicks[i].x - x, dy = recentClicks[i].y - y;
      if (dx * dx + dy * dy <= RAGE_PX * RAGE_PX) near++;
    }
    return near >= RAGE_MIN;
  }

  // Double/triple-clicking to select text produces the same same-spot click
  // cluster as rage and lands on non-interactive text (so it also reads as
  // "dead"). If the click left an active text selection, it's a copy gesture,
  // not frustration — suppress both flags so the heatmap isn't polluted.
  function hasTextSelection() {
    var sel = window.getSelection ? window.getSelection() : null;
    return !!(sel && !sel.isCollapsed && String(sel).length > 0);
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
  var lastSentScrollPct = -1;

  function recordClick(e) {
    if (isIgnoredClick(e.target)) return;
    var dw = docWidth() || 1;
    var xFrac = Math.max(0, Math.min(1000, Math.round((e.pageX / dw) * 1000)));
    var target = resolveTarget(e.target);
    var selecting = hasTextSelection();
    queue.push({
      type: "click",
      path: path,
      device: deviceClass(),
      x_frac: xFrac,
      y_px: Math.round(e.pageY),
      vw: Math.round(window.innerWidth || 0),
      dh: Math.round(docHeight()),
      selector: cssSelector(e.target),
      label: clickLabel(target),
      dead: (!selecting && isDeadClick(e.target)) ? 1 : 0,
      rage: (!selecting && isRageClick(Date.now(), e.pageX, e.pageY)) ? 1 : 0,
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
    if (includeScroll) {
      // Seed from the current viewport first: a visitor who never fired a
      // scroll event still saw the initial fold, so record that depth rather
      // than a misleading 0 (which would drop them out of the top band).
      trackScroll();
      // Re-send the running max on every flush (not just at pagehide) so scroll
      // depth survives a missed unload. The server keeps only the deepest value
      // per session, so this can't inflate the pageview count. Skip when the
      // depth hasn't grown since the last send to avoid redundant beacons.
      if (maxScrollPct !== lastSentScrollPct) {
        lastSentScrollPct = maxScrollPct;
        events.push({
          type: "scroll",
          path: path,
          device: deviceClass(),
          scroll_pct: maxScrollPct,
          visitorId: visitorId,
          sessionId: sessionId
        });
      }
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
      path: path,
      device: deviceClass(),
      capture_width: Math.round(window.innerWidth || document.documentElement.clientWidth || 0),
      pageModified: cfg.pageModified || ""
    });
    // Snapshots fire on load (page is alive) and are far larger than sendBeacon's
    // ~64KB cap; keepalive fetch carries the same cap, so use a plain fetch.
    try {
      fetch(cfg.snapshotEndpoint, { method: "POST", body: envelope, headers: { "Content-Type": "application/json" } });
    } catch (e) {}
  }

  function doCapture() {
    if (!window.rrwebSnapshot || !window.rrwebSnapshot.snapshot) return;
    var result;
    try {
      result = window.rrwebSnapshot.snapshot(document, {
        blockSelector: "[data-na-block]",
        maskAllInputs: true,
        maskTextSelector: "[data-na-mask]",
        inlineStylesheet: true,
        recordCanvas: false
      });
    } catch (e) { return; }
    // snapshot() returns [serializedNode, idNodeMap]; the map holds live DOM nodes
    // (circular, not serializable), so keep only the serializable node tree.
    var node = Array.isArray(result) ? result[0] : result;
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
  setInterval(function () { flush(true); }, 10000);
  window.addEventListener("pagehide", function () { flush(true); });
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "hidden") flush(true);
  });

  if (document.readyState === "complete") maybeCaptureSnapshot();
  else window.addEventListener("load", maybeCaptureSnapshot);
})();
