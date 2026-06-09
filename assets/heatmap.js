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
      return true;
    }

    function frameDoc() {
      return frame.contentDocument || (frame.contentWindow && frame.contentWindow.document) || null;
    }

    function drawHeat() {
      var doc = frameDoc();
      if (!doc) return;
      var w = frame.clientWidth;
      var h = frame.clientHeight;
      if (!w || !h) return;
      canvas.width = w;
      canvas.height = h;
      var ctx = canvas.getContext("2d");
      ctx.clearRect(0, 0, w, h);

      var maxC = 1, i;
      for (i = 0; i < clicks.length; i++) {
        var cc = parseInt(clicks[i].c, 10) || 0;
        if (cc > maxC) maxC = cc;
      }

      var unmatched = 0;
      for (i = 0; i < clicks.length; i++) {
        var sel = clicks[i].selector;
        var count = parseInt(clicks[i].c, 10) || 0;
        var el = null;
        try { el = sel ? doc.querySelector(sel) : null; } catch (e) { el = null; }
        if (!el) { unmatched += count; continue; }
        var r = el.getBoundingClientRect();
        if (r.bottom < 0 || r.top > h || r.width === 0 || r.height === 0) continue;
        var intensity = Math.min(1, count / maxC);
        ctx.fillStyle = "rgba(255,0,0," + (0.20 + intensity * 0.55) + ")";
        ctx.fillRect(r.left, r.top, r.width, r.height);
        var cx = r.left + r.width / 2;
        var cy = r.top + r.height / 2;
        var radius = Math.max(r.width, r.height) / 2 + 12;
        var grad = ctx.createRadialGradient(cx, cy, 0, cx, cy, radius);
        grad.addColorStop(0, "rgba(255,180,0," + (0.25 + intensity * 0.45) + ")");
        grad.addColorStop(1, "rgba(255,0,0,0)");
        ctx.fillStyle = grad;
        ctx.beginPath();
        ctx.arc(cx, cy, radius, 0, Math.PI * 2);
        ctx.fill();
      }

      var status = document.getElementById("nab-unmatched");
      if (status) {
        status.textContent = unmatched > 0
          ? unmatched + " click(s) not matched to the current layout (element absent or restructured)."
          : "";
      }
    }

    function bindFrameScroll() {
      try {
        var win = frame.contentWindow;
        if (win) win.addEventListener("scroll", drawHeat, { passive: true });
      } catch (e) {}
    }

    function setup() {
      frame.style.width = captureWidth + "px";
      var doc = frameDoc();
      if (!doc) return;
      if (!rebuildInto(doc)) return;
      // Allow layout to settle before measuring element boxes.
      setTimeout(function () { drawHeat(); bindFrameScroll(); }, 50);
    }

    function drawScroll() {
      var wrap = document.querySelector(".nab-scroll-bars");
      if (!wrap) return;
      var total = 0, i;
      for (i = 0; i < scroll.length; i++) total += parseInt(scroll[i], 10) || 0;
      if (total === 0) { wrap.textContent = "No scroll data."; return; }
      var html = "";
      for (i = scroll.length - 1; i >= 0; i--) {
        var pct = Math.round(((parseInt(scroll[i], 10) || 0) / total) * 100);
        html += '<div class="nab-scroll-row"><span class="nab-scroll-label">' + (i * 10) + '%</span>' +
                '<span class="nab-scroll-track"><span class="nab-scroll-fill" style="width:' + pct + '%"></span></span>' +
                '<span class="nab-scroll-val">' + pct + '%</span></div>';
      }
      wrap.innerHTML = html;
    }

    setup();
    window.addEventListener("resize", drawHeat);
    drawScroll();
  });
})();
