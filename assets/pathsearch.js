(function () {
  "use strict";

  function ready(fn) {
    if (document.readyState !== "loading") fn();
    else document.addEventListener("DOMContentLoaded", fn);
  }

  ready(function () {
    var cfgEl = document.getElementById("nab-pathsearch-config");
    if (!cfgEl || !window.fetch) return;
    var cfg;
    try { cfg = JSON.parse(cfgEl.textContent || "{}"); } catch (e) { return; }
    if (!cfg.url) return;

    function esc(s) {
      return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) {
        return { "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;" }[c] || c;
      });
    }

    var inputs = document.querySelectorAll("[data-nab-pathsearch]");
    for (var i = 0; i < inputs.length; i++) initInput(inputs[i]);

    function initInput(input) {
      if (input.dataset.nabPathsearchReady === "1") return;
      input.dataset.nabPathsearchReady = "1";
      var label = input.closest("label");
      var results = label ? label.querySelector("[data-nab-pathsearch-results]") : null;
      if (!results) return;
      var timer = null;
      var controller = null;

      function hide() {
        results.hidden = true;
        results.innerHTML = "";
      }

      function render(items) {
        if (!items || !items.length) {
          results.innerHTML = "<div class=\"nab-pathfind-empty\">No matching paths</div>";
          results.hidden = false;
          return;
        }
        var html = "";
        for (var j = 0; j < items.length; j++) {
          var it = items[j];
          html += "<button type=\"button\" class=\"nab-pathfind-item\" data-path=\"" + esc(it.path) + "\">" +
                  esc(it.path) +
                  "<span class=\"nab-pathfind-count\">" + esc(it.c) + "</span></button>";
        }
        results.innerHTML = html;
        results.hidden = false;
      }

      function search(q) {
        // Abort an in-flight request so a fast typist's stale response can't
        // overwrite the newest one.
        if (controller && controller.abort) controller.abort();
        controller = window.AbortController ? new AbortController() : null;
        var opts = { credentials: "same-origin" };
        if (controller) opts.signal = controller.signal;
        fetch(cfg.url + "?q=" + encodeURIComponent(q), opts)
          .then(function (r) { return r.ok ? r.json() : []; })
          .then(function (data) { render(data); })
          .catch(function () { /* aborted or failed: leave field usable */ });
      }

      input.addEventListener("input", function () {
        var q = input.value.trim();
        if (timer) clearTimeout(timer);
        if (q.length < 2) { hide(); return; }
        timer = setTimeout(function () { search(q); }, 250);
      });

      results.addEventListener("click", function (ev) {
        var btn = ev.target.closest(".nab-pathfind-item");
        if (!btn) return;
        input.value = btn.getAttribute("data-path");
        hide();
        input.focus();
      });

      input.addEventListener("keydown", function (ev) {
        if (ev.key === "Escape") hide();
      });
    }

    // Close any open dropdown when clicking outside its field.
    document.addEventListener("click", function (ev) {
      var labels = document.querySelectorAll("label.nab-pathfind");
      for (var k = 0; k < labels.length; k++) {
        if (!labels[k].contains(ev.target)) {
          var r = labels[k].querySelector("[data-nab-pathsearch-results]");
          if (r) { r.hidden = true; r.innerHTML = ""; }
        }
      }
    });
  });
})();
