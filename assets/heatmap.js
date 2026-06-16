(function () {
  "use strict";

  function ready(fn) {
    if (document.readyState !== "loading") fn();
    else document.addEventListener("DOMContentLoaded", fn);
  }

  ready(function () {
    var dataEl = document.getElementById("nab-data");
    var snapEl = document.getElementById("nab-snapshot");
    var canvas = document.getElementById("nab-canvas");
    var frame = document.getElementById("nab-frame");
    if (!dataEl || !snapEl || !canvas || !frame) return;

    var data, snap;
    try { data = JSON.parse(dataEl.textContent || "{}"); } catch (e) { return; }
    try { snap = JSON.parse(snapEl.textContent || "null"); } catch (e) { return; }
    if (!snap) return;

    // The backdrop iframe is sandboxed without allow-scripts (deliberate — a
    // replayed page must never run). Any <script> still present in the snapshot
    // is created during rrweb's rebuild and the browser logs a blocked-execution
    // error as it tries (then fails) to run it. The collector strips scripts at
    // capture, but snapshots stored before that shipped still carry them, so
    // strip them from the serialized tree here too — before rebuild, since the
    // execution attempt happens as each node is built.
    NABStage.stripScripts(snap);

    var clicks = data.clicks || [];
    var scroll = data.scroll || [];
    var coords = data.coords || [];
    var captureWidth = parseInt(data.captureWidth, 10) || 1280;
    var heatMode = "outlines";
    var focusEl = null;       // element currently flashed via a table-row click
    var focusTimer = null;
    var revealRestore = null; // undoes any off-canvas reveal done for the focus
    var boxes = [];           // last-drawn outline boxes {rx,ry,w,h,label,sel,count} in iframe-viewport coords, for hover tooltips
    var tip = null;           // floating label tooltip shown for the box under the cursor

    function rebuildInto(doc) {
      return NABStage.rebuild(doc, snap);
    }

    function frameDoc() {
      return frame.contentDocument || (frame.contentWindow && frame.contentWindow.document) || null;
    }

    // Scroll the iframe (vertical) and stage (horizontal) so an element resolved
    // in the backdrop lands roughly centred in the visible window. Rects are read
    // before scrolling, so they're relative to the current iframe/stage viewport.
    function scrollToElement(el) {
      if (!el) return;
      var r = el.getBoundingClientRect();
      var win = frame.contentWindow;
      if (win) {
        var targetY = (win.pageYOffset || 0) + r.top + r.height / 2 - frame.clientHeight / 2;
        win.scrollTo(0, Math.max(0, targetY));
      }
      var stage = canvas.parentNode;
      if (stage) {
        var targetX = stage.scrollLeft + r.left + r.width / 2 - stage.clientWidth / 2;
        stage.scrollLeft = Math.max(0, targetX);
        // The element is now centred inside the iframe, but the stage itself may
        // sit below the admin-page fold (the tables are above it). Scroll the
        // outer page so the stage — and thus the centred element — is on screen.
        if (stage.scrollIntoView) stage.scrollIntoView({ behavior: "smooth", block: "center" });
      }
    }

    // Scroll an element into view and flash a highlight box around it for a few
    // seconds. Used when an admin clicks a row in any of the tables. The box is
    // painted by drawHeat (so it tracks scroll); the timer clears it and redraws.
    function focusElement(el) {
      if (!el) return;
      clearReveal();
      // If the element is hidden (e.g. inside a closed off-canvas menu the
      // scriptless iframe can't open), force its panel visible so it can be seen.
      if (NABStage.isHidden(el)) revealRestore = NABStage.reveal(el, frame.contentWindow);
      focusEl = el;
      scrollToElement(el);
      drawHeat();
      if (focusTimer) clearTimeout(focusTimer);
      focusTimer = setTimeout(function () { focusEl = null; clearReveal(); drawHeat(); }, 2500);
    }

    function clearReveal() {
      if (revealRestore) { revealRestore(); revealRestore = null; }
    }

    function focusSelector(sel) {
      var doc = frameDoc();
      if (!doc) return;
      focusElement(NABStage.resolveSelector(doc, sel));
    }

    // Bright box around the currently focused element, drawn on top of the heat so
    // a table-row click is easy to spot. Re-run by drawHeat on every scroll.
    function drawFocus(ctx, ox, oy) {
      if (!focusEl) return;
      var r = focusEl.getBoundingClientRect();
      if (r.width === 0 && r.height === 0) return;
      ctx.save();
      ctx.strokeStyle = "#0C7896";
      ctx.setLineDash([]);
      ctx.lineWidth = 4;
      ctx.strokeRect(r.left + ox - 2, r.top + oy - 2, r.width + 4, r.height + 4);
      ctx.restore();
    }

    // Wire the server-rendered "Top interactions" table rows so clicking one
    // scrolls the backdrop to that element. Each row carries its recorded
    // selector in data-nab-sel. Section-reach rows are wired separately in
    // renderSectionTable (they hold a live element reference, not a selector).
    function bindTableLinks() {
      var rows = document.querySelectorAll("[data-nab-sel]");
      for (var i = 0; i < rows.length; i++) {
        bindSelectorRow(rows[i]);
      }
    }
    function bindSelectorRow(row) {
      var sel = row.getAttribute("data-nab-sel");
      if (!sel) return;
      row.addEventListener("click", function () { focusSelector(sel); });
      row.addEventListener("keydown", function (e) {
        if (e.key === "Enter" || e.key === " ") { e.preventDefault(); focusSelector(sel); }
      });
    }

    // Cold-to-hot ramp for click volume: blue (cold) -> cyan -> green -> amber
    // -> red (hot). t is 0..1; returns [r,g,b]. Linear interpolation between the
    // five stops keeps the gradient continuous and matches the dashboard legend.
    var HEAT_STOPS = [
      [40, 90, 200],
      [0, 170, 200],
      [40, 180, 60],
      [240, 190, 30],
      [220, 40, 30]
    ];
    function heatColor(t) {
      if (t < 0) t = 0; else if (t > 1) t = 1;
      var span = HEAT_STOPS.length - 1;
      var pos = t * span;
      var i = Math.floor(pos);
      if (i >= span) return HEAT_STOPS[span].slice();
      var f = pos - i;
      var a = HEAT_STOPS[i], b = HEAT_STOPS[i + 1];
      return [
        Math.round(a[0] + (b[0] - a[0]) * f),
        Math.round(a[1] + (b[1] - a[1]) * f),
        Math.round(a[2] + (b[2] - a[2]) * f)
      ];
    }

    // Build the legend once (a gradient bar mirroring HEAT_STOPS) and keep its
    // caption and end labels in sync with the active mode: per-element click
    // counts (1..max) for outlines, or a relative Low..High ramp for density.
    function renderLegend(maxC) {
      var controls = document.querySelector(".nab-stage-controls");
      if (!controls) return;
      var legend = document.getElementById("nab-legend");
      if (!legend) {
        legend = document.createElement("span");
        legend.id = "nab-legend";
        var cap = document.createElement("span");
        cap.className = "nab-legend-cap";
        var lo = document.createElement("span");
        lo.className = "nab-legend-lo";
        var bar = document.createElement("span");
        bar.className = "nab-legend-bar";
        var hi = document.createElement("span");
        hi.className = "nab-legend-hi";
        legend.appendChild(cap);
        legend.appendChild(lo);
        legend.appendChild(bar);
        legend.appendChild(hi);
        controls.appendChild(legend);
      }
      var capEl = legend.querySelector(".nab-legend-cap");
      var loEl = legend.querySelector(".nab-legend-lo");
      var hiEl = legend.querySelector(".nab-legend-hi");
      if (heatMode === "density") {
        if (capEl) capEl.textContent = "Click density ";
        if (loEl) loEl.textContent = "Low";
        if (hiEl) hiEl.textContent = "High";
      } else {
        if (capEl) capEl.textContent = "Clicks per element ";
        if (loEl) loEl.textContent = "1";
        if (hiEl) hiEl.textContent = String(maxC);
      }
    }

    // The heat layer is the parent-level #nab-canvas, a sibling of the iframe in
    // the admin DOM. It always paints on top of the iframe element, so it can't
    // be buried under the rebuilt page's own stacking contexts. Element boxes
    // come from getBoundingClientRect (iframe-viewport-relative); an offset
    // aligns them to the canvas, which also corrects any horizontal/vertical
    // stage scroll. At scroll 0 the offset is 0, matching the original behavior.
    function drawHeat() {
      var doc = frameDoc();
      if (!doc) return;
      var w = frame.clientWidth;
      var h = frame.clientHeight;
      if (!w || !h) return;

      canvas.width = w;
      canvas.height = h;
      // UIKit forces max-width:100% !important on media elements, which would
      // squash this 1477px overlay down to the (narrow) stage width and scale
      // every hotspot. Beat it with inline !important so the canvas renders at
      // its true pixel width and overlaps the iframe 1:1.
      canvas.style.setProperty("max-width", "none", "important");
      canvas.style.setProperty("width", w + "px", "important");
      canvas.style.height = h + "px";
      var ctx = canvas.getContext("2d");
      ctx.clearRect(0, 0, w, h);

      // Rebuilt every draw; drives the hover tooltips. Boxes are stored in
      // iframe-viewport coordinates (the space mousemove on the iframe reports).
      boxes.length = 0;

      var fr = frame.getBoundingClientRect();
      var cr = canvas.getBoundingClientRect();
      var ox = fr.left - cr.left;
      var oy = fr.top - cr.top;

      if (heatMode === "density") {
        drawDensity(ctx, w, h, ox, oy);
        drawScrollLines(ctx, w, h, oy);
        drawFocus(ctx, ox, oy);
        renderLegend(null);
        var dstatus = document.getElementById("nab-unmatched");
        if (dstatus) dstatus.textContent = "";
        return;
      }

      drawScrollShade(ctx, w, h, oy);

      var maxC = 1, i;
      for (i = 0; i < clicks.length; i++) {
        var cc = parseInt(clicks[i].c, 10) || 0;
        if (cc > maxC) maxC = cc;
      }
      renderLegend(maxC);

      var unmatched = 0, overlay = 0;
      for (i = 0; i < clicks.length; i++) {
        var sel = clicks[i].selector;
        var count = parseInt(clicks[i].c, 10) || 0;
        var el = NABStage.resolveSelector(doc, sel);
        if (!el) { unmatched += count; continue; }
        if (isFixedSelector(doc, sel)) { overlay += count; continue; }
        var r = el.getBoundingClientRect();
        if (r.width === 0 || r.height === 0) continue;
        var x = r.left + ox;
        var y = r.top + oy;
        // Outline the clicked element, coloured by click volume along a
        // cold-to-hot ramp. A border (not a fill) keeps the content legible and
        // avoids translucent overlaps of nested boxes blending into muddy hues.
        var rgb = heatColor(maxC > 0 ? count / maxC : 0);
        ctx.strokeStyle = "rgb(" + rgb[0] + "," + rgb[1] + "," + rgb[2] + ")";
        ctx.lineWidth = 3;
        ctx.strokeRect(x + 1.5, y + 1.5, r.width - 3, r.height - 3);
        boxes.push({ rx: r.left, ry: r.top, w: r.width, h: r.height, label: clicks[i].label || "", sel: sel, count: count });
      }

      drawScrollLines(ctx, w, h, oy);
      drawFocus(ctx, ox, oy);

      var status = document.getElementById("nab-unmatched");
      if (status) {
        var notes = [];
        if (unmatched > 0) notes.push(unmatched + " click(s) not matched to the current layout (element absent or restructured).");
        if (overlay > 0) notes.push(overlay + " click(s) on fixed overlays (e.g. cookie banner) shown in the table only.");
        status.textContent = notes.join(" ");
      }
    }

    // Scroll-fold shading: tint the backdrop progressively darker the fewer
    // sessions reached each depth, so the "fold" — where most visitors stopped —
    // reads at a glance. Painted under the click outlines and scroll rules, mapping
    // document depth to canvas Y (matching the dashed-line math).
    //
    // The alpha is contrast-stretched across the OBSERVED reach range rather than a
    // flat 0..100%: clear at the most-reached depth, full tint at the deepest depth
    // anyone actually reached, and pinned dark below that. Real scroll curves are
    // top-loaded (almost everyone sees the top, a steep drop, then a thin tail), so
    // a flat ramp washes the drop-off zone into a smooth gradient. Spreading the
    // alpha across [minReach, maxReach] puts the whole contrast budget where the
    // reach actually varies, making the fold pop.
    var SHADE_MAX_ALPHA = 0.45;
    function drawScrollShade(ctx, w, h, oy) {
      var total = 0, j;
      for (j = 0; j < scroll.length; j++) total += parseInt(scroll[j], 10) || 0;
      if (total === 0) return;
      var doc = frameDoc();
      if (!doc) return;
      var root = doc.documentElement, body = doc.body;
      var fullH = Math.max(root ? root.scrollHeight : 0, body ? body.scrollHeight : 0);
      if (!fullH) return;
      var win = frame.contentWindow;
      var sy = (win && win.pageYOffset) || (root && root.scrollTop) || 0;
      var top = oy - sy;
      var bottom = fullH - sy + oy;
      if (bottom - top < 1) return;
      // Cumulative reach fraction at each stop, plus the observed range to stretch
      // across. minReach ignores the artificial trailing zeros below the deepest
      // reached bucket so those pin to full tint instead of skewing the range.
      var reach = [], maxReach = 0, minReach = 1, b;
      for (b = 0; b <= 10; b++) {
        var reached = 0;
        for (j = b; j < scroll.length; j++) reached += parseInt(scroll[j], 10) || 0;
        var r = reached / total;
        reach.push(r);
        if (r > maxReach) maxReach = r;
        if (r > 0 && r < minReach) minReach = r;
      }
      var span = maxReach - minReach;
      var grad = ctx.createLinearGradient(0, top, 0, bottom);
      for (b = 0; b <= 10; b++) {
        var t = span > 0 ? (maxReach - reach[b]) / span : (1 - reach[b]);
        if (t < 0) t = 0; else if (t > 1) t = 1;
        var alpha = t * SHADE_MAX_ALPHA;
        grad.addColorStop(b / 10, "rgba(20,30,55," + alpha.toFixed(3) + ")");
      }
      ctx.save();
      ctx.fillStyle = grad;
      ctx.fillRect(0, 0, w, h);
      ctx.restore();
    }

    // A 256-entry lookup of the HEAT_STOPS ramp, built once. Maps a normalized
    // density (0..1) to an [r,g,b] without recomputing the interpolation per pixel.
    var PALETTE = null;
    function buildPalette() {
      var pal = new Uint8Array(256 * 3);
      for (var i = 0; i < 256; i++) {
        var rgb = heatColor(i / 255);
        pal[i * 3] = rgb[0];
        pal[i * 3 + 1] = rgb[1];
        pal[i * 3 + 2] = rgb[2];
      }
      return pal;
    }

    // Classic pixel-coordinate click-density heatmap. Each recorded click
    // (x_frac 0..1000 across page width; y_px absolute, normalized by the page
    // height at click time) maps to a document point, then to canvas space using
    // the same depth/offset math as the scroll rules so it tracks the backdrop.
    // Density is accumulated as stacked translucent blobs on an offscreen canvas,
    // then recoloured per-pixel through the HEAT_STOPS palette and composited.
    var DENSITY_RADIUS = 28;
    var DENSITY_MAX_ALPHA = 0.85;

    // Stamp one click's translucent radial blob; stacking these accumulates the
    // density that's later recoloured through the palette.
    function paintBlob(octx, x, y, radius) {
      var g = octx.createRadialGradient(x, y, 0, x, y, radius);
      g.addColorStop(0, "rgba(0,0,0,0.18)");
      g.addColorStop(1, "rgba(0,0,0,0)");
      octx.fillStyle = g;
      octx.fillRect(x - radius, y - radius, radius * 2, radius * 2);
    }

    // Document-space point for a click, anchored to the clicked element when its
    // selector still resolves in the rebuilt backdrop (so it tracks the live
    // layout instead of drifting). offx/offy are 0..1000 fractions of the
    // element's box captured at click time (pre-offset rows default to the
    // centre). Returns null to signal "fall back to the recorded coordinates"
    // (selector absent/restructured), letting the caller use NABStage.point.
    // Resolve a selector against the (static, built-once) backdrop, memoized for
    // the life of the loaded snapshot. Density's coords are per-click (thousands),
    // and many share a selector, so without this each scroll redraw would run a
    // querySelector per click. The resolved element is stable; only its rect
    // (read live via getBoundingClientRect) changes with layout/scroll.
    var selCache = {};
    function resolveCached(doc, sel) {
      if (Object.prototype.hasOwnProperty.call(selCache, sel)) return selCache[sel];
      selCache[sel] = NABStage.resolveSelector(doc, sel) || null;
      return selCache[sel];
    }

    // A position:fixed element (e.g. a cookie-consent banner like PrivacyWire) is
    // anchored to the viewport, not the page, so getBoundingClientRect stays
    // constant under scroll: its outline can't be placed faithfully on the
    // scrolling static backdrop — it orphans over unrelated content and never
    // moves. Those clicks are real signal and stay in the interactions table, but
    // the heatmap skips them. The climb up the ancestor chain matters: the click
    // lands on a button/link in normal flow (position:static) inside the banner —
    // only the banner *container* is fixed. Memoized: position is stable for a
    // built-once snapshot. Sticky is kept — it tracks the page within its range.
    var fixedCache = {};
    function isFixedSelector(doc, sel) {
      if (!sel) return false;
      if (Object.prototype.hasOwnProperty.call(fixedCache, sel)) return fixedCache[sel];
      var win = frame.contentWindow;
      var node = resolveCached(doc, sel);
      var root = doc.documentElement;
      var fixed = false;
      while (node && node !== root && win && win.getComputedStyle) {
        if (win.getComputedStyle(node).position === "fixed") { fixed = true; break; }
        node = node.parentElement;
      }
      return (fixedCache[sel] = fixed);
    }

    function elementDocPoint(c, doc, win) {
      var sel = c[5];
      if (!sel) return null;
      var el = resolveCached(doc, sel);
      if (!el) return null;
      var r = el.getBoundingClientRect();
      if (r.width <= 0 && r.height <= 0) return null;
      var ofx = (typeof c[3] === "number" ? c[3] : 500) / 1000;
      var ofy = (typeof c[4] === "number" ? c[4] : 500) / 1000;
      var sx = (win && win.pageXOffset) || 0;
      var sy = (win && win.pageYOffset) || 0;
      return { x: r.left + sx + ofx * r.width, y: r.top + sy + ofy * r.height };
    }

    // Peak accumulated alpha across the WHOLE document, so the colour scale is
    // fixed: a cluster keeps its temperature no matter what else is on screen.
    // Scrolling re-renders only the visible canvas, so normalizing against that
    // visible max made the same cluster change colour as you scrolled. Computed
    // once per backdrop size and cached. Scaling coords and radius by the same
    // factor preserves the overlap pattern (so the peak is unchanged) while a
    // capped offscreen canvas bounds memory on very tall pages.
    var densityMax = -1, densityMaxKey = "";
    function globalDensityMax(fullW, fullH) {
      var key = fullW + "x" + fullH + ":" + coords.length;
      if (densityMaxKey === key) return densityMax;
      var scale = fullH > 4000 ? 4000 / fullH : 1;
      var cw = Math.max(1, Math.round(fullW * scale));
      var ch = Math.max(1, Math.round(fullH * scale));
      var radius = DENSITY_RADIUS * scale;
      var off = document.createElement("canvas");
      off.width = cw;
      off.height = ch;
      var octx = off.getContext("2d");
      var doc = frameDoc();
      var win = frame.contentWindow;
      var i, c, dh, dp;
      // Plot with the SAME element anchoring as the visible render, so the peak
      // this normalizes against matches what's actually drawn. A coordinate-based
      // max would understate element clusters and let hot spots saturate to full
      // red. Element points are document-space; scale them like the coordinate
      // fallback so the offscreen overlap pattern (and thus the peak) is faithful.
      for (i = 0; i < coords.length; i++) {
        c = coords[i];
        if (doc && isFixedSelector(doc, c[5])) continue;
        dp = doc ? elementDocPoint(c, doc, win) : null;
        if (dp) {
          paintBlob(octx, dp.x * scale, dp.y * scale, radius);
          continue;
        }
        dh = c[2] || 0;
        if (dh <= 0) continue;
        paintBlob(octx, (c[0] / 1000) * fullW * scale, (c[1] / dh) * fullH * scale, radius);
      }
      var d = octx.getImageData(0, 0, cw, ch).data, p, maxA = 0;
      for (p = 3; p < d.length; p += 4) { if (d[p] > maxA) maxA = d[p]; }
      densityMaxKey = key;
      densityMax = maxA;
      return maxA;
    }

    function drawDensity(ctx, w, h, ox, oy) {
      if (!coords.length) return;
      var doc = frameDoc();
      if (!doc) return;
      var g = NABStage.geom(frame, canvas);
      if (!g) return;
      var fullW = g.fullW, fullH = g.fullH;

      var off = document.createElement("canvas");
      off.width = w;
      off.height = h;
      var octx = off.getContext("2d");
      var win = frame.contentWindow;
      var sx = (win && win.pageXOffset) || 0;
      var sy = (win && win.pageYOffset) || 0;
      var i, c, dh, pt, dp;
      for (i = 0; i < coords.length; i++) {
        c = coords[i];
        if (isFixedSelector(doc, c[5])) continue;
        // Prefer anchoring the blob to the clicked element (document-space point
        // converted to canvas space); fall back to the recorded page-fraction
        // coordinates when the selector no longer resolves.
        dp = elementDocPoint(c, doc, win);
        if (dp) {
          pt = { x: dp.x - sx + ox, y: dp.y - sy + oy };
        } else {
          dh = c[2] || 0;
          if (dh <= 0) continue;
          pt = NABStage.point(g, c[0], c[1], dh);
          if (!pt) continue;
        }
        if (pt.x < -DENSITY_RADIUS || pt.x > w + DENSITY_RADIUS) continue;
        if (pt.y < -DENSITY_RADIUS || pt.y > h + DENSITY_RADIUS) continue;
        paintBlob(octx, pt.x, pt.y, DENSITY_RADIUS);
      }

      var img = octx.getImageData(0, 0, w, h);
      var d = img.data, p;
      var maxA = globalDensityMax(fullW, fullH);
      if (maxA === 0) return;
      if (!PALETTE) PALETTE = buildPalette();
      for (p = 0; p < d.length; p += 4) {
        var a = d[p + 3];
        if (a === 0) continue;
        var t = a / maxA;
        if (t > 1) t = 1;
        var idx = (t * 255) | 0;
        d[p] = PALETTE[idx * 3];
        d[p + 1] = PALETTE[idx * 3 + 1];
        d[p + 2] = PALETTE[idx * 3 + 2];
        d[p + 3] = Math.round(Math.min(1, t * 1.2) * DENSITY_MAX_ALPHA * 255);
      }
      octx.putImageData(img, 0, 0);
      ctx.drawImage(off, 0, 0);
    }

    // Cumulative scroll-reach rules: a dashed line at each 10% of document depth
    // labelled with the percentage of sessions that scrolled at least that far.
    // Anchored to document coordinates (depth - iframe scroll) so the lines track
    // the backdrop; labels are pinned to the visible right edge of the stage so
    // they stay on-screen at any horizontal scroll.
    function drawScrollLines(ctx, w, h, oy) {
      var total = 0, j;
      for (j = 0; j < scroll.length; j++) total += parseInt(scroll[j], 10) || 0;
      if (total === 0) return;
      var doc = frameDoc();
      if (!doc) return;
      var root = doc.documentElement, body = doc.body;
      var fullH = Math.max(root ? root.scrollHeight : 0, body ? body.scrollHeight : 0);
      if (!fullH) return;
      var win = frame.contentWindow;
      var sy = (win && win.pageYOffset) || (root && root.scrollTop) || 0;
      var stage = canvas.parentNode;
      var visLeft = stage ? stage.scrollLeft : 0;
      var visW = stage ? stage.clientWidth : w;

      ctx.save();
      ctx.font = "600 12px -apple-system, system-ui, sans-serif";
      ctx.textBaseline = "middle";
      for (var b = 1; b <= 10; b++) {
        var reached = 0;
        for (j = b; j < scroll.length; j++) reached += parseInt(scroll[j], 10) || 0;
        var pct = Math.round((reached / total) * 100);
        var y = (b / 10) * fullH - sy + oy;
        if (y < 9 || y > h - 2) continue;

        ctx.strokeStyle = "rgba(11,120,150,0.8)";
        ctx.lineWidth = 1;
        ctx.setLineDash([6, 4]);
        ctx.beginPath();
        ctx.moveTo(0, y);
        ctx.lineTo(w, y);
        ctx.stroke();
        ctx.setLineDash([]);

        var label = pct + "%";
        var tw = ctx.measureText(label).width;
        var pillW = tw + 12, pillH = 18;
        // Pin to the visible right edge, but never past the canvas: a narrow
        // (mobile) backdrop makes the canvas narrower than the stage, so the
        // stage's right edge would fall off the canvas bitmap and clip the pill.
        var px = Math.min(visLeft + visW, w) - pillW - 8;
        ctx.fillStyle = "rgba(11,120,150,0.92)";
        ctx.fillRect(px, y - pillH / 2, pillW, pillH);
        ctx.fillStyle = "#fff";
        ctx.fillText(label, px + 6, y + 1);
      }
      ctx.restore();
    }

    // Human-readable label for a backdrop section row. Forms report their
    // name/id; headings report their text with inline <style>/<svg> stripped
    // (UIKit icon <style> blocks otherwise leak CSS into the text).
    function sectionLabel(el) {
      if (el.nodeName.toLowerCase() === "form") {
        // Skip anonymous forms (e.g. a header search box): a bare "Form" row
        // tells you nothing. Only name/id-bearing forms are worth listing.
        var n = (el.getAttribute("name") || el.getAttribute("id") || "").replace(/\s+/g, " ").trim();
        return n ? "Form: " + n : "";
      }
      var clone = el.cloneNode(true);
      var junk = clone.querySelectorAll("style, script, svg");
      for (var i = 0; i < junk.length; i++) {
        if (junk[i].parentNode) junk[i].parentNode.removeChild(junk[i]);
      }
      return (clone.textContent || "").replace(/\s+/g, " ").trim().slice(0, 80);
    }

    // Build the section-reach table: for each h1/h2/h3 and form in the backdrop,
    // the share of sessions that scrolled far enough to see it. A section's depth
    // is its document offset over full document height, snapped to the nearest
    // 10% bucket of the scroll histogram (the only granularity captured), then
    // reach = sessions in that bucket or deeper. Rebuilt on the same layout
    // settle/resize ticks as the heat, since image loads shift offsets.
    function buildSectionTable() {
      var container = document.getElementById("nab-scroll-sections");
      if (!container) return;
      var doc = frameDoc();
      if (!doc) return;
      var total = 0, j;
      for (j = 0; j < scroll.length; j++) total += parseInt(scroll[j], 10) || 0;
      if (total === 0) { container.innerHTML = ""; return; }
      var root = doc.documentElement, body = doc.body;
      var fullH = Math.max(root ? root.scrollHeight : 0, body ? body.scrollHeight : 0);
      if (!fullH) return;
      var win = frame.contentWindow;
      var sy = (win && win.pageYOffset) || 0;
      var nodes = doc.querySelectorAll("h1, h2, form");
      var rows = [];
      for (var i = 0; i < nodes.length && rows.length < 40; i++) {
        var el = nodes[i];
        var label = sectionLabel(el);
        if (!label) continue;
        var r = el.getBoundingClientRect();
        if (r.width === 0 && r.height === 0) continue;
        var frac = (r.top + sy) / fullH;
        if (frac < 0) frac = 0; else if (frac > 1) frac = 1;
        var bucket = Math.round(frac * 10);
        if (bucket < 0) bucket = 0; else if (bucket > 10) bucket = 10;
        var reached = 0;
        for (j = bucket; j < scroll.length; j++) reached += parseInt(scroll[j], 10) || 0;
        rows.push({
          label: label,
          pct: Math.round((reached / total) * 100),
          level: el.nodeName.toLowerCase() === "h2" ? 2 : 1,
          el: el
        });
      }
      renderSectionTable(container, rows);
    }

    function renderSectionTable(container, rows) {
      container.innerHTML = "";
      if (!rows.length) return;
      var table = document.createElement("table");
      table.className = "pwna-table nab-click-table";
      var thead = document.createElement("thead");
      thead.innerHTML = "<tr><th>Section reach</th><th class=\"nab-click-num\">Seen by</th></tr>";
      table.appendChild(thead);
      var tbody = document.createElement("tbody");
      for (var i = 0; i < rows.length; i++) {
        var tr = document.createElement("tr");
        var tdL = document.createElement("td");
        tdL.className = "nab-sec-l" + rows[i].level;
        tdL.textContent = rows[i].label;
        var tdN = document.createElement("td");
        tdN.className = "nab-click-num";
        tdN.textContent = rows[i].pct + "%";
        tr.appendChild(tdL);
        tr.appendChild(tdN);
        bindSectionRow(tr, rows[i].el);
        tbody.appendChild(tr);
      }
      table.appendChild(tbody);
      container.appendChild(table);
    }

    // Section-reach rows hold a live heading/form element, so they scroll to it
    // directly (no selector to resolve).
    function bindSectionRow(tr, el) {
      if (!el) return;
      tr.className = "nab-click-row";
      tr.setAttribute("tabindex", "0");
      tr.addEventListener("click", function () { focusElement(el); });
      tr.addEventListener("keydown", function (e) {
        if (e.key === "Enter" || e.key === " ") { e.preventDefault(); focusElement(el); }
      });
    }

    function bindFrameScroll() {
      // Redraw whenever the heat could drift from the elements: scrolling inside
      // the iframe, or scrolling the stage that holds the pinned canvas.
      var win = frame.contentWindow;
      if (win) win.addEventListener("scroll", drawHeat, { passive: true });
      var stage = canvas.parentNode;
      if (stage) stage.addEventListener("scroll", drawHeat, { passive: true });
    }

    // Toggle the whole overlay (click outlines, scroll rules, fold shading) so the
    // clean backdrop can be inspected. Hiding via display keeps the canvas drawn,
    // so flipping it back on is instant with no redraw.
    function bindHeatToggle() {
      var btn = document.getElementById("nab-toggle-heat");
      if (!btn) return;
      btn.addEventListener("click", function () {
        var on = canvas.style.display !== "none";
        canvas.style.display = on ? "none" : "";
        if (on && tip) tip.hidden = true;
        btn.setAttribute("aria-pressed", on ? "false" : "true");
        btn.textContent = on ? "Show heatmap" : "Hide heatmap";
      });
    }

    // Switch the overlay between element outlines and pixel click-density. Only
    // one view is shown at a time; redraw immediately on change (even if the
    // overlay is currently hidden, so the new mode is ready when shown).
    function bindModeSwitch() {
      var btns = document.querySelectorAll(".nab-mode-btn");
      if (!btns.length) return;
      for (var i = 0; i < btns.length; i++) {
        btns[i].addEventListener("click", function () {
          var mode = this.getAttribute("data-mode");
          if (mode === heatMode) return;
          heatMode = mode;
          for (var k = 0; k < btns.length; k++) {
            btns[k].setAttribute("aria-pressed", btns[k].getAttribute("data-mode") === heatMode ? "true" : "false");
          }
          drawHeat();
        });
      }
    }

    // Hover tooltips that name the box under the cursor with its table label, so
    // a box on the backdrop can be tied back to its interactions-table row. The
    // canvas is pointer-events:none, so mouse events reach the iframe document
    // underneath; listening there (allow-same-origin lets the parent attach the
    // handler) keeps the backdrop scrollable. boxes[] is rebuilt every drawHeat in
    // iframe-viewport coords, matching e.clientX/Y here; the smallest (innermost)
    // box under the cursor wins so nested elements resolve to the most specific.
    function setupHotspots() {
      var fdoc = frameDoc();
      if (!fdoc) return;
      tip = document.createElement("div");
      tip.id = "nab-tip";
      tip.hidden = true;
      document.body.appendChild(tip);
      var hide = function () { if (tip) tip.hidden = true; };
      fdoc.addEventListener("mousemove", function (e) {
        if (canvas.style.display === "none") { hide(); return; }
        var mx = e.clientX, my = e.clientY, best = null;
        for (var i = 0; i < boxes.length; i++) {
          var b = boxes[i];
          if (mx >= b.rx && mx <= b.rx + b.w && my >= b.ry && my <= b.ry + b.h) {
            if (!best || b.w * b.h < best.w * best.h) best = b;
          }
        }
        if (!best) { hide(); return; }
        var fr = frame.getBoundingClientRect();
        tip.textContent = (best.label || best.sel) + " · " + best.count + (best.count === 1 ? " click" : " clicks");
        tip.style.left = (fr.left + mx + 12) + "px";
        tip.style.top = (fr.top + my + 12) + "px";
        tip.hidden = false;
      });
      if (fdoc.documentElement) fdoc.documentElement.addEventListener("mouseleave", hide);

      // The scriptless backdrop still applies CSS, so a :hover dropdown opens when
      // the cursor enters a top-level menu item. Its items are zero-size (skipped
      // by drawHeat) until shown, so redraw on hover enter/leave to outline them
      // while the menu is open. Coalesced to one redraw per frame; skipped in
      // density mode, where the per-frame repaint is too heavy for mouse moves.
      var pending = false;
      var redrawOnHover = function () {
        if (pending || heatMode === "density" || canvas.style.display === "none") return;
        pending = true;
        var run = function () { pending = false; drawHeat(); };
        if (window.requestAnimationFrame) window.requestAnimationFrame(run);
        else window.setTimeout(run, 16);
      };
      fdoc.addEventListener("mouseover", redrawOnHover);
      fdoc.addEventListener("mouseout", redrawOnHover);
    }

    function setup() {
      frame.style.width = captureWidth + "px";
      var doc = frameDoc();
      if (!doc) return;
      if (!rebuildInto(doc)) return;
      // Allow layout to settle before measuring element boxes, then redraw a
      // couple more times as images load and shift box positions.
      bindHeatToggle();
      bindModeSwitch();
      bindTableLinks();
      setupHotspots();
      setTimeout(function () { drawHeat(); buildSectionTable(); bindFrameScroll(); }, 50);
      setTimeout(function () { drawHeat(); buildSectionTable(); }, 400);
      setTimeout(function () { drawHeat(); buildSectionTable(); }, 1200);
      // When embedded in a WireTab, the panel starts display:none, so the frame
      // has zero size at load and every early drawHeat bails. Redraw once the
      // tab is shown and the frame gains real dimensions. drawHeat no-ops on a
      // zero-size frame, so the initial 0->0 callbacks are harmless.
      if (typeof ResizeObserver === "function") {
        new ResizeObserver(function () { drawHeat(); buildSectionTable(); }).observe(frame);
      }
    }

    // Building the backdrop writes the snapshot into the iframe, and the iframe's
    // subresources (the page's responsive images) count toward THIS document's
    // load event. Those images are captured with loading="lazy"/sizes="auto" and
    // can hang or get canceled in the rebuilt doc, which leaves the browser tab
    // spinner running forever. Defer the build until after the parent window has
    // finished loading so the iframe's network activity can never hold the
    // parent's load event open.
    if (document.readyState === "complete") setup();
    else window.addEventListener("load", setup);

    window.addEventListener("resize", drawHeat);
  });
})();
