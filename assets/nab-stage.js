// Shared snapshot-stage helpers for the NativeAnalyticsBehavior dashboard.
// Owns the two things that MUST stay identical between the aggregate heatmap
// (heatmap.js) and the single-session trail (session.js): rebuilding a stored
// rrweb snapshot into a sandboxed iframe, and mapping a recorded click
// (x_frac 0..1000 of doc width; y_px normalized by the doc height at capture)
// to a pixel point in the overlay that sits on top of the iframe.
(function () {
  "use strict";

  // Recursively drop <script> element nodes (rrweb NodeType 2 = Element) from a
  // snapshot tree. The backdrop iframe is sandboxed WITHOUT allow-scripts, so a
  // replayed page must never run; snapshots stored before capture-time stripping
  // shipped still carry scripts, so strip here before rebuild too.
  function stripScripts(n) {
    if (!n || !n.childNodes || !n.childNodes.length) return;
    n.childNodes = n.childNodes.filter(function (c) {
      return !(c && c.type === 2 && c.tagName === "script");
    });
    for (var i = 0; i < n.childNodes.length; i++) stripScripts(n.childNodes[i]);
  }

  // Write a snapshot into a document. Returns true on success. Clears the doc,
  // rebuilds via rrweb-snapshot, removes <noscript> (rendered visibly with
  // scripting off), and clips spurious horizontal overflow from the iframe
  // scrollbar. Caller is responsible for stripScripts() before calling.
  function rebuild(doc, snap) {
    try {
      doc.open();
      doc.write("<!DOCTYPE html><html><head></head><body></body></html>");
      doc.close();
    } catch (e) {}
    if (!window.rrwebSnapshot || !window.rrwebSnapshot.rebuild) return false;
    try {
      window.rrwebSnapshot.rebuild(snap, { doc: doc });
    } catch (e) { return false; }
    var noscripts = doc.querySelectorAll("noscript");
    for (var n = 0; n < noscripts.length; n++) {
      if (noscripts[n].parentNode) noscripts[n].parentNode.removeChild(noscripts[n]);
    }
    var head = doc.head || doc.getElementsByTagName("head")[0];
    if (head) {
      var style = doc.createElement("style");
      style.textContent = "html{overflow-x:hidden}";
      head.appendChild(style);
    }
    return true;
  }

  // Precompute the geometry needed to map document points into the overlay that
  // sits over `frame`. `overlay` is the pinned element (canvas or marker layer)
  // whose top-left the returned x/y are relative to. Returns null if the frame
  // doc isn't ready or has no size. Computed once per paint, then fed to point()
  // for each click so the per-click cost is just arithmetic.
  function geom(frame, overlay) {
    var doc = frame.contentDocument || (frame.contentWindow && frame.contentWindow.document) || null;
    if (!doc) return null;
    var root = doc.documentElement, body = doc.body;
    var fullH = Math.max(root ? root.scrollHeight : 0, body ? body.scrollHeight : 0);
    var fullW = Math.max(root ? root.scrollWidth : 0, body ? body.scrollWidth : 0);
    if (!fullH || !fullW) return null;
    var win = frame.contentWindow;
    var sy = (win && win.pageYOffset) || (root && root.scrollTop) || 0;
    var sx = (win && win.pageXOffset) || (root && root.scrollLeft) || 0;
    var fr = frame.getBoundingClientRect();
    var or = overlay.getBoundingClientRect();
    // viewW is the capture-time viewport width (the frame is sized to it). x_frac
    // is recorded as a fraction of that viewport, so horizontal placement maps
    // against viewW, NOT the document scrollWidth (fullW) — otherwise clicks land
    // past the frame whenever the rebuilt snapshot overflows horizontally.
    var viewW = frame.clientWidth || fr.width || fullW;
    return { fullW: fullW, fullH: fullH, viewW: viewW, sx: sx, sy: sy, ox: fr.left - or.left, oy: fr.top - or.top };
  }

  // Map one recorded click to an overlay pixel point using a geom() result.
  // dh is the document height captured WITH that click (per-event), so clicks
  // recorded at different page heights still land correctly. Returns null when
  // dh is missing (can't normalize vertically).
  function point(g, xFrac, yPx, dh) {
    if (!g || !dh) return null;
    return {
      x: (xFrac / 1000) * g.viewW + g.ox,
      y: (yPx / dh) * g.fullH - g.sy + g.oy
    };
  }

  // Resolve a recorded selector against the backdrop, tolerating ancestor drift.
  // The full path can fail when the snapshot's ancestor structure differs from
  // the page at capture time (wrapper nodes, nth-child shifts) even though the
  // target is still present. Fall back to the longest right-anchored suffix that
  // pins exactly one element; an ambiguous suffix is left unmatched rather than
  // risk pointing at the wrong node. Shared by the aggregate heatmap and the
  // single-session trail so both resolve clicks the same way.
  function resolveSelector(doc, sel) {
    if (!sel) return null;
    try {
      var el = doc.querySelector(sel);
      if (el) return el;
    } catch (e) {}
    var segs = sel.split(" > ");
    for (var k = 1; k < segs.length; k++) {
      var suffix = segs.slice(k).join(" > ");
      var matches;
      try { matches = doc.querySelectorAll(suffix); } catch (e) { matches = null; }
      if (matches && matches.length === 1) return matches[0];
    }
    return null;
  }

  // True when an element is present but renders no box (zero-size) — the signal
  // that the page hid it (e.g. a closed off-canvas menu the replay iframe can't
  // open, since it runs no scripts).
  function isHidden(el) {
    if (!el) return false;
    var r = el.getBoundingClientRect();
    return r.width === 0 && r.height === 0;
  }

  // Force the hidden ancestors of `el` visible so a recorded interaction on it
  // can be seen. The replay iframe runs no JS, so panels the page's own script
  // would open (UIKit off-canvas, dropdowns) stay collapsed; override the
  // display/visibility/transform that hides them with inline !important. Returns
  // a restore() that puts every touched node's style back exactly as it was.
  function reveal(el, win) {
    var changed = [];  // inline-style restores
    var classed = [];  // uk-open additions to restore
    var doc = el && el.ownerDocument;
    var body = doc && doc.body;
    var node = el;
    while (node && node.nodeType === 1 && node !== body) {
      // UIKit off-canvas: the container is display:none and the bar is parked
      // off-screen at left:-Npx (not transform/visibility). Flip the framework's
      // own open class so its `.uk-open > .uk-offcanvas-bar { left:0 }` rule
      // slides the bar in — robust to whatever width (270/350) is configured.
      if (node.classList && node.classList.contains("uk-offcanvas") && !node.classList.contains("uk-open")) {
        node.classList.add("uk-open");
        classed.push(node);
      }
      var cs = win.getComputedStyle(node);
      var fixes = {};
      if (cs.display === "none") fixes.display = "block";
      if (cs.visibility === "hidden") fixes.visibility = "visible";
      if (cs.transform && cs.transform !== "none") fixes.transform = "none";
      var keys = Object.keys(fixes);
      if (keys.length) {
        changed.push({ node: node, cssText: node.style.cssText });
        for (var i = 0; i < keys.length; i++) node.style.setProperty(keys[i], fixes[keys[i]], "important");
      }
      node = node.parentNode;
    }
    return function restore() {
      for (var j = 0; j < changed.length; j++) changed[j].node.style.cssText = changed[j].cssText;
      for (var k = 0; k < classed.length; k++) classed[k].classList.remove("uk-open");
    };
  }

  window.NABStage = {
    stripScripts: stripScripts,
    rebuild: rebuild,
    geom: geom,
    point: point,
    resolveSelector: resolveSelector,
    isHidden: isHidden,
    reveal: reveal
  };
})();
