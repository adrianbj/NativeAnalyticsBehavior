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

    function drawClicks() {
      var w = frame.clientWidth;
      var dh = maxDocHeight();
      canvas.width = w;
      canvas.height = frame.clientHeight;
      var ctx = canvas.getContext("2d");
      ctx.clearRect(0, 0, canvas.width, canvas.height);

      var maxC = 1;
      for (var i = 0; i < clicks.length; i++) { if (clicks[i].c > maxC) maxC = parseInt(clicks[i].c, 10); }

      for (var j = 0; j < clicks.length; j++) {
        var c = clicks[j];
        var x = (parseInt(c.x_bucket, 10) / 100) * w;
        var y = ((parseInt(c.y_bucket, 10) * 20) / dh) * canvas.height;
        var intensity = Math.min(1, (parseInt(c.c, 10) / maxC));
        var radius = 18;
        var grad = ctx.createRadialGradient(x, y, 0, x, y, radius);
        grad.addColorStop(0, "rgba(255,0,0," + (0.15 + intensity * 0.55) + ")");
        grad.addColorStop(1, "rgba(255,0,0,0)");
        ctx.fillStyle = grad;
        ctx.beginPath();
        ctx.arc(x, y, radius, 0, Math.PI * 2);
        ctx.fill();
      }
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

    frame.addEventListener("load", drawClicks);
    window.addEventListener("resize", drawClicks);
    drawScroll();
  });
})();
