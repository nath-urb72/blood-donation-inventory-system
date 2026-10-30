(function () {
  "use strict";

  var TYPES = ["A+", "A-", "B+", "B-", "AB+", "AB-", "O+", "O-"];
  var COMPS = ["Red cells", "Plasma", "Platelets"];
  var STATUSES = ["Stored", "Testing"];
  var MONTHS = { Jan: 0, Feb: 1, Mar: 2, Apr: 3, May: 4, Jun: 5, Jul: 6, Aug: 7, Sep: 8, Oct: 9, Nov: 10, Dec: 11 };

  var CONFIGS = [
    {
      key: "donors", section: "database", tbody: "donor-rows",
      cols: ["donor_id", "name", "blood_type", "last_donation"],
      idCols: [0], dateCols: [3],
      filters: [{ label: "blood types", col: 2, options: TYPES }]
    },
    {
      key: "bags", section: "bags", tbody: "bag-rows",
      cols: ["bag_id", "donor_id", "blood_type", "component", "date_collected", "status"],
      idCols: [0, 1], dateCols: [4],
      filters: [
        { label: "blood types", col: 2, options: TYPES },
        { label: "components", col: 3, options: COMPS },
        { label: "statuses", col: 5, options: STATUSES }
      ]
    }
  ];

  /* ---------- Helpers ---------- */
  function esc(v) {
    return String(v).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function text(el) { return el ? el.textContent.trim() : ""; }
  function parseDate(t) {
    var m = /^([A-Za-z]{3}) (\d{1,2}), (\d{4})$/.exec(t);
    return m && m[1] in MONTHS ? Date.UTC(+m[3], MONTHS[m[1]], +m[2]) : NaN;
  }
  function toISO(t) {
    var ms = parseDate(t);
    return isNaN(ms) ? t : new Date(ms).toISOString().slice(0, 10);
  }
  var toastTimer;
  function toast(msg) {
    var t = document.getElementById("toast");
    if (!t) return;
    t.textContent = msg;
    t.classList.add("show");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.classList.remove("show"); }, 2600);
  }

  /* ---------- Styles added by this script ---------- */
  var css = document.createElement("style");
  css.textContent = [
    ".db-tools{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:14px}",
    ".db-tools input,.db-tools select{font:inherit;font-size:.875rem;font-weight:500;color:var(--ink);background:var(--surface);border:1px solid var(--field-border);border-radius:999px;padding:9px 14px}",
    ".db-search{flex:1 1 220px;min-width:180px}",
    ".db-tools .btn{padding:9px 16px;font-size:.8125rem}",
    ".db-tools .btn-ghost:disabled:hover{background:transparent}",
    ".db-count{flex-basis:100%;font-size:.8125rem;color:var(--muted)}",
    ".sort-btn{border:0;background:none;font:inherit;font-weight:inherit;color:inherit;cursor:pointer;padding:0;display:inline-flex;align-items:center;gap:4px;text-align:left}",
    ".sort-btn:hover{color:var(--ink)}",
    ".sort-btn::after{content:'\\2195';opacity:.35;font-size:.75rem}",
    "th[aria-sort='ascending'] .sort-btn::after{content:'\\2191';opacity:1;color:var(--blue)}",
    "th[aria-sort='descending'] .sort-btn::after{content:'\\2193';opacity:1;color:var(--blue)}",
    ".db-pager{display:flex;justify-content:flex-end;align-items:center;gap:12px;margin-top:12px;font-size:.875rem;color:var(--muted)}",
    ".db-pager[hidden]{display:none}",
    ".db-pager .btn{padding:8px 16px;font-size:.8125rem}",
    ".db-pager .btn-ghost:disabled:hover{background:transparent}",
    "tr[hidden]{display:none}",
    ".no-match td{text-align:center;color:var(--muted);padding:28px}"
  ].join("\n");
  document.head.appendChild(css);

  /* ---------- One controller per table ---------- */
  var controllers = {};

  function setup(cfg) {
    var sec = document.getElementById(cfg.section);
    var tbody = document.getElementById(cfg.tbody);
    if (!sec || !tbody) return null;
    var card = sec.querySelector(".table-card");
    var thead = sec.querySelector("thead tr");

    var state = { q: "", f: {}, sortCol: 0, sortDir: "desc", sortSet: false, page: 1, size: 10 };
    var lastMatched = [];

    /* toolbar */
    var bar = document.createElement("div");
    bar.className = "db-tools";
    bar.innerHTML =
      '<input type="search" class="db-search" placeholder="Search ' + cfg.key + '..." aria-label="Search ' + cfg.key + '" autocomplete="off">' +
      cfg.filters.map(function (f) {
        return '<select data-col="' + f.col + '" aria-label="Filter by ' + f.label + '"><option value="">All ' + f.label + "</option>" +
          f.options.map(function (o) { return '<option value="' + esc(o) + '">' + esc(o) + "</option>"; }).join("") + "</select>";
      }).join("") +
      '<select class="db-size" aria-label="Rows per page"><option value="5">5 per page</option><option value="10" selected>10 per page</option><option value="25">25 per page</option></select>' +
      '<button type="button" class="btn btn-ghost db-clear">Clear</button>' +
      '<button type="button" class="btn btn-ghost db-export">Export CSV</button>' +
      '<p class="db-count" aria-live="polite"></p>';
    card.parentNode.insertBefore(bar, card);

    var pager = document.createElement("div");
    pager.className = "db-pager";
    pager.hidden = true;
    pager.innerHTML =
      '<button type="button" class="btn btn-ghost db-prev">Previous</button>' +
      '<span class="db-page" aria-live="polite"></span>' +
      '<button type="button" class="btn btn-ghost db-next">Next</button>';
    card.parentNode.insertBefore(pager, card.nextSibling);

    var input = bar.querySelector(".db-search");
    var selects = Array.prototype.slice.call(bar.querySelectorAll("select[data-col]"));
    var sizeSel = bar.querySelector(".db-size");
    var clearBtn = bar.querySelector(".db-clear");
    var countEl = bar.querySelector(".db-count");
    var prevBtn = pager.querySelector(".db-prev");
    var nextBtn = pager.querySelector(".db-next");
    var pageEl = pager.querySelector(".db-page");

    /* sortable column titles */
    var ths = Array.prototype.slice.call(thead.children);
    ths.forEach(function (th, i) {
      if (th.classList.contains("col-actions")) return;
      var btn = document.createElement("button");
      btn.type = "button";
      btn.className = "sort-btn";
      while (th.firstChild) btn.appendChild(th.firstChild);
      th.appendChild(btn);
      btn.addEventListener("click", function () {
        if (state.sortSet && state.sortCol === i) state.sortDir = state.sortDir === "asc" ? "desc" : "asc";
        else { state.sortCol = i; state.sortDir = "asc"; state.sortSet = true; }
        state.page = 1;
        apply();
      });
    });

    /* helpers */
    function isData(tr) { return !tr.classList.contains("no-match") && !tr.querySelector("td.empty"); }
    function keyFor(tr, col) {
      var t = text(tr.cells[col]);
      if (cfg.dateCols.indexOf(col) !== -1) { var ms = parseDate(t); return isNaN(ms) ? t.toLowerCase() : ms; }
      if (cfg.idCols.indexOf(col) !== -1) { var n = parseInt(t.replace(/\D/g, ""), 10); return isNaN(n) ? t.toLowerCase() : n; }
      return t.toLowerCase();
    }
    function compare(a, b) {
      if (typeof a === "number" && typeof b === "number") return a - b;
      return String(a).localeCompare(String(b));
    }
    function isDefault() {
      var any = state.q.trim() || state.sortSet;
      Object.keys(state.f).forEach(function (k) { if (state.f[k]) any = true; });
      return !any;
    }

    /* the observer re-applies everything whenever the page rebuilds the rows */
    var obs = new MutationObserver(function () { apply(); });

    function apply() {
      obs.disconnect();

      var old = tbody.querySelector("tr.no-match");
      if (old) old.remove();

      var rows = Array.prototype.slice.call(tbody.rows).filter(isData);

      /* donors tab: make donor_id a link to that donor's bags */
      if (cfg.key === "donors") {
        rows.forEach(function (tr) {
          var td = tr.cells[0];
          if (td.querySelector("a")) return;
          var id = text(td);
          td.innerHTML = '<a class="idlink" href="#bags" data-donor="' + esc(id) + '" title="Show bags from ' + esc(id) + '">' + esc(id) + "</a>";
        });
      }

      /* filter */
      var q = state.q.trim().toLowerCase();
      var matched = rows.filter(function (tr) {
        if (q) {
          var hay = cfg.cols.map(function (c, i) { return text(tr.cells[i]); }).join(" ").toLowerCase();
          if (hay.indexOf(q) === -1) return false;
        }
        return cfg.filters.every(function (f) {
          return !state.f[f.col] || text(tr.cells[f.col]) === state.f[f.col];
        });
      });

      /* sort (default = newest id first, which is how the app lists them) */
      var col = state.sortSet ? state.sortCol : 0;
      var dir = state.sortSet ? state.sortDir : "desc";
      rows.sort(function (a, b) {
        var r = compare(keyFor(a, col), keyFor(b, col));
        return dir === "asc" ? r : -r;
      });
      matched.sort(function (a, b) { return rows.indexOf(a) - rows.indexOf(b); });
      rows.forEach(function (tr) { tbody.appendChild(tr); });
      lastMatched = matched;

      ths.forEach(function (th, i) {
        if (state.sortSet && i === state.sortCol) th.setAttribute("aria-sort", state.sortDir === "asc" ? "ascending" : "descending");
        else th.removeAttribute("aria-sort");
      });

      /* pagination */
      var pages = Math.max(1, Math.ceil(matched.length / state.size));
      if (state.page > pages) state.page = pages;
      var from = (state.page - 1) * state.size;
      var to = from + state.size;
      rows.forEach(function (tr) { tr.hidden = matched.indexOf(tr) === -1; });
      matched.forEach(function (tr, i) { tr.hidden = i < from || i >= to; });

      /* nothing matches */
      if (rows.length && !matched.length) {
        var nm = document.createElement("tr");
        nm.className = "no-match";
        nm.innerHTML = '<td colspan="' + ths.length + '">No ' + cfg.key + " match your search or filters.</td>";
        tbody.appendChild(nm);
      }

      /* labels + buttons */
      if (!rows.length) countEl.textContent = "";
      else if (!matched.length) countEl.textContent = "No matching records.";
      else {
        countEl.textContent = "Showing " + (from + 1) + "\u2013" + Math.min(to, matched.length) + " of " + matched.length +
          (matched.length < rows.length ? " (filtered from " + rows.length + ")" : "") + ".";
      }
      pager.hidden = pages <= 1;
      pageEl.textContent = "Page " + state.page + " of " + pages;
      prevBtn.disabled = state.page <= 1;
      nextBtn.disabled = state.page >= pages;
      clearBtn.disabled = isDefault();
      bar.querySelector(".db-export").disabled = !matched.length;

      obs.observe(tbody, { childList: true });
    }

    function reset() {
      state.q = ""; state.f = {}; state.sortSet = false; state.sortCol = 0; state.sortDir = "desc"; state.page = 1;
      input.value = "";
      selects.forEach(function (s) { s.value = ""; });
    }

    /* events */
    input.addEventListener("input", function () { state.q = input.value; state.page = 1; apply(); });
    input.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && input.value) { e.stopPropagation(); input.value = ""; state.q = ""; state.page = 1; apply(); }
    });
    selects.forEach(function (s) {
      s.addEventListener("change", function () { state.f[s.dataset.col] = s.value; state.page = 1; apply(); });
    });
    sizeSel.addEventListener("change", function () { state.size = parseInt(sizeSel.value, 10); state.page = 1; apply(); });
    clearBtn.addEventListener("click", function () { reset(); apply(); input.focus(); });
    prevBtn.addEventListener("click", function () { if (state.page > 1) { state.page--; apply(); } });
    nextBtn.addEventListener("click", function () { state.page++; apply(); });

    /* export the filtered + sorted rows (all pages) as CSV */
    bar.querySelector(".db-export").addEventListener("click", function () {
      function cell(v) { v = String(v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; }
      var lines = [cfg.cols.join(",")];
      lastMatched.forEach(function (tr) {
        lines.push(cfg.cols.map(function (c, i) {
          var t = text(tr.cells[i]);
          return cell(cfg.dateCols.indexOf(i) !== -1 ? toISO(t) : t);
        }).join(","));
      });
      var blob = new Blob(["\ufeff" + lines.join("\r\n")], { type: "text/csv;charset=utf-8" });
      var a = document.createElement("a");
      a.href = URL.createObjectURL(blob);
      a.download = "blood-bank-" + cfg.key + "-" + new Date().toISOString().slice(0, 10) + ".csv";
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
      toast("Exported " + lastMatched.length + " row" + (lastMatched.length === 1 ? "" : "s") + " to CSV.");
    });

    apply();

    return {
      input: input,
      section: sec,
      apply: apply,
      showOnly: function (value) { reset(); state.q = value; input.value = value; apply(); }
    };
  }

  CONFIGS.forEach(function (cfg) { controllers[cfg.key] = setup(cfg); });

  /* ---------- Jump between the two tables through donor_id ---------- */
  var donorRows = document.getElementById("donor-rows");
  var bagRows = document.getElementById("bag-rows");

  if (donorRows && controllers.bags) {
    donorRows.addEventListener("click", function (e) {
      var a = e.target.closest("a[data-donor]");
      if (!a) return;
      controllers.bags.showOnly(a.dataset.donor);
      toast("Showing bags from " + a.dataset.donor + ".");
      /* the link's href (#bags) opens the Bags tab */
    });
  }
  if (bagRows && controllers.donors) {
    bagRows.addEventListener("click", function (e) {
      var a = e.target.closest(".idlink");
      if (!a) return;
      var id = text(a);
      controllers.donors.showOnly(id);
      toast("Showing donor " + id + ".");
      /* the link's href (#database) opens the Donors tab */
    });
  }

  /* ---------- "/" jumps to the search box ---------- */
  document.addEventListener("keydown", function (e) {
    if (e.key !== "/" || e.ctrlKey || e.metaKey || e.altKey) return;
    if (/^(INPUT|SELECT|TEXTAREA)$/.test((e.target.tagName || "")) || e.target.isContentEditable) return;
    if (document.querySelector("dialog[open]")) return;
    var key = Object.keys(controllers).filter(function (k) {
      return controllers[k] && controllers[k].section.classList.contains("active");
    })[0];
    if (!key) return;
    e.preventDefault();
    controllers[key].input.focus();
  });

  /* ---------- Small public API ---------- */
  window.BloodBankDatabase = {
    refresh: function () { Object.keys(controllers).forEach(function (k) { if (controllers[k]) controllers[k].apply(); }); },
    search: function (table, value) { if (controllers[table]) controllers[table].showOnly(value || ""); }
  };
})();