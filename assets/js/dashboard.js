(function () {
  "use strict";

  var section = document.getElementById("dashboard");
  if (!section) return;

  /* ---------- Settings & Sample Initial Data ---------- */
  var LOW_SHARE = 10;
  var ORDER = ["A+", "A-", "B+", "B-", "AB+", "AB-", "O+", "O-"];

  // Sample data restored
  var data = {
    types: { "A+": 47, "A-": 10, "B+": 33, "B-": 8, "AB+": 7, "AB-": 3, "O+": 50, "O-": 9 },
    components: { "Red cells": 92, "Plasma": 48, "Platelets": 27 }
  };

  var COMP_INFO = {
    "Red cells": "Carry oxygen. Used for surgery, trauma, and anemia. (Expires in 35-42 days)",
    "Plasma": "The liquid part of blood. Used for burns and clotting problems. (Expires in 1 year)",
    "Platelets": "Help blood clot. Used for cancer treatment and bleeding. (Expires fastest in 5 days)"
  };

  var state = { sort: "type", lowOnly: false, selected: null, comp: null };

  /* ---------- Helpers ---------- */
  function $(sel, root) { return (root || section).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || section).querySelectorAll(sel)); }
  function sum(obj) { return Object.keys(obj).reduce(function (t, k) { return t + obj[k]; }, 0); }
  function pct(n, total) { return total ? Math.round((n / total) * 100) : 0; }
  function reduceMotion() {
    return document.documentElement.getAttribute("data-motion") === "reduce" ||
      (window.matchMedia && matchMedia("(prefers-reduced-motion: reduce)").matches);
  }

  /* ---------- Inject Dynamic CSS ---------- */
  var css = document.createElement("style");
  css.textContent = [
    ".dash-controls{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:-4px 0 14px}",
    ".dash-controls .seg{display:flex;gap:4px;background:var(--bg);padding:4px;border-radius:999px}",
    ".dash-controls button{border:0;cursor:pointer;font:inherit;font-size:.8125rem;font-weight:700;color:var(--muted);background:transparent;padding:7px 14px;border-radius:999px}",
    ".dash-controls button:hover{color:var(--ink)}",
    ".dash-controls button[aria-pressed='true']{background:var(--blue);color:var(--on-blue)}",
    ".dash-controls .low-btn{border:1px solid var(--line);background:var(--surface)}",
    ".dash-controls .low-btn[aria-pressed='true']{border-color:var(--blue)}",
    ".chart .bar{transition:height .6s cubic-bezier(.2,.8,.2,1),opacity .2s,filter .2s}",
    ".chart .bar.is-low{background:var(--bar-low)}",
    ".chart .bar-col{position:relative;cursor:pointer;border-radius:12px}",
    ".chart .bar-col:hover .bar{filter:brightness(1.1)}",
    ".chart .bar-col.dim{opacity:.3}",
    ".chart .bar-col.selected .bar{outline:3px solid var(--ink);outline-offset:2px}",
    ".chart .bar-col[data-tip]:hover::after,.chart .bar-col[data-tip]:focus-visible::after{",
    "  content:attr(data-tip);position:absolute;bottom:calc(100% + 4px);left:50%;transform:translateX(-50%);",
    "  background:var(--ink);color:var(--bg);font-size:.75rem;font-weight:700;white-space:nowrap;",
    "  padding:6px 10px;border-radius:10px;z-index:5;pointer-events:none}",
    ".type-info{margin-top:12px;padding:12px 16px;border-radius:14px;background:var(--blue-soft);color:var(--ink);font-size:.9375rem}",
    ".type-info strong{font-weight:800}",
    ".type-info .ok{color:var(--ok);font-weight:700}",
    ".type-info .low{color:var(--warn);font-weight:700}",
    ".components li{cursor:pointer;border-radius:14px;padding:6px 8px;margin:-6px -8px;transition:opacity .2s,background .2s}",
    ".components li:hover{background:var(--bg)}",
    ".components li.dim{opacity:.35}",
    ".components li.selected{background:var(--bg);box-shadow:inset 0 0 0 2px var(--line)}",
    ".meter i{transition:width .7s cubic-bezier(.2,.8,.2,1)}",
    ".comp-info{margin-top:12px;font-size:.875rem;color:var(--muted)}",
    ".comp-info strong{color:var(--ink)}"
  ].join("\n");
  document.head.appendChild(css);

  /* ---------- Build Dashboard Controls ---------- */
  var chart = $(".chart");
  var chartNote = $(".chart-note");

  var controls = document.createElement("div");
  controls.className = "dash-controls";
  controls.innerHTML =
    '<div class="seg" role="group" aria-label="Sort blood types">' +
      '<button type="button" data-sort="type" aria-pressed="true">By type</button>' +
      '<button type="button" data-sort="high" aria-pressed="false">Highest</button>' +
      '<button type="button" data-sort="low" aria-pressed="false">Lowest</button>' +
    "</div>" +
    '<button type="button" class="low-btn" aria-pressed="false">Low stock only</button>';
  chart.parentNode.insertBefore(controls, chart);

  var typeInfo = document.createElement("div");
  typeInfo.className = "type-info";
  typeInfo.setAttribute("aria-live", "polite");
  chartNote.parentNode.insertBefore(typeInfo, chartNote);

  var compList = $(".components");
  var compInfo = document.createElement("p");
  compInfo.className = "comp-info";
  compInfo.setAttribute("aria-live", "polite");
  compList.parentNode.appendChild(compInfo);

  var cols = $$(".bar-col", chart);
  var colByType = {};
  cols.forEach(function (col) {
    var label = $(".bar span", col).textContent.trim();
    colByType[label] = col;
    col.setAttribute("role", "button");
    col.setAttribute("tabindex", "0");
    col.setAttribute("aria-pressed", "false");
    col.addEventListener("click", function () { selectType(label); });
    col.addEventListener("keydown", function (e) {
      if (e.key === "Enter" || e.key === " ") { e.preventDefault(); selectType(label); }
    });
  });

  function isLow(label) { return pct(data.types[label], sum(data.types)) < LOW_SHARE; }

  function drawTypes(animate) {
    var total = sum(data.types);
    ORDER.forEach(function (label) {
      var col = colByType[label];
      if (!col) return;
      var n = data.types[label] || 0;
      var share = pct(n, total);
      var bar = $(".bar", col);
      $(".bar-pct", col).textContent = share + "%";
      bar.classList.toggle("is-low", share < LOW_SHARE);
      col.dataset.tip = label + ": " + n + " bags";
      if (animate) bar.style.setProperty("--v", "0");
      else bar.style.setProperty("--v", String(share));
    });
    if (animate) {
      void chart.offsetWidth;
      requestAnimationFrame(function () {
        ORDER.forEach(function (label) {
          var col = colByType[label];
          if (col) $(".bar", col).style.setProperty("--v", String(pct(data.types[label] || 0, total)));
        });
      });
    }
    applyOrderAndFilter();
    showTypeInfo();
  }

  function applyOrderAndFilter() {
    var list = ORDER.slice();
    if (state.sort === "high") list.sort(function (a, b) { return data.types[b] - data.types[a]; });
    if (state.sort === "low") list.sort(function (a, b) { return data.types[a] - data.types[b]; });
    list.forEach(function (label) { chart.appendChild(colByType[label]); });
    ORDER.forEach(function (label) {
      var col = colByType[label];
      var hide = state.lowOnly && !isLow(label);
      var other = state.selected && state.selected !== label;
      col.classList.toggle("dim", hide || !!other);
      col.classList.toggle("selected", state.selected === label);
      col.setAttribute("aria-pressed", String(state.selected === label));
    });
  }

  function showTypeInfo() {
    var total = sum(data.types);
    if (state.selected) {
      var n = data.types[state.selected], share = pct(n, total), low = share < LOW_SHARE;
      typeInfo.innerHTML = "<strong>" + state.selected + "</strong>: " + n + " bags (" + share + "% of stock). " +
        (low ? '<span class="low">Low stock, more donors needed.</span>' : '<span class="ok">Stock is OK.</span>');
      return;
    }
    var lows = ORDER.filter(isLow);
    typeInfo.innerHTML = lows.length
      ? "<strong>" + lows.length + " blood type" + (lows.length > 1 ? "s are" : " is") + " low:</strong> " +
        lows.join(", ") + ". Select a bar for details."
      : "All blood types are well stocked. Select a bar for details.";
  }

  function selectType(label) {
    state.selected = state.selected === label ? null : label;
    applyOrderAndFilter();
    showTypeInfo();
  }

  $$("[data-sort]", controls).forEach(function (btn) {
    btn.addEventListener("click", function () {
      state.sort = btn.dataset.sort;
      $$("[data-sort]", controls).forEach(function (b) { b.setAttribute("aria-pressed", String(b === btn)); });
      applyOrderAndFilter();
    });
  });
  $(".low-btn", controls).addEventListener("click", function (e) {
    state.lowOnly = !state.lowOnly;
    e.currentTarget.setAttribute("aria-pressed", String(state.lowOnly));
    applyOrderAndFilter();
    showTypeInfo();
  });

  /* ---------- Total Bags Counter ---------- */
  var big = $(".big-number");
  var bigText = null;
  Array.prototype.forEach.call(big.childNodes, function (n) { if (!bigText && n.nodeType === 3) bigText = n; });
  if (!bigText) { bigText = document.createTextNode("0"); big.insertBefore(bigText, big.firstChild); }
  var countTimer = null;

  function drawTotal(animate) {
    var target = sum(data.types);
    cancelAnimationFrame(countTimer);
    if (!animate || reduceMotion()) { bigText.nodeValue = String(target); return; }
    var start = null, dur = 900;
    function step(ts) {
      if (start === null) start = ts;
      var t = Math.min(1, (ts - start) / dur);
      var eased = 1 - Math.pow(1 - t, 3);
      bigText.nodeValue = String(Math.round(target * eased));
      if (t < 1) countTimer = requestAnimationFrame(step);
    }
    bigText.nodeValue = "0";
    countTimer = requestAnimationFrame(step);
  }

  /* ---------- Component Meters ---------- */
  var compItems = $$("li", compList);
  var compNames = ["Red cells", "Plasma", "Platelets"];

  compItems.forEach(function (li, i) {
    var name = compNames[i];
    li.setAttribute("role", "button");
    li.setAttribute("tabindex", "0");
    li.setAttribute("aria-pressed", "false");
    function toggle() { state.comp = state.comp === name ? null : name; markComponents(); }
    li.addEventListener("click", toggle);
    li.addEventListener("keydown", function (e) {
      if (e.key === "Enter" || e.key === " ") { e.preventDefault(); toggle(); }
    });
  });

  function markComponents() {
    compItems.forEach(function (li, i) {
      var name = compNames[i];
      li.classList.toggle("selected", state.comp === name);
      li.classList.toggle("dim", !!state.comp && state.comp !== name);
      li.setAttribute("aria-pressed", String(state.comp === name));
    });
    compInfo.innerHTML = state.comp
      ? "<strong>" + state.comp + ":</strong> " + COMP_INFO[state.comp]
      : "Select a component to see what it is used for.";
  }

  function drawComponents(animate) {
    var total = sum(data.components);
    compItems.forEach(function (li, i) {
      var name = compNames[i], n = data.components[name] || 0, share = pct(n, total);
      var fill = $(".meter i", li), label = $(".meter i span", li);
      $(".comp-row span:last-child", li).textContent = n + " bags";
      label.textContent = share + "%";
      if (animate) fill.style.setProperty("--w", "0%");
      else fill.style.setProperty("--w", share + "%");
      if (animate) {
        void fill.offsetWidth;
        requestAnimationFrame(function () { fill.style.setProperty("--w", share + "%"); });
      }
    });
    markComponents();
  }

  /* ---------- Fetch Live API Statistics (With Sample Data Fallback) ---------- */
  function fetchStatsAndRender() {
    fetch('backend/api.php?action=get_stats')
      .then(function (res) {
        if (!res.ok) throw new Error("API Status: " + res.status);
        return res.json();
      })
      .then(function (stats) {
        var hasRealTypeData = stats && stats.types && Object.values(stats.types).some(function(v) { return Number(v) > 0; });
        var hasRealCompData = stats && stats.components && Object.values(stats.components).some(function(v) { return Number(v) > 0; });

        if (hasRealTypeData) {
          ORDER.forEach(function (k) { data.types[k] = Number(stats.types[k]) || 0; });
        }
        if (hasRealCompData) {
          compNames.forEach(function (k) { data.components[k] = Number(stats.components[k]) || 0; });
        }

        var animate = !reduceMotion();
        drawTypes(animate);
        drawComponents(animate);
        drawTotal(true);
      })
      .catch(function (err) {
        console.warn("Using sample dashboard data:", err);
        drawTypes(false);
        drawComponents(false);
        drawTotal(false);
      });
  }

  window.BloodBankDashboard = {
    update: function (next) {
      if (next && next.types) ORDER.forEach(function (k) { if (k in next.types) data.types[k] = Number(next.types[k]) || 0; });
      if (next && next.components) compNames.forEach(function (k) { if (k in next.components) data.components[k] = Number(next.components[k]) || 0; });
      drawTypes(false);
      drawComponents(false);
      drawTotal(false);
    },
    getData: function () { return JSON.parse(JSON.stringify(data)); }
  };

  /* Render Initial Dashboard */
  drawTypes(false);
  drawComponents(false);
  drawTotal(false);

  var wasActive = section.classList.contains("active");
  new MutationObserver(function () {
    var now = section.classList.contains("active");
    if (now && !wasActive) {
      fetchStatsAndRender();
    }
    wasActive = now;
  }).observe(section, { attributes: true, attributeFilter: ["class"] });

  if (wasActive) {
    fetchStatsAndRender();
  }
})();