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

    var clicks = data.clicks || [];
    var scroll = data.scroll || [];
    var captureWidth = parseInt(data.captureWidth, 10) || 1280;

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

    // Build the click-volume legend once (a gradient bar mirroring HEAT_STOPS)
    // and keep its high-end label in sync with the current max click count.
    function renderLegend(maxC) {
      var meta = document.querySelector(".nab-snapshot-meta");
      if (!meta) return;
      var legend = document.getElementById("nab-legend");
      if (!legend) {
        legend = document.createElement("span");
        legend.id = "nab-legend";
        var lo = document.createElement("span");
        lo.className = "nab-legend-lo";
        lo.textContent = "1";
        var bar = document.createElement("span");
        bar.className = "nab-legend-bar";
        var hi = document.createElement("span");
        hi.className = "nab-legend-hi";
        legend.appendChild(document.createTextNode("Clicks "));
        legend.appendChild(lo);
        legend.appendChild(bar);
        legend.appendChild(hi);
        meta.appendChild(legend);
      }
      var hiLabel = legend.querySelector(".nab-legend-hi");
      if (hiLabel) hiLabel.textContent = maxC + (maxC === 1 ? " click" : " clicks");
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
        var px = visLeft + visW - pillW - 8;
        ctx.fillStyle = "rgba(11,120,150,0.92)";
        ctx.fillRect(px, y - pillH / 2, pillW, pillH);
        ctx.fillStyle = "#fff";
        ctx.fillText(label, px + 6, y + 1);
      }
      ctx.restore();
    }

    function bindFrameScroll() {
      // Redraw whenever the heat could drift from the elements: scrolling inside
      // the iframe, or scrolling the stage that holds the pinned canvas.
      var win = frame.contentWindow;
      if (win) win.addEventListener("scroll", drawHeat, { passive: true });
      var stage = canvas.parentNode;
      if (stage) stage.addEventListener("scroll", drawHeat, { passive: true });
    }

    function setup() {
      frame.style.width = captureWidth + "px";
      var doc = frameDoc();
      if (!doc) return;
      if (!rebuildInto(doc)) return;
      // Allow layout to settle before measuring element boxes, then redraw a
      // couple more times as images load and shift box positions.
      setTimeout(function () { drawHeat(); bindFrameScroll(); }, 50);
      setTimeout(drawHeat, 400);
      setTimeout(drawHeat, 1200);
    }

    setup();
    window.addEventListener("resize", drawHeat);
  });
})();
