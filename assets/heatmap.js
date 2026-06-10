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
    stripSnapshotScripts(snap);

    var clicks = data.clicks || [];
    var scroll = data.scroll || [];
    var coords = data.coords || [];
    var captureWidth = parseInt(data.captureWidth, 10) || 1280;
    var heatMode = "outlines";

    // Recursively drop <script> element nodes from an rrweb-snapshot tree
    // (NodeType 2 = Element). Mirrors the collector's capture-time strip so
    // snapshots stored before that existed are also safe to rebuild.
    function stripSnapshotScripts(n) {
      if (!n || !n.childNodes || !n.childNodes.length) return;
      n.childNodes = n.childNodes.filter(function (c) {
        return !(c && c.type === 2 && c.tagName === "script");
      });
      for (var i = 0; i < n.childNodes.length; i++) stripSnapshotScripts(n.childNodes[i]);
    }

    function rebuildInto(doc) {
      try {
        doc.open();
        doc.write("<!DOCTYPE html><html><head></head><body></body></html>");
        doc.close();
      } catch (e) {}
      if (!window.rrwebSnapshot || !window.rrwebSnapshot.rebuild) return false;
      try {
        window.rrwebSnapshot.rebuild(snap, { doc: doc });
      } catch (e) { return false; }
      // The page was captured with scripting on, so <noscript> blocks (e.g. the
      // GTM fallback iframe) were stored as inert text. This iframe replays them
      // with scripting off, which makes the browser render that text visibly.
      // Drop them — they're irrelevant to the heatmap backdrop.
      var noscripts = doc.querySelectorAll("noscript");
      for (var n = 0; n < noscripts.length; n++) {
        if (noscripts[n].parentNode) noscripts[n].parentNode.removeChild(noscripts[n]);
      }
      // The iframe's vertical scrollbar (classic ~15px on desktop) eats into the
      // viewport width, so full-width (100vw) elements captured on a scrollbar-
      // less mobile viewport overflow horizontally and add a spurious horizontal
      // scrollbar. Clip that overflow with overflow-x:hidden — the few stray px
      // are an artifact, not real content. overflow-y computes to auto, so the
      // vertical scrollbar stays visible as the scroll affordance.
      var head = doc.head || doc.getElementsByTagName("head")[0];
      if (head) {
        var style = doc.createElement("style");
        style.textContent = "html{overflow-x:hidden}";
        head.appendChild(style);
      }
      return true;
    }

    function frameDoc() {
      return frame.contentDocument || (frame.contentWindow && frame.contentWindow.document) || null;
    }

    // Resolve a recorded selector against the backdrop, tolerating ancestor
    // drift. The full path can fail when the snapshot's ancestor structure
    // differs from the page at click time (UIKit wrapper nodes, nth-child
    // shifts) even though the clicked element is still present. Fall back to the
    // longest right-anchored suffix that pins exactly one element — a unique
    // match is the same element; an ambiguous one is left unmatched rather than
    // risk drawing heat on the wrong node.
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
      var meta = document.querySelector(".nab-snapshot-meta");
      if (!meta) return;
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
        meta.appendChild(legend);
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

      var fr = frame.getBoundingClientRect();
      var cr = canvas.getBoundingClientRect();
      var ox = fr.left - cr.left;
      var oy = fr.top - cr.top;

      if (heatMode === "density") {
        drawDensity(ctx, w, h, ox, oy);
        drawScrollLines(ctx, w, h, oy);
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

      var unmatched = 0;
      for (i = 0; i < clicks.length; i++) {
        var sel = clicks[i].selector;
        var count = parseInt(clicks[i].c, 10) || 0;
        var el = resolveSelector(doc, sel);
        if (!el) { unmatched += count; continue; }
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
      }

      drawScrollLines(ctx, w, h, oy);

      var status = document.getElementById("nab-unmatched");
      if (status) {
        status.textContent = unmatched > 0
          ? unmatched + " click(s) not matched to the current layout (element absent or restructured)."
          : "";
      }
    }

    // Scroll-fold shading: tint the backdrop progressively darker the fewer
    // sessions reached each depth, so the "fold" — where most visitors stopped —
    // reads at a glance. Painted under the click outlines and scroll rules. The
    // tint maps document depth to canvas Y (matching the dashed-line math), with
    // a per-bucket alpha of (1 - cumulative reach) * MAX_ALPHA: the top everyone
    // saw stays clear, the rarely-seen tail goes dark.
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
      var grad = ctx.createLinearGradient(0, top, 0, bottom);
      for (var b = 0; b <= 10; b++) {
        var reached = 0;
        for (j = b; j < scroll.length; j++) reached += parseInt(scroll[j], 10) || 0;
        var alpha = (1 - reached / total) * SHADE_MAX_ALPHA;
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
    function drawDensity(ctx, w, h, ox, oy) {
      if (!coords.length) return;
      var doc = frameDoc();
      if (!doc) return;
      var root = doc.documentElement, body = doc.body;
      var fullH = Math.max(root ? root.scrollHeight : 0, body ? body.scrollHeight : 0);
      var fullW = Math.max(root ? root.scrollWidth : 0, body ? body.scrollWidth : 0);
      if (!fullH || !fullW) return;
      var win = frame.contentWindow;
      var sy = (win && win.pageYOffset) || (root && root.scrollTop) || 0;
      var sx = (win && win.pageXOffset) || (root && root.scrollLeft) || 0;

      var off = document.createElement("canvas");
      off.width = w;
      off.height = h;
      var octx = off.getContext("2d");
      var i, c, dh, x, y, g;
      for (i = 0; i < coords.length; i++) {
        c = coords[i];
        dh = c[2] || 0;
        if (dh <= 0) continue;
        x = (c[0] / 1000) * fullW - sx + ox;
        y = (c[1] / dh) * fullH - sy + oy;
        if (x < -DENSITY_RADIUS || x > w + DENSITY_RADIUS) continue;
        if (y < -DENSITY_RADIUS || y > h + DENSITY_RADIUS) continue;
        g = octx.createRadialGradient(x, y, 0, x, y, DENSITY_RADIUS);
        g.addColorStop(0, "rgba(0,0,0,0.18)");
        g.addColorStop(1, "rgba(0,0,0,0)");
        octx.fillStyle = g;
        octx.fillRect(x - DENSITY_RADIUS, y - DENSITY_RADIUS, DENSITY_RADIUS * 2, DENSITY_RADIUS * 2);
      }

      var img = octx.getImageData(0, 0, w, h);
      var d = img.data, p, maxA = 0;
      for (p = 3; p < d.length; p += 4) { if (d[p] > maxA) maxA = d[p]; }
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
          level: el.nodeName.toLowerCase() === "h2" ? 2 : 1
        });
      }
      renderSectionTable(container, rows);
    }

    function renderSectionTable(container, rows) {
      container.innerHTML = "";
      if (!rows.length) return;
      var table = document.createElement("table");
      table.className = "nab-click-table uk-table uk-table-small uk-table-divider";
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
        tbody.appendChild(tr);
      }
      table.appendChild(tbody);
      container.appendChild(table);
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

    function setup() {
      frame.style.width = captureWidth + "px";
      var doc = frameDoc();
      if (!doc) return;
      if (!rebuildInto(doc)) return;
      // Allow layout to settle before measuring element boxes, then redraw a
      // couple more times as images load and shift box positions.
      bindHeatToggle();
      bindModeSwitch();
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
