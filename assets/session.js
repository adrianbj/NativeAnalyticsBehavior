(function () {
  "use strict";

  function ready(fn) {
    if (document.readyState !== "loading") fn();
    else document.addEventListener("DOMContentLoaded", fn);
  }

  ready(function () {
    var cfgEl = document.getElementById("nab-session-config");
    var listEl = document.getElementById("nab-session-list");
    var trail = document.getElementById("nab-trail");
    var frame = document.getElementById("nab-trail-frame");
    var markersLayer = document.getElementById("nab-trail-markers");
    var placeholder = document.getElementById("nab-trail-placeholder");
    var rail = document.getElementById("nab-trail-rail");
    var meta = document.getElementById("nab-trail-meta");
    var posEl = document.getElementById("nab-trail-pos");
    var prevBtn = document.getElementById("nab-trail-prev");
    var nextBtn = document.getElementById("nab-trail-next");
    var legend = document.getElementById("nab-trail-legend");
    var spinner = document.getElementById("nab-trail-spinner");
    var stage = document.getElementById("nab-trail-stage");
    var tableEl = document.getElementById("nab-trail-table");
    var scrollEl = document.getElementById("nab-trail-scroll");
    var aggregate = document.getElementById("nab-aggregate");
    if (!cfgEl || !listEl || !trail || !frame || !markersLayer || !rail) return;

    var cfg;
    try { cfg = JSON.parse(cfgEl.textContent || "{}"); } catch (e) { return; }

    var journey = null;       // current journey object
    var flat = [];            // [{pageIndex, withinIndex}] across the whole journey, time order
    var step = 0;             // index into flat
    var loadedPageIndex = -1; // which journey page is currently in the iframe
    var loadToken = 0;        // bumped per loadPage; stale async loads bail on mismatch
    var selectEl = null;      // the session <select>, so navigation can sync it
    var selectSpinner = null; // spinner beside the select while a session loads
    var revealRestore = null; // undoes any off-canvas reveal for the current step
    var focusedWithin = -1;   // interaction whose pin is element-anchored (or -1)
    var focusedEl = null;     // the resolved element that pin is anchored to
    var listFilters = { min_time: false, interacted: false, min_scroll: false, multi_page: false }; // engagement filter checkboxes
    var listToken = 0;        // bumped per loadList; stale list fetches bail on mismatch
    var refocusFilter = null; // filter checkbox key to refocus after the list re-renders
    var filterSpinner = null; // spinner in the filter row while a re-fetch is in flight

    function frameDoc() {
      return frame.contentDocument || (frame.contentWindow && frame.contentWindow.document) || null;
    }

    function esc(s) {
      var d = document.createElement("div");
      d.textContent = s == null ? "" : String(s);
      return d.innerHTML;
    }

    function fmtWhen(s) {
      // "YYYY-MM-DD HH:MM:SS" -> a compact local-ish label; keep the raw on title.
      if (!s) return "";
      return s.replace("T", " ").slice(0, 16);
    }

    function fmtElapsed(fromTs, ts) {
      if (!fromTs || !ts) return "";
      var a = Date.parse(fromTs.replace(" ", "T"));
      var b = Date.parse(ts.replace(" ", "T"));
      if (isNaN(a) || isNaN(b)) return "";
      var secs = Math.max(0, Math.round((b - a) / 1000));
      var m = Math.floor(secs / 60);
      var s = secs % 60;
      return m + ":" + (s < 10 ? "0" + s : String(s));
    }

    function fmtDuration(secs) {
      secs = Math.round(Number(secs) || 0);
      if (secs <= 0) return "";
      var h = Math.floor(secs / 3600);
      var m = Math.floor((secs % 3600) / 60);
      var s = secs % 60;
      var mm = h && m < 10 ? "0" + m : String(m);
      var ss = s < 10 ? "0" + s : String(s);
      return h ? h + ":" + mm + ":" + ss : mm + ":" + ss;
    }

    // ---- session list ----

    function filtersActive() {
      return listFilters.min_time || listFilters.interacted || listFilters.min_scroll || listFilters.multi_page;
    }

    // The "Show only sessions with:" checkbox row. State lives in listFilters
    // so it survives re-renders; any change re-fetches the filtered list. The
    // row also renders when active filters empty the list, so they can be
    // unchecked; it's omitted only when the page simply has no sessions.
    function renderFilterRow(hasSessions) {
      if (!hasSessions && !filtersActive()) return null;
      var wrap = document.createElement("div");
      wrap.className = "nab-session-filters";
      wrap.setAttribute("role", "group");
      wrap.setAttribute("aria-label", "Session engagement filters");
      var label = document.createElement("span");
      label.textContent = "Show only sessions with:";
      wrap.appendChild(label);
      [
        { key: "min_time", text: "10s+ duration" },
        { key: "interacted", text: "clicks/copies" },
        { key: "min_scroll", text: "25%+ scroll" },
        { key: "multi_page", text: "more than 1 page" }
      ].forEach(function (f) {
        var lab = document.createElement("label");
        var cb = document.createElement("input");
        cb.type = "checkbox";
        cb.checked = !!listFilters[f.key];
        cb.setAttribute("data-nab-filter", f.key);
        cb.addEventListener("change", function () {
          listFilters[f.key] = cb.checked;
          refocusFilter = f.key;
          if (filterSpinner) filterSpinner.hidden = false;
          loadList();
        });
        lab.appendChild(cb);
        lab.appendChild(document.createTextNode(" " + f.text));
        wrap.appendChild(lab);
      });
      // Shown from a checkbox change until the refreshed list renders (every
      // render rebuilds this row with the spinner hidden again).
      filterSpinner = document.createElement("span");
      filterSpinner.className = "nab-trail-spinner nab-filter-spinner";
      filterSpinner.hidden = true;
      wrap.appendChild(filterSpinner);
      return wrap;
    }

    // Re-renders replace the checkbox that was just toggled; give it focus
    // back so keyboard users aren't dropped to <body> on every toggle. The
    // keys are our own fixed strings, so they're selector-safe.
    function restoreFilterFocus() {
      if (!refocusFilter) return;
      var cb = listEl.querySelector('input[data-nab-filter="' + refocusFilter + '"]');
      refocusFilter = null;
      if (cb) cb.focus();
    }

    function loadList() {
      // Token mirrors loadPage's loadToken: rapid filter toggles fire
      // overlapping fetches with different params, and a stale response must
      // not render over a newer one (the checkboxes re-derive from
      // listFilters, so a stale list would disagree with them).
      var token = ++listToken;
      var url = cfg.listUrl + "?path=" + encodeURIComponent(cfg.path || "/") +
        "&from=" + encodeURIComponent(cfg.from || "") + "&to=" + encodeURIComponent(cfg.to || "") +
        (listFilters.min_time ? "&min_time=1" : "") +
        (listFilters.interacted ? "&interacted=1" : "") +
        (listFilters.min_scroll ? "&min_scroll=1" : "") +
        (listFilters.multi_page ? "&multi_page=1" : "");
      fetch(url, { credentials: "same-origin" })
        .then(function (r) { return r.json(); })
        .then(function (data) { if (token === listToken) renderList(data); })
        .catch(function () {
          if (token !== listToken) return;
          // Keep the filter row on errors too — an over-aggressive filter may
          // be exactly what broke the request, and it must stay uncheckable.
          listEl.innerHTML = "";
          var fr = renderFilterRow(false);
          if (fr) listEl.appendChild(fr);
          var err = document.createElement("p");
          err.className = "nab-frust-none";
          err.textContent = "Could not load sessions.";
          listEl.appendChild(err);
          restoreFilterFocus();
        });
    }

    function renderList(data) {
      var sessions = (data && data.sessions) || [];
      if (!sessions.length) {
        listEl.innerHTML = "";
        var emptyFilters = renderFilterRow(false);
        if (emptyFilters) listEl.appendChild(emptyFilters);
        var none = document.createElement("p");
        none.className = "nab-frust-none";
        none.textContent = filtersActive()
          ? "No sessions match the active filters."
          : "No recorded sessions visited this page in range.";
        listEl.appendChild(none);
        restoreFilterFocus();
        maybeDeepLink();
        return;
      }
      var sel = document.createElement("select");
      sel.className = "uk-select nab-session-select";
      var ph = document.createElement("option");
      ph.value = "";
      ph.textContent = "All sessions (heatmap)";
      sel.appendChild(ph);
      sessions.forEach(function (s) {
        var opt = document.createElement("option");
        opt.value = s.session_hash;
        opt.textContent = sessionLabel(s);
        opt.title = s.started_at + " · session " + (s.hash_short || "");
        sel.appendChild(opt);
      });
      sel.addEventListener("change", function () {
        if (sel.value) openSession(sel.value);
        else closeTrail();
      });

      selectEl = sel;
      // The deep-linked journey may already be open (or in flight) from the
      // startup maybeDeepLink, which ran before this select existed — sync it.
      // currentUrlSession() only matches 64-hex hashes, so it's selector-safe.
      var cur = currentUrlSession();
      if (cur) {
        // Active filters may exclude the open session from the list; keep the
        // select truthful with a synthetic option so the trail can still be
        // closed by switching back to "All sessions" (a select can't fire
        // change from its placeholder when it's already silently on it).
        if (!sel.querySelector('option[value="' + cur + '"]')) {
          var curOpt = document.createElement("option");
          curOpt.value = cur;
          curOpt.textContent = "Current session (not in the filtered list)";
          sel.appendChild(curOpt);
        }
        sel.value = cur;
      }
      listEl.innerHTML = "";
      var filterRow = renderFilterRow(true);
      if (filterRow) listEl.appendChild(filterRow);
      var row = document.createElement("div");
      row.className = "nab-session-row";
      row.appendChild(sel);
      selectSpinner = document.createElement("span");
      selectSpinner.className = "nab-trail-spinner nab-session-spinner";
      selectSpinner.hidden = true;
      row.appendChild(selectSpinner);
      listEl.appendChild(row);
      var stats = [];
      var medians = [];
      var median = fmtDuration(data && data.median_duration);
      if (median) medians.push(median);
      if (data && data.median_pages > 0) medians.push(data.median_pages + (data.median_pages === 1 ? " page" : " pages"));
      if (data && data.scroll_median > 0) medians.push(data.scroll_median + "% scroll");
      // One "Median" label fronts the whole median group.
      if (medians.length) {
        medians[0] = "Median " + medians[0];
        stats = stats.concat(medians);
      }
      if (stats.length) {
        var avgNote = document.createElement("p");
        avgNote.className = "nab-frust-none";
        avgNote.textContent = stats.join(" · ") + ".";
        listEl.appendChild(avgNote);
      }
      var total = typeof data.total === "number" && data.total > 0 ? data.total : sessions.length;
      var note = document.createElement("p");
      note.className = "nab-frust-none";
      note.textContent = total > sessions.length
        ? "Showing " + sessions.length + " of " + total + " sessions."
        : total + " session" + (total === 1 ? "" : "s") + ".";
      listEl.appendChild(note);
      restoreFilterFocus();
      maybeDeepLink();
    }

    function sessionLabel(s) {
      var pages = s.page_count || 0;
      var clicks = s.click_count || 0;
      var copies = s.copy_count || 0;
      var scroll = s.max_scroll || 0;
      var parts = [fmtWhen(s.started_at)];
      if (s.device) parts.push(s.device);
      var dur = fmtDuration(s.duration);
      if (dur) parts.push(dur);
      parts.push(pages + (pages === 1 ? " page" : " pages"));
      parts.push(clicks + (clicks === 1 ? " click" : " clicks"));
      if (copies > 0) parts.push(copies + (copies === 1 ? " copy" : " copies"));
      if (scroll > 0) parts.push(scroll + "% scroll");
      // Dead clicks are too common to be a useful session-level flag; only
      // rage marks a session as worth drilling into from the label alone.
      if (s.has_rage) parts.push("rage");
      var src = s.referrer_host || s.utm_source;
      if (src) {
        var utm = [s.utm_source, s.utm_medium, s.utm_campaign].filter(Boolean).join(" / ");
        var via = "via " + src;
        if (utm && utm !== src) via += " (" + utm + ")";
        parts.push(via);
      }
      return parts.join(" · ");
    }

    // ---- drill-down ----

    function openSession(hash) {
      if (!/^[a-f0-9]{64}$/.test(hash)) return;
      showSelectSpinner(true);
      fetch(cfg.journeyUrl + "?session=" + encodeURIComponent(hash), { credentials: "same-origin" })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          showSelectSpinner(false);
          if (!data || data.error || !data.pages || !data.pages.length) {
            staleNote();
            return;
          }
          journey = data;
          buildFlat();
          loadedPageIndex = -1;
          renderMeta();
          renderRail();
          renderScrollLine();
          renderSessionTable();
          showTrail(true);
          setUrlSession(hash);
          if (selectEl) selectEl.value = hash;
          // Open on the page the admin is analysing (the filtered cfg.path),
          // not the visitor's landing page, so the iframe and active rail chip
          // match the page whose heatmap they drilled in from. Fall back to the
          // first page if the journey doesn't include that path.
          var startPage = pageIndexForPath(cfg.path);
          var gi = firstFlatIndexOnPage(startPage);
          step = gi >= 0 ? gi : 0;
          loadPage(startPage, gi >= 0 ? flat[gi].withinIndex : -1);
          updatePos();
        })
        .catch(function () { showSelectSpinner(false); staleNote(); });
    }

    function showSelectSpinner(on) {
      if (selectSpinner) selectSpinner.hidden = !on;
    }

    function buildFlat() {
      flat = [];
      journey.pages.forEach(function (p, pi) {
        (p.interactions || []).forEach(function (_it, ii) {
          flat.push({ pageIndex: pi, withinIndex: ii });
        });
      });
    }

    // Normalise a path for comparison: drop query/hash, ensure a leading slash,
    // and ignore a trailing slash (except root) so "/x/" and "/x" match.
    function normPath(s) {
      if (!s) return "/";
      s = String(s).split("?")[0].split("#")[0];
      if (s.charAt(0) !== "/") s = "/" + s;
      if (s.length > 1) s = s.replace(/\/+$/, "");
      return s || "/";
    }

    // Index of the journey page matching the given path (the filtered page),
    // or 0 if the journey doesn't include it.
    function pageIndexForPath(path) {
      var want = normPath(path);
      for (var i = 0; i < journey.pages.length; i++) {
        if (normPath(journey.pages[i].path) === want) return i;
      }
      return 0;
    }

    // Flat-index of the first interaction on a page, or -1 if it has none.
    function firstFlatIndexOnPage(pageIndex) {
      for (var i = 0; i < flat.length; i++) {
        if (flat[i].pageIndex === pageIndex) return i;
      }
      return -1;
    }

    function showTrail(on) {
      if (on) {
        trail.hidden = false;
        if (aggregate) aggregate.style.display = "none";
      } else {
        trail.hidden = true;
        if (aggregate) aggregate.style.display = "";
      }
    }

    function renderMeta() {
      var parts = [];
      // ua_device is the visitor's actual device class (UA-based); journey.device
      // is the viewport-based layout class used for interaction/snapshot lookups.
      var dev = journey.ua_device || journey.device;
      if (dev) parts.push(esc(dev));
      if (journey.browser) parts.push(esc(journey.browser));
      if (journey.os) parts.push(esc(journey.os));
      if (journey.landing) parts.push("landing " + esc(journey.landing));
      // Prefer the real referring host over a generic utm_source (e.g. "ads"),
      // then append the full UTM triple when it adds anything beyond what's shown.
      // The full referrer URL, when present, goes on hover.
      var src = journey.referrer_host || journey.utm_source;
      if (src) {
        var utm = [journey.utm_source, journey.utm_medium, journey.utm_campaign].filter(Boolean).join(" / ");
        var via = "via " + esc(src);
        if (utm && utm !== src) via += " (" + esc(utm) + ")";
        if (journey.referrer_url) via = '<span title="' + esc(journey.referrer_url).replace(/"/g, "&quot;") + '">' + via + "</span>";
        parts.push(via);
      }
      var total = 0;
      journey.pages.forEach(function (p) { total += (p.time_on_page || 0); });
      var dur = fmtDuration(total);
      if (dur) parts.push(dur + " on site");
      meta.innerHTML = parts.join(" &middot; ");
    }

    function renderRail() {
      rail.innerHTML = "";
      journey.pages.forEach(function (p, pi) {
        var chip = document.createElement("button");
        chip.type = "button";
        chip.className = "nab-rail-chip";
        chip.setAttribute("data-page", String(pi));
        var n = document.createElement("span");
        n.className = "nab-rail-n";
        n.textContent = String(pi + 1);
        var path = document.createElement("span");
        path.className = "nab-rail-path";
        path.textContent = p.path;
        path.title = (p.page_title || p.path) + " · " + (p.interaction_count || 0) + " actions" +
          (p.visit_count > 1 ? " · " + p.visit_count + " visits" : "");
        var c = document.createElement("span");
        c.className = "nab-rail-count";
        c.textContent = String(p.interaction_count || 0);
        chip.appendChild(n);
        chip.appendChild(path);
        chip.appendChild(c);
        chip.addEventListener("click", function () { goToPage(pi); });
        rail.appendChild(chip);
      });
    }

    // Whole-session table: one row per click/copy in journey order. Each row's
    // index equals its flat[] index, so a row click is setStep(flatIndex).
    function renderSessionTable() {
      if (!tableEl) return;
      tableEl.innerHTML = "";
      if (!flat.length) {
        tableEl.innerHTML = '<p class="nab-frust-none">No interactions recorded.</p>';
        return;
      }
      var firstTs = "";
      for (var k = 0; k < flat.length; k++) {
        var fp = journey.pages[flat[k].pageIndex];
        var fit = (fp.interactions || [])[flat[k].withinIndex];
        if (fit && fit.t) { firstTs = fit.t; break; }
      }
      var wrap = document.createElement("div");
      wrap.className = "pwna-table-wrap";
      var table = document.createElement("table");
      table.className = "pwna-table nab-click-table nab-trail-table";
      table.innerHTML = '<thead><tr><th>Page</th><th>Element</th>' +
        '<th>Type</th><th class="nab-click-num">Time</th></tr></thead>';
      var tbody = document.createElement("tbody");
      flat.forEach(function (f, fi) {
        var p = journey.pages[f.pageIndex];
        var it = (p.interactions || [])[f.withinIndex];
        if (!it) return;
        var tr = document.createElement("tr");
        tr.className = "nab-click-row";
        tr.setAttribute("tabindex", "0");
        tr.setAttribute("data-flat", String(fi));
        var sig = "";
        if (it.rage) sig = ' <span class="nab-row-sig is-rage">rage</span>';
        else if (it.dead) sig = ' <span class="nab-row-sig is-dead">dead</span>';
        var elCell = it.type === "search"
          ? 'Searched for "' + esc(it.label) + '"'
          : esc(it.label || it.selector || it.type);
        tr.innerHTML =
          '<td><span class="nab-rail-path" title="' + esc(p.page_title || p.path) + '">' + esc(p.path) + '</span></td>' +
          '<td>' + elCell + sig + '</td>' +
          '<td>' + esc(it.type) + '</td>' +
          '<td class="nab-click-num">' + esc(fmtElapsed(firstTs, it.t)) + '</td>';
        tr.addEventListener("click", function () { setStep(fi); scrollStageIntoView(tr); });
        tr.addEventListener("keydown", function (e) {
          if (e.key === "Enter" || e.key === " ") { e.preventDefault(); setStep(fi); scrollStageIntoView(tr); }
        });
        tbody.appendChild(tr);
      });
      table.appendChild(tbody);
      wrap.appendChild(table);
      tableEl.appendChild(wrap);
      var capped = 0;
      journey.pages.forEach(function (p) { capped += (p.more || 0); });
      if (capped > 0) {
        var note = document.createElement("p");
        note.className = "nab-frust-none";
        note.textContent = "+" + capped + " interaction" + (capped === 1 ? "" : "s") + " not shown (capped).";
        tableEl.appendChild(note);
      }
    }

    // Per-page deepest scroll for the session, as one summary line.
    function renderScrollLine() {
      if (!scrollEl) return;
      scrollEl.innerHTML = "";
      if (!journey || !journey.pages.length) return;
      var bits = journey.pages.map(function (p) {
        var d = (p.max_scroll || 0) > 0 ? (p.max_scroll + "%") : "—";
        return esc(p.path) + " " + d;
      });
      scrollEl.innerHTML = "<strong>Scroll depth:</strong> " + bits.join(" &middot; ");
    }

    function setActiveRail(pi) {
      var chips = rail.querySelectorAll(".nab-rail-chip");
      for (var i = 0; i < chips.length; i++) {
        chips[i].classList.toggle("is-active", String(i) === String(pi));
      }
    }

    // Load a journey page's backdrop into the iframe, then render its markers.
    // highlightWithin is the per-page interaction index to highlight (or -1).
    function loadPage(pageIndex, highlightWithin) {
      var p = journey.pages[pageIndex];
      if (!p) return;
      // Drop any off-canvas reveal from the page we're leaving before the new
      // backdrop replaces the document its restore() closure points into.
      clearReveal();
      // Bump the load token so a slower earlier fetch can't render its page into
      // the iframe after a later navigation has taken over (keeps the iframe and
      // the active rail chip on the same page).
      var token = ++loadToken;
      setActiveRail(pageIndex);
      if (!p.has_backdrop) {
        showSpinner(false);
        showPlaceholder(p);
        loadedPageIndex = pageIndex;
        renderMarkersFallback(p, highlightWithin);
        return;
      }
      hidePlaceholder();
      showSpinner(true);
      // Pass the time this session was on the page so the server returns the DOM
      // version live during the session's window, not whatever was captured most
      // recently (D2 versioned snapshots). Omit when unknown so it falls to latest.
      var atParam = p.first_at ? "&at=" + encodeURIComponent(p.first_at) : "";
      fetch(cfg.snapshotUrl + "?path=" + encodeURIComponent(p.path) + "&device=" + encodeURIComponent(journey.device) + atParam, { credentials: "same-origin" })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
          if (token !== loadToken) return;
          if (!resp || resp.none) { showSpinner(false); showPlaceholder(p); loadedPageIndex = pageIndex; renderMarkersFallback(p, highlightWithin); return; }
          var snap;
          try { snap = JSON.parse(resp.dom); } catch (e) { showSpinner(false); showPlaceholder(p); loadedPageIndex = pageIndex; renderMarkersFallback(p, highlightWithin); return; }
          NABStage.stripScripts(snap);
          frame.style.width = (parseInt(resp.capture_width, 10) || 1280) + "px";
          var doc = frame.contentDocument || (frame.contentWindow && frame.contentWindow.document);
          if (!doc || !NABStage.rebuild(doc, snap)) { showSpinner(false); showPlaceholder(p); loadedPageIndex = pageIndex; renderMarkersFallback(p, highlightWithin); return; }
          loadedPageIndex = pageIndex;
          showSpinner(false);
          // The rebuilt doc is a fresh document; re-bind its scroll so markers
          // track as the iframe content scrolls.
          bindFrameScroll();
          // Re-render markers as the rebuilt layout settles (images shift offsets).
          renderMarkers(p, highlightWithin);
          setTimeout(function () { if (token === loadToken) renderMarkers(p, highlightWithin); }, 80);
          setTimeout(function () { if (token === loadToken) renderMarkers(p, highlightWithin); }, 500);
        })
        .catch(function () { if (token !== loadToken) return; showSpinner(false); showPlaceholder(p); loadedPageIndex = pageIndex; renderMarkersFallback(p, highlightWithin); });
    }

    function showSpinner(on) {
      if (spinner) spinner.hidden = !on;
    }

    // Bring the highlighted action NEAR THE TOP of the visible window (not
    // centred): the pin then sits in the first slice of the stage, so the
    // outer page only needs to reveal the stage top — a much shorter page
    // scroll that keeps the activated table row in view. The iframe scrolls
    // VERTICALLY (its content is taller than the fixed-height frame), but it
    // can't scroll horizontally — the rebuilt content fits the captured-width
    // iframe (html{overflow-x:hidden}), so the iframe element is wider than the
    // stage and the STAGE is the horizontal scroller. Hence two different axes
    // on two different elements.
    function scrollToWithin(p, withinIndex) {
      if (!p || !p.has_backdrop || withinIndex < 0) return;
      var it = (p.interactions || [])[withinIndex];
      if (!it) return;
      var win = frame.contentWindow;
      // Headroom above the pin: enough context to read the element, small
      // enough that the pin stays in the stage's first ~140px.
      var pad = Math.min(140, Math.floor(frame.clientHeight / 4));
      // Prefer scrolling to the clicked element (pins are anchored to it), so the
      // highlighted pin lands centred even when the recorded coordinates drift
      // from the rebuilt layout. Fall back to those coordinates when the selector
      // no longer resolves.
      var doc = frameDoc();
      if (doc && it.selector) {
        var el = resolveClickEl(doc, it);
        // Only scroll to the element when its click point is in view; otherwise
        // the pin is placed by the recorded coordinate (elClickInView is false),
        // so scroll by those coordinates too and keep the highlighted pin centred.
        if (el && elClickInView(el, it, null)) {
          var r = el.getBoundingClientRect();
          // Centre the recorded click POINT (where the pin sits, via offx/offy),
          // not the element box: a container far wider/taller than the viewport
          // would otherwise leave the actual click — and its pin — off-screen.
          var fx = (typeof it.offx === "number" ? it.offx : 500) / 1000;
          var fy = (typeof it.offy === "number" ? it.offy : 500) / 1000;
          if (win) win.scrollTo(0, Math.max(0, (win.pageYOffset || 0) + r.top + r.height * fy - pad));
          if (stage) stage.scrollLeft = Math.max(0, r.left + r.width * fx - stage.clientWidth / 2);
          return;
        }
      }
      if (!it.dh) return;
      var g = NABStage.geom(frame, markersLayer);
      if (!g) return;
      var docY = (it.y_px / it.dh) * g.fullH;
      var docX = (it.x_frac / 1000) * g.fullW;
      if (win) win.scrollTo(0, Math.max(0, docY - pad));
      if (stage) stage.scrollLeft = Math.max(0, docX - stage.clientWidth / 2);
    }

    function showPlaceholder(p) {
      frame.style.visibility = "hidden";
      if (placeholder) {
        placeholder.hidden = false;
        placeholder.innerHTML = '<p class="nab-frust-none">No backdrop stored for <strong>' + esc(p.path) +
          "</strong> (" + esc(journey.device) + "). Showing interactions as a list.</p>";
      }
    }
    function hidePlaceholder() {
      frame.style.visibility = "";
      if (placeholder) { placeholder.hidden = true; placeholder.innerHTML = ""; }
    }

    // Positioned numbered pins over the backdrop.
    function renderMarkers(p, highlightWithin) {
      markersLayer.innerHTML = "";
      var ints = p.interactions || [];
      var g = NABStage.geom(frame, markersLayer);
      if (!g) return;
      var doc = frameDoc();
      // Searches have no coordinates and never pin; skip them when numbering
      // so visible pin numbers stay contiguous.
      var num = 0;
      ints.forEach(function (it, ii) {
        if (it.type === "search") return;
        num++;
        var pin = makePin(p, it, ii, num);
        if (!placePin(pin, it, g, doc)) return;
        markersLayer.appendChild(pin);
      });
      if (p.more) {
        var more = document.createElement("div");
        more.className = "nab-marker-more";
        more.textContent = "+" + p.more + " more not shown";
        markersLayer.appendChild(more);
      }
      highlight(p, highlightWithin);
    }

    // Fallback list when there is no backdrop to position against.
    function renderMarkersFallback(p, highlightWithin) {
      markersLayer.innerHTML = "";
      var list = document.createElement("ol");
      list.className = "nab-marker-list";
      (p.interactions || []).forEach(function (it, ii) {
        var li = document.createElement("li");
        li.appendChild(makePin(p, it, ii));
        var span = document.createElement("span");
        span.className = "nab-marker-text";
        span.textContent = it.type === "search"
          ? 'Searched for "' + (it.label || "") + '"'
          : (it.label || it.selector || it.type);
        li.appendChild(span);
        list.appendChild(li);
      });
      if (placeholder) placeholder.appendChild(list);
      applyHighlight(highlightWithin);
    }

    function makePin(p, it, withinIndex, displayNum) {
      var pin = document.createElement("button");
      pin.type = "button";
      pin.className = "nab-marker nab-marker-" + (it.type === "copy" ? "copy" : "click") +
        (it.dead ? " is-dead" : "") + (it.rage ? " is-rage" : "");
      pin.textContent = String(displayNum || withinIndex + 1);
      pin.title = (it.label || it.selector || it.type) + (it.t ? " · " + it.t : "");
      pin.setAttribute("data-within", String(withinIndex));
      pin.addEventListener("click", function () {
        var gi = flatIndexFor(loadedPageIndex, withinIndex);
        if (gi >= 0) { step = gi; updatePos(); highlight(p, withinIndex); }
      });
      return pin;
    }

    function flatIndexFor(pageIndex, withinIndex) {
      for (var i = 0; i < flat.length; i++) {
        if (flat[i].pageIndex === pageIndex && flat[i].withinIndex === withinIndex) return i;
      }
      return -1;
    }

    function applyHighlight(withinIndex) {
      var pins = markersLayer.querySelectorAll(".nab-marker");
      for (var i = 0; i < pins.length; i++) {
        var on = String(pins[i].getAttribute("data-within")) === String(withinIndex);
        pins[i].classList.toggle("is-current", on);
        pins[i].classList.toggle("is-dim", withinIndex >= 0 && !on);
      }
    }

    // Undo any off-canvas reveal applied for the current step and forget the
    // element-anchored pin.
    function clearReveal() {
      if (revealRestore) { revealRestore(); revealRestore = null; }
      focusedWithin = -1;
      focusedEl = null;
    }

    // Highlight an interaction. If it resolves to an element hidden inside a
    // closed panel (e.g. a UIKit off-canvas menu the scriptless iframe can't
    // open), force the panel visible and anchor the pin to the now-rendered
    // element so the admin can see what was actually clicked; otherwise fall
    // back to scrolling by the recorded coordinates.
    function highlight(p, withinIndex) {
      applyHighlight(withinIndex);
      if (revealHighlighted(p, withinIndex)) return;
      if (withinIndex >= 0) scrollToWithin(p, withinIndex);
      // A search step triggers no iframe/stage scroll, so the scroll-driven
      // pin repositioning never fires — and a pin clearReveal() just detached
      // from a re-hidden panel would keep floating over nothing. Reposition
      // explicitly for that one no-scroll case.
      var it = withinIndex >= 0 ? (p.interactions || [])[withinIndex] : null;
      if (it && it.type === "search") positionMarkers();
    }

    // Returns true when it revealed a hidden panel and took over pin placement
    // (so the caller skips the recorded-coordinate scroll).
    function revealHighlighted(p, withinIndex) {
      clearReveal();
      if (!p || !p.has_backdrop || withinIndex < 0) return false;
      var it = (p.interactions || [])[withinIndex];
      if (!it || !it.selector) return false;
      var doc = frameDoc();
      if (!doc) return false;
      var el = resolveClickEl(doc, it);
      if (!el || !NABStage.isHidden(el)) return false;
      revealRestore = NABStage.reveal(el, frame.contentWindow);
      // The reveal can surface a panel in the horizontally clipped overflow — e.g.
      // a scriptless desktop navbar that never collapses to the captured mobile
      // layout, leaving the clicked link past the frame's right edge. If the
      // click point still falls outside the captured viewport width, the reveal
      // can't bring it on screen, so undo it and let the caller place the pin by
      // the recorded viewport-relative coordinate instead.
      if (!elClickInView(el, it, null)) { clearReveal(); return false; }
      focusedWithin = withinIndex;
      focusedEl = el;
      // The reveal forces a closed panel (a fixed off-canvas bar) visible. Let
      // its layout settle for a frame, then bring the whole panel on screen and
      // anchor the pin to the now-rendered element. Guard against a newer step
      // having taken over before the frame fires.
      requestAnimationFrame(function () {
        if (focusedWithin !== withinIndex || focusedEl !== el) return;
        revealScroll(el);
        var pin = markersLayer.querySelector('.nab-marker[data-within="' + withinIndex + '"]');
        if (pin) placePinByEl(pin, el, it.offx, it.offy);
      });
      return true;
    }

    // The outermost ancestor that makes up the revealed panel: a positioned
    // (fixed/absolute) box or an explicit off-canvas container. Used to scroll
    // the panel — not just the small clicked element — into view, so a fixed
    // off-canvas bar pinned to the iframe's left edge isn't left off-screen.
    function panelRoot(el) {
      var win = frame.contentWindow;
      var doc = el.ownerDocument, body = doc && doc.body;
      var node = el, found = el;
      while (node && node.nodeType === 1 && node !== body) {
        if (node.className && /offcanvas/i.test(String(node.className))) return node;
        var pos = win.getComputedStyle(node).position;
        if (pos === "fixed" || pos === "absolute") found = node;
        node = node.parentNode;
      }
      return found;
    }

    // Position a pin. Prefer anchoring to the clicked element resolved in the
    // rebuilt backdrop (its rendered layout differs from the live page the
    // coordinates were recorded against, so the recorded x/y drift); fall back
    // to those coordinates only when the selector no longer resolves (element
    // absent or restructured) or has no rendered box (hidden in a closed panel,
    // which the reveal logic re-anchors separately). Returns false when neither
    // method can place it.
    function placePin(pin, it, g, doc) {
      if (doc && it.selector) {
        var el = resolveClickEl(doc, it);
        if (el && elClickInView(el, it, g)) { placePinByEl(pin, el, it.offx, it.offy); return true; }
      }
      var pt = NABStage.point(g, it.x_frac, it.y_px, it.dh);
      if (!pt) return false;
      pin.style.left = pt.x + "px";
      pin.style.top = pt.y + "px";
      return true;
    }

    // True when the recorded click point on a resolved element lands inside the
    // captured viewport width. The rebuilt layout can place an element in the
    // horizontally clipped overflow region (snapshot content wider than the
    // capture width); anchoring the pin there drops it in the empty gutter
    // beside the frame, so callers fall back to the recorded viewport-relative
    // coordinate instead. Only the horizontal axis is gated — vertical overflow
    // is reachable by scrolling the iframe.
    function elClickInView(el, it, g) {
      var r = el.getBoundingClientRect();
      if (r.width <= 0 && r.height <= 0) return false;
      var viewW = g ? g.viewW : frame.clientWidth;
      if (!viewW) return true;
      var fx = (typeof it.offx === "number" ? it.offx : 500) / 1000;
      var px = r.left + r.width * fx;
      return px >= 0 && px <= viewW;
    }

    // Resolve a recorded selector to the element that best represents the click.
    // When the selector matches more than one node — typically a duplicate id
    // such as a desktop nav button and its off-canvas twin — querySelector (and
    // so NABStage.resolveSelector) returns the first in document order, which on
    // a mobile-width capture is usually the desktop copy hidden by a `uk-visible@l`
    // media query. pickClickTwin chooses the twin whose click point actually
    // lands on-frame; only when none qualifies do we defer to resolveSelector's
    // first-match-plus-ancestor-suffix behaviour.
    function resolveClickEl(doc, it) {
      if (!it || !it.selector) return null;
      return pickClickTwin(doc, it) || NABStage.resolveSelector(doc, it.selector);
    }

    // Among multiple exact matches for it.selector, prefer one already rendered
    // with its click point on-frame; failing that, a hidden one whose click point
    // falls inside the captured viewport once revealed (probed by reveal-then-
    // restore so the DOM is left untouched). Returns null when there is a single
    // match or no candidate qualifies, so the caller can fall back.
    function pickClickTwin(doc, it) {
      var list;
      try { list = doc.querySelectorAll(it.selector); } catch (e) { return null; }
      if (!list || list.length < 2) return null;
      for (var i = 0; i < list.length; i++) {
        if (elClickInView(list[i], it, null)) return list[i];
      }
      for (var j = 0; j < list.length; j++) {
        if (!NABStage.isHidden(list[j])) continue;
        var restore = NABStage.reveal(list[j], frame.contentWindow);
        var onFrame = elClickInView(list[j], it, null);
        restore();
        if (onFrame) return list[j];
      }
      return null;
    }

    // Position a pin over a resolved element, at the recorded click point within
    // it (offx/offy are 0..1000 fractions of the element's box). Pre-offset rows
    // default to the centre, so historical sessions stay element-centred. Used
    // whenever the recorded page coordinates don't match the rebuilt layout.
    function placePinByEl(pin, el, offx, offy) {
      var r = el.getBoundingClientRect();
      var fr = frame.getBoundingClientRect();
      var mr = markersLayer.getBoundingClientRect();
      var fx = (typeof offx === "number" ? offx : 500) / 1000;
      var fy = (typeof offy === "number" ? offy : 500) / 1000;
      pin.style.left = (fr.left - mr.left + r.left + r.width * fx) + "px";
      pin.style.top = (fr.top - mr.top + r.top + r.height * fy) + "px";
    }

    // Align the revealed panel's top-left edge into the stage (rather than
    // centring the clicked element, which can push a left-anchored panel off
    // the left edge), then nudge the outer page so the stage is on screen.
    function revealScroll(el) {
      var panel = panelRoot(el);
      var pr = panel.getBoundingClientRect();
      var er = el.getBoundingClientRect();
      var win = frame.contentWindow;
      if (win) {
        // er.top/pr.top are iframe-viewport-relative; add pageYOffset to get the
        // document position to scroll to (no-op for a fixed bar at top:0).
        var top = Math.min(er.top, pr.top);
        win.scrollTo(0, Math.max(0, (win.pageYOffset || 0) + top - 12));
      }
      if (stage) {
        // The iframe sits at the stage's content origin (frame.offsetLeft 0) and
        // can't scroll horizontally (html{overflow-x:hidden}), so the panel's x
        // in stage-scroll coords equals its iframe-relative left. Align it to the
        // stage's left edge — don't mix in the stage's own parent-page rect.
        // The OUTER page is deliberately not scrolled here: only the activation
        // entry points do that (they know which element must stay in view), and
        // a second unclamped scroll from this path would override their clamp.
        stage.scrollLeft = Math.max(0, pr.left - 4);
      }
    }

    // Bring the top slice of the trail stage into the admin viewport without
    // losing the user's place. scrollToWithin parks the highlighted pin near
    // the stage top, so revealing only that first slice is enough — a far
    // shorter page scroll than showing the whole stage. The anchor element
    // (the clicked table row) is kept at least 90px inside the top of the
    // window, clear of the admin theme's ~80px masthead.
    function scrollStageIntoView(anchorEl) {
      if (!stage) return;
      var vh = window.innerHeight || document.documentElement.clientHeight || 0;
      if (!vh) return;
      var sr = stage.getBoundingClientRect();
      var reveal = Math.min(Math.floor(vh * 0.55), 480, sr.bottom - sr.top);
      var delta = Math.min(sr.top + reveal - vh, sr.top - 12);
      if (anchorEl) {
        var ar = anchorEl.getBoundingClientRect();
        delta = Math.min(delta, ar.top - 90);
      }
      // Absolute scrollTo, not relative scrollBy: smooth scrollBy is applied
      // against the PENDING scroll destination (notably in Chrome), so rapid
      // successive row clicks stack their deltas and overshoot the anchor
      // clamp. An absolute target replaces any in-flight animation instead.
      if (delta > 0) window.scrollTo({ top: (window.pageYOffset || 0) + delta, behavior: "smooth" });
    }

    // Reposition existing pins without rebuilding (scroll/resize).
    function positionMarkers() {
      if (loadedPageIndex < 0 || !journey) return;
      var p = journey.pages[loadedPageIndex];
      if (!p || !p.has_backdrop) return;
      var ints = p.interactions || [];
      var g = NABStage.geom(frame, markersLayer);
      if (!g) return;
      var doc = frameDoc();
      var pins = markersLayer.querySelectorAll(".nab-marker");
      for (var i = 0; i < pins.length; i++) {
        var wi = parseInt(pins[i].getAttribute("data-within"), 10);
        var it = ints[wi];
        if (wi === focusedWithin && focusedEl) { placePinByEl(pins[i], focusedEl, it && it.offx, it && it.offy); continue; }
        if (!it) continue;
        placePin(pins[i], it, g, doc);
      }
    }

    // ---- step-through ----

    function goToPage(pageIndex) {
      // Jump to the first interaction on that page (or just show the page if none).
      var gi = -1;
      for (var i = 0; i < flat.length; i++) { if (flat[i].pageIndex === pageIndex) { gi = i; break; } }
      if (gi >= 0) { step = gi; loadPage(pageIndex, flat[gi].withinIndex); }
      else loadPage(pageIndex, -1);
      updatePos();
    }

    function setStep(newStep) {
      if (!flat.length) return;
      if (newStep < 0) newStep = 0;
      if (newStep > flat.length - 1) newStep = flat.length - 1;
      step = newStep;
      var target = flat[step];
      if (target.pageIndex !== loadedPageIndex) {
        loadPage(target.pageIndex, target.withinIndex);
      } else {
        highlight(journey.pages[loadedPageIndex], target.withinIndex);
      }
      updatePos();
    }

    function updatePos() {
      posEl.textContent = flat.length ? (step + 1) + " / " + flat.length : "0 / 0";
      prevBtn.disabled = (step <= 0);
      nextBtn.disabled = (step >= flat.length - 1);
      legend.textContent = "rage = repeated · dead = no-op · ◻ copy";
      if (tableEl) {
        var rows = tableEl.querySelectorAll(".nab-click-row");
        for (var i = 0; i < rows.length; i++) {
          rows[i].classList.toggle("is-current", rows[i].getAttribute("data-flat") === String(step));
        }
      }
    }

    if (prevBtn) prevBtn.addEventListener("click", function () { setStep(step - 1); });
    if (nextBtn) nextBtn.addEventListener("click", function () { setStep(step + 1); });

    function closeTrail() {
      showTrail(false);
      showSpinner(false);
      clearReveal();
      journey = null; flat = []; step = 0; loadedPageIndex = -1;
      markersLayer.innerHTML = "";
      setUrlSession(null);
      if (selectEl) selectEl.value = "";
      if (tableEl) tableEl.innerHTML = "";
      if (scrollEl) scrollEl.innerHTML = "";
    }

    // ---- deep-linking ----

    function currentUrlSession() {
      var m = window.location.search.match(/[?&]session=([a-f0-9]{64})\b/);
      return m ? m[1] : null;
    }
    function setUrlSession(hash) {
      var url = new URL(window.location.href);
      if (hash) url.searchParams.set("session", hash);
      else url.searchParams.delete("session");
      window.history.replaceState({}, "", url.toString());
    }
    var deepLinkTried = false;
    function maybeDeepLink() {
      if (deepLinkTried) return;
      deepLinkTried = true;
      var h = currentUrlSession();
      if (h) openSession(h);
    }
    function staleNote() {
      setUrlSession(null);
      // A failed deep link hid the aggregate before fetching — bring it back.
      // When a journey is already on screen (stale pick from the dropdown),
      // leave that trail up instead.
      if (!journey) showTrail(false);
      var note = document.createElement("p");
      note.className = "nab-frust-none nab-stale";
      note.textContent = "That session is no longer available.";
      listEl.insertBefore(note, listEl.firstChild);
      setTimeout(function () { if (note.parentNode) note.parentNode.removeChild(note); }, 6000);
    }

    // Bind the iframe's own scroll so markers track as the rebuilt page scrolls.
    // Re-called after every rebuild; addEventListener de-dupes the same handler,
    // so binding the live contentWindow each load is safe.
    function bindFrameScroll() {
      var win = frame.contentWindow;
      if (win) win.addEventListener("scroll", positionMarkers, { passive: true });
    }

    // Reposition markers on stage scroll and resize (iframe scroll is bound per
    // rebuild via bindFrameScroll).
    function bindReposition() {
      bindFrameScroll();
      if (stage) stage.addEventListener("scroll", positionMarkers, { passive: true });
      window.addEventListener("resize", positionMarkers);
      if (typeof ResizeObserver === "function") {
        new ResizeObserver(positionMarkers).observe(frame);
      }
    }

    bindReposition();
    // A deep-linked session replaces the aggregate view anyway: hide it before
    // any fetch so it never flashes, and open the session in parallel with the
    // list load instead of after it (renderList's own maybeDeepLink call then
    // no-ops via deepLinkTried).
    if (currentUrlSession()) {
      showTrail(true);
      maybeDeepLink();
    }
    loadList();
  });
})();
