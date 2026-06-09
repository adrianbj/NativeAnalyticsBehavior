(function () {
  "use strict";

  function ready(fn) {
    if (document.readyState !== "loading") fn();
    else document.addEventListener("DOMContentLoaded", fn);
  }

  ready(function () {
    var dataEl = document.getElementById("nab-data");
    var canvas = document.getElementById("nab-canvas");
    var frame = document.getElementById("nab-frame");
    if (!dataEl || !canvas || !frame) return;

    var data;
    try { data = JSON.parse(dataEl.textContent || "{}"); } catch (e) { return; }
    var clicks = data.clicks || [];
    var scroll = data.scroll || [];

    function maxDocHeight() {
      var h = 0;
      for (var i = 0; i < clicks.length; i++) { if (clicks[i].dh > h) h = parseInt(clicks[i].dh, 10) || 0; }
      return h || 1000;
    }

    // The canvas overlays the iframe as a sibling in the admin document (where
    // it reliably renders — a canvas injected into the sandboxed iframe does
    // not composite). To keep marks glued to the content while the iframe
    // scrolls internally, we read the iframe's own scroll offset (same-origin)
    // and subtract it when drawing.
    function frameMetrics() {
      try {
        var win = frame.contentWindow;
        var doc = frame.contentDocument || (win && win.document);
        if (!doc || !doc.documentElement) return null;
        var root = doc.documentElement;
        var contentHeight = Math.max(root.scrollHeight || 0, doc.body ? doc.body.scrollHeight : 0);
        var scrollTop = (win && typeof win.scrollY === "number") ? win.scrollY : (root.scrollTop || 0);
        return { contentHeight: contentHeight, scrollTop: scrollTop };
      } catch (e) { return null; }
    }

    function drawClicks() {
      var w = frame.clientWidth;
      var h = frame.clientHeight;
      if (!w || !h) return;
      canvas.width = w;
      canvas.height = h;
      var ctx = canvas.getContext("2d");
      ctx.clearRect(0, 0, w, h);

      var m = frameMetrics();
      var contentH = (m && m.contentHeight) || maxDocHeight();
      var scrollTop = m ? m.scrollTop : 0;
      var dh = maxDocHeight();

      var maxC = 1;
      for (var i = 0; i < clicks.length; i++) { if (clicks[i].c > maxC) maxC = parseInt(clicks[i].c, 10); }

      var radius = Math.max(24, Math.round(w * 0.04));
      for (var j = 0; j < clicks.length; j++) {
        var c = clicks[j];
        var x = (parseInt(c.x_bucket, 10) / 100) * w;
        var yDoc = ((parseInt(c.y_bucket, 10) * 20) / dh) * contentH;
        var y = yDoc - scrollTop;
        if (y < -radius || y > h + radius) continue;
        var intensity = Math.min(1, (parseInt(c.c, 10) / maxC));
        var grad = ctx.createRadialGradient(x, y, 0, x, y, radius);
        grad.addColorStop(0, "rgba(255,0,0," + (0.35 + intensity * 0.45) + ")");
        grad.addColorStop(1, "rgba(255,0,0,0)");
        ctx.fillStyle = grad;
        ctx.beginPath();
        ctx.arc(x, y, radius, 0, Math.PI * 2);
        ctx.fill();
      }
    }

    function bindFrameScroll() {
      try {
        var win = frame.contentWindow;
        if (win) win.addEventListener("scroll", drawClicks, { passive: true });
      } catch (e) {}
    }

    function setup() {
      drawClicks();
      bindFrameScroll();
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

    frame.addEventListener("load", setup);
    window.addEventListener("resize", drawClicks);
    // In case the iframe finished loading before this deferred script ran.
    if (frameMetrics()) setup();
    drawScroll();
  });
})();
