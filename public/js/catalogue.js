// public/js/catalogue.js
(function () {
  const CFG = window.CATALOG_CONFIG || {};
  if (!CFG.csvUrl) {
    console.warn("[catalogue.js] CATALOG_CONFIG.csvUrl manquant");
    return;
  }

  // -----------------------------
  // Helpers
  // -----------------------------
  const LOCALE = CFG.locale || "fr-FR";
  const CURRENCY = CFG.currency || "MAD";

  const el = (id) => document.getElementById(id);

  function toNumber(v) {
    if (v === null || v === undefined) return NaN;
    let s = String(v).trim();
    if (!s) return NaN;
    s = s.replace(/\s/g, "").replace(",", ".").replace(/[^0-9.\-]/g, "");
    const n = Number(s);
    return Number.isFinite(n) ? n : NaN;
  }

  function escHtml(s) {
    return String(s ?? "")
      .replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;").replaceAll("'", "&#039;");
  }

  function fmtMoney(n) {
    if (!Number.isFinite(n)) return "—";
    try {
      return new Intl.NumberFormat(LOCALE, {
        style: "currency",
        currency: CURRENCY,
        maximumFractionDigits: 2
      }).format(n);
    } catch (e) {
      return n.toFixed(2) + " " + CURRENCY;
    }
  }

  function debounce(fn, ms = 250) {
    let t;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), ms);
    };
  }

  function stripQuotes(s) {
    if (s === null || s === undefined) return "";
    s = String(s).trim();
    if (s.startsWith('"') && s.endsWith('"')) s = s.slice(1, -1);
    return s.trim();
  }

  function field(r, ...names) {
    for (const n of names) {
      const v = r?.[n];
      if (v !== undefined && v !== null && String(v).trim() !== "") return String(v).trim();
    }
    return "";
  }

  // -----------------------------
  // Parse date "robuste"
  // -----------------------------
  function parseDateAny(s) {
    if (!s) return null;
    let raw = stripQuotes(String(s).trim());
    if (!raw) return null;

    // dd/mm/yyyy [hh:mm[:ss]]
    let m = raw.match(/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})(?:\s+(\d{2}):(\d{2})(?::(\d{2}))?)?$/);
    if (m) {
      const dd = +m[1], mm = +m[2], yyyy = +m[3];
      const hh = +(m[4] || 0), mi = +(m[5] || 0), ss = +(m[6] || 0);
      const d = new Date(yyyy, mm - 1, dd, hh, mi, ss);
      return isNaN(d.getTime()) ? null : d;
    }

    // ISO-ish
    if (/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/.test(raw)) raw = raw.replace(" ", "T");
    raw = raw.replace(/(\.\d{3})\d+/, "$1");

    m = raw.match(/^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2})(?::(\d{2}))?)?$/);
    if (m) {
      const yyyy = +m[1], mm = +m[2], dd = +m[3];
      const hh = +(m[4] || 0), mi = +(m[5] || 0), ss = +(m[6] || 0);
      const d = new Date(yyyy, mm - 1, dd, hh, mi, ss);
      return isNaN(d.getTime()) ? null : d;
    }

    // fallback
    const d = new Date(raw);
    return isNaN(d.getTime()) ? null : d;
  }

  function rowNewnessDate(r) {
    // tu peux basculer sur sysModifiedDate si tu préfères
    return parseDateAny(field(r, "sysCreatedDate"));
  }

  function isNewRow(r) {
    const d = rowNewnessDate(r);
    if (!d) return false;
    const days = (Date.now() - d.getTime()) / 86400000;
    const nd = Number.isFinite(+CFG.newDays) ? +CFG.newDays : 5;
    return days >= 0 && days <= nd;
  }

  // -----------------------------
  // DOM refs (doivent exister dans _catalogue.html.twig)
  // -----------------------------
  const grid = el("grid");
  const chips = el("chips");
  const empty = el("empty");
  const count = el("count");
  const total = el("total");
  const hint = el("hint");

  const qTop = el("qTop");
  const btnClearQ = el("btnClearQ");
  const btnMore = el("btnMore");

  // Filtres
  const fBrand = el("fBrand");
  const fFamily = el("fFamily");
  const fVehicle = el("fVehicle");
  const fYearMin = el("fYearMin");
  const fYearMax = el("fYearMax");
  const fPriceMin = el("fPriceMin");
  const fPriceMax = el("fPriceMax");
  const fSort = el("fSort");

  // -----------------------------
  // Panier global (CartUI)
  // -----------------------------
  const CartUI = window.CartUI;

  // état local miroir (on lit toujours le vrai depuis CartUI.getState())
  function getCartState() {
    try {
      return (CartUI && typeof CartUI.getState === "function") ? (CartUI.getState() || {}) : {};
    } catch (_) {
      return {};
    }
  }

  // -----------------------------
  // Data catalogue
  // -----------------------------
  let allRows = [];
  let filteredRows = [];
  let allRowsByUid = new Map();

  const PAGE_SIZE = 8;
  let page = 1;

  let priceMode = "inc"; // "inc" ou "exc"
  const cardQtyDraft = new Map();

  function getUid(r) {
    return field(r, "UniqueId") || field(r, "Id", "ID", "id");
  }

  function getRef(r) {
    return field(r, "Id", "ID", "id") || getUid(r);
  }

  function getPrice(r, pm) {
    const key = (pm === "inc") ? "SalePriceVatIncluded" : "SalePriceVatExcluded";
    return toNumber(field(r, key));
  }

  function getStock(r) {
    return toNumber(field(r, "RealStock"));
  }

  function getImageUrlByUid(uid) {
    const base = CFG.imagesBase || "";
    return uid && base ? `${base}/${encodeURIComponent(uid)}.png` : "";
  }

  // -----------------------------
  // Filtres : responsive (move #filtersCard)
  // -----------------------------
  function mountFiltersResponsive() {
    const card = el("filtersCard");
    const desktopMount = el("filtersDesktopMount");
    const mobileMount = el("filtersClone");
    if (!card || !desktopMount || !mobileMount) return;

    const isMobile = window.matchMedia("(max-width: 991.98px)").matches;
    if (isMobile) {
      mobileMount.appendChild(card);
      card.classList.remove("sticky-filters");
    } else {
      desktopMount.appendChild(card);
      card.classList.add("sticky-filters");
    }
  }

  // -----------------------------
  // Facets
  // -----------------------------
  function uniqueSorted(arr) {
    return Array.from(new Set(arr.filter(Boolean))).sort((a, b) => String(a).localeCompare(String(b), "fr"));
  }

  function fillSelect(select, values, allLabel) {
    if (!select) return;
    const prev = select.value;
    select.innerHTML = "";

    const opt = document.createElement("option");
    opt.value = "";
    opt.textContent = allLabel;
    select.appendChild(opt);

    for (const v of values) {
      const o = document.createElement("option");
      o.value = v;
      o.textContent = v;
      select.appendChild(o);
    }

    if (prev && values.includes(prev)) select.value = prev;
  }

  function buildFacets() {
    const brands = [];
    const families = [];
    const vehicles = [];

    for (const r of allRows) {
      const b = field(r, "xx_Marque");
      const f = field(r, "FamilyName");
      const v = field(r, "xx_Vehicule");
      if (b) brands.push(b);
      if (f) families.push(f);
      if (v) vehicles.push(v);
    }

    fillSelect(fBrand, uniqueSorted(brands), "Toutes");
    fillSelect(fFamily, uniqueSorted(families), "Toutes");
    fillSelect(fVehicle, uniqueSorted(vehicles), "Tous");
  }

  // -----------------------------
  // UI chips
  // -----------------------------
  function getActiveFilters() {
    const q = (qTop?.value || "").trim();
    const brand = (fBrand?.value || "").trim();
    const family = (fFamily?.value || "").trim();
    const vehicle = (fVehicle?.value || "").trim();

    const yearMin = toNumber(fYearMin?.value);
    const yearMax = toNumber(fYearMax?.value);
    const priceMin = toNumber(fPriceMin?.value);
    const priceMax = toNumber(fPriceMax?.value);

    const stockMode = document.querySelector('input[name="stockMode"]:checked')?.value || "all";
    const sort = fSort?.value || "relevance";

    return {
      q, brand, family, vehicle,
      yearMin: Number.isFinite(yearMin) ? yearMin : null,
      yearMax: Number.isFinite(yearMax) ? yearMax : null,
      priceMin: Number.isFinite(priceMin) ? priceMin : null,
      priceMax: Number.isFinite(priceMax) ? priceMax : null,
      stockMode, sort
    };
  }

  function renderChips(active) {
    if (!chips || !hint) return;
    chips.innerHTML = "";

    const items = [];
    if (active.q) items.push({ k: "q", label: `Recherche: ${active.q}` });
    if (active.brand) items.push({ k: "brand", label: `Marque: ${active.brand}` });
    if (active.family) items.push({ k: "family", label: `Famille: ${active.family}` });
    if (active.vehicle) items.push({ k: "vehicle", label: `Véhicule: ${active.vehicle}` });
    if (active.yearMin !== null) items.push({ k: "yearMin", label: `Année min: ${active.yearMin}` });
    if (active.yearMax !== null) items.push({ k: "yearMax", label: `Année max: ${active.yearMax}` });
    if (active.priceMin !== null) items.push({ k: "priceMin", label: `Prix min: ${active.priceMin}` });
    if (active.priceMax !== null) items.push({ k: "priceMax", label: `Prix max: ${active.priceMax}` });
    if (active.stockMode === "in") items.push({ k: "stockMode", label: `Stock: En stock` });
    if (active.stockMode === "out") items.push({ k: "stockMode", label: `Stock: Rupture` });

    if (items.length === 0) {
      hint.textContent = (CFG.pageMode === "new")
        ? `Filtre automatique : nouveautés sur ${CFG.newDays || 5} jours.`
        : "";
      return;
    }

    hint.textContent = "";

    for (const it of items) {
      const chip = document.createElement("span");
      chip.className = "chip";
      chip.innerHTML = `<span>${escHtml(it.label)}</span><button type="button" title="Retirer"><i class="bi bi-x"></i></button>`;
      chip.querySelector("button").addEventListener("click", () => removeChip(it.k));
      chips.appendChild(chip);
    }
  }

  function removeChip(k) {
    if (k === "q" && qTop) qTop.value = "";
    if (k === "brand" && fBrand) fBrand.value = "";
    if (k === "family" && fFamily) fFamily.value = "";
    if (k === "vehicle" && fVehicle) fVehicle.value = "";
    if (k === "yearMin" && fYearMin) fYearMin.value = "";
    if (k === "yearMax" && fYearMax) fYearMax.value = "";
    if (k === "priceMin" && fPriceMin) fPriceMin.value = "";
    if (k === "priceMax" && fPriceMax) fPriceMax.value = "";
    if (k === "stockMode") {
      const smAll = el("smAll");
      if (smAll) smAll.checked = true;
    }
    applyFilters();
  }

  // -----------------------------
  // Sorting
  // -----------------------------
  function sortRows(rows, active) {
    const copy = rows.slice();

    if (active.sort === "newest") {
      copy.sort((a, b) => {
        const da = rowNewnessDate(a)?.getTime() || 0;
        const db = rowNewnessDate(b)?.getTime() || 0;
        return db - da;
      });
      return copy;
    }

    if (active.sort === "priceAsc") {
      copy.sort((a, b) => (getPrice(a, priceMode) || Infinity) - (getPrice(b, priceMode) || Infinity));
      return copy;
    }
    if (active.sort === "priceDesc") {
      copy.sort((a, b) => (getPrice(b, priceMode) || -Infinity) - (getPrice(a, priceMode) || -Infinity));
      return copy;
    }
    if (active.sort === "stockDesc") {
      copy.sort((a, b) => (getStock(b) || -Infinity) - (getStock(a) || -Infinity));
      return copy;
    }
    if (active.sort === "az") {
      copy.sort((a, b) => field(a, "DesComClear").localeCompare(field(b, "DesComClear"), "fr"));
      return copy;
    }

    return copy; // relevance = ordre original
  }

  // -----------------------------
  // Filters apply + render
  // -----------------------------
  function applyFilters() {
    if (!allRows.length) {
      if (count) count.textContent = "0";
      if (grid) grid.innerHTML = "";
      if (empty) empty.classList.remove("d-none");
      if (btnMore) btnMore.classList.add("d-none");
      return;
    }

    const active = getActiveFilters();
    renderChips(active);

    const tokens = active.q ? active.q.toLowerCase().split(/\s+/).filter(Boolean) : [];

    let res = allRows.filter((r) => {
      if (CFG.pageMode === "new" && !isNewRow(r)) return false;

      if (active.brand && field(r, "xx_Marque") !== active.brand) return false;
      if (active.family && field(r, "FamilyName") !== active.family) return false;
      if (active.vehicle && field(r, "xx_Vehicule") !== active.vehicle) return false;

      const y = toNumber(field(r, "xx_Annee"));
      if (active.yearMin !== null && (!Number.isFinite(y) || y < active.yearMin)) return false;
      if (active.yearMax !== null && (!Number.isFinite(y) || y > active.yearMax)) return false;

      const st = getStock(r);
      const inStock = Number.isFinite(st) && st > 0;
      if (active.stockMode === "in" && !inStock) return false;
      if (active.stockMode === "out" && inStock) return false;

      const pr = getPrice(r, priceMode);
      if (active.priceMin !== null && (!Number.isFinite(pr) || pr < active.priceMin)) return false;
      if (active.priceMax !== null && (!Number.isFinite(pr) || pr > active.priceMax)) return false;

      if (tokens.length) {
        const blob = (r.__search || "");
        for (const t of tokens) {
          if (!blob.includes(t)) return false;
        }
      }

      return true;
    });

    res = sortRows(res, active);
    filteredRows = res;

    if (count) count.textContent = String(filteredRows.length);

    page = 1;
    renderVisible();
  }

  function renderVisible() {
    const visible = filteredRows.slice(0, page * PAGE_SIZE);
    renderGrid(visible);

    const done = visible.length >= filteredRows.length;
    if (btnMore) btnMore.classList.toggle("d-none", done || filteredRows.length === 0);
  }

  // -----------------------------
  // Cards
  // -----------------------------
  function updateCardCartBadges() {
    const cart = getCartState();
    document.querySelectorAll("[data-card-badge]").forEach((b) => {
      const uid = b.getAttribute("data-card-badge");
      const q = Math.floor(toNumber(cart?.[uid]) || 0);

      if (q > 0) {
        b.classList.remove("d-none");
        b.innerHTML = `<i class="bi bi-cart-check"></i><span>${q}</span>`;
      } else {
        b.classList.add("d-none");
        b.innerHTML = "";
      }
    });
  }

  function buildCard(r) {
    const uid = getUid(r);
    const ref = getRef(r);

    const cap = field(r, "DesComClear") || "Produit";
    const brand = field(r, "xx_Marque");
    const family = field(r, "FamilyName");
    const veh = field(r, "xx_Vehicule");
    const year = field(r, "xx_Annee");

    const stock = getStock(r);
    const stockOk = Number.isFinite(stock) && stock > 0;

    const price = getPrice(r, priceMode);
    const isNew = isNewRow(r);

    const draft = Math.max(1, Math.floor(toNumber(cardQtyDraft.get(uid)) || 1));
    if (uid) cardQtyDraft.set(uid, draft);

    const cart = getCartState();
    const inCartQty = uid ? Math.floor(toNumber(cart?.[uid]) || 0) : 0;

    const imgUrl = getImageUrlByUid(uid);

    const col = document.createElement("div");
    col.className = "col-12 col-sm-6 col-xl-3";
    const disabled = !uid ? "disabled" : "";

    col.innerHTML = `
      <div class="catalog-card h-100 p-3">

        <span class="card-cart-pill ${inCartQty > 0 ? "" : "d-none"}" data-card-badge="${escHtml(uid)}">
          <i class="bi bi-cart-check"></i><span>${inCartQty}</span>
        </span>

        <div class="thumb ratio ratio-4x3 mb-3">
          ${imgUrl
            ? `<img src="${escHtml(imgUrl)}" alt="${escHtml(cap)}" loading="lazy"
                onerror="this.onerror=null;this.src='data:image/svg+xml;utf8,${encodeURIComponent(
                  `<svg xmlns="http://www.w3.org/2000/svg" width="600" height="450">
                     <rect width="100%" height="100%" fill="%23f3f4f6"/>
                     <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle"
                       fill="%236b7280" font-family="Arial" font-size="20">Image indisponible</text>
                   </svg>`
                )}';">`
            : `<div class="d-flex align-items-center justify-content-center text-muted small">Photo</div>`
          }
        </div>

        <div class="d-flex flex-wrap gap-2 mb-2">
          ${isNew ? `<span class="badge rounded-pill text-bg-warning"><i class="bi bi-stars me-1"></i>Nouveau</span>` : ``}
          ${brand ? `<span class="badge rounded-pill badge-soft">${escHtml(brand)}</span>` : ""}
          ${family ? `<span class="badge rounded-pill badge-family">${escHtml(family)}</span>` : ""}
          <span class="badge rounded-pill ${stockOk ? "text-bg-success" : "text-bg-danger"}">
            ${stockOk ? "Dispo" : "Rupture"}
          </span>
        </div>

        <div class="fw-semibold fs-6 card-title-trunc">${escHtml(cap)}</div>
        <div class="mt-2 small text-muted">${escHtml([veh, year].filter(Boolean).join(" • "))}</div>

        <div class="d-flex align-items-center justify-content-between mt-3">
          <div class="fw-bold text-primary">${Number.isFinite(price) ? escHtml(fmtMoney(price)) : "—"}</div>
          <div class="small text-muted">${priceMode === "inc" ? "TTC" : "HT"}</div>
        </div>

        <div class="mt-3 small text-muted">
          ${ref ? `Référence : <span class="fw-semibold">${escHtml(ref)}</span>` : `<span class="text-danger">Référence manquante</span>`}
        </div>

        <div class="mt-3">
          <form class="d-flex align-items-center gap-2" data-cart-form ${disabled}>
            <input type="hidden" name="id" value="${escHtml(uid)}">

            <div class="input-group input-group-sm" style="width: 140px;">
              <button class="btn btn-outline-secondary" type="button" data-action="qty-dec" data-uid="${escHtml(uid)}" ${disabled}>
                <i class="bi bi-dash"></i>
              </button>
              <input class="form-control text-center" name="qty" type="number" min="1" value="${draft}"
                     data-action="qty-input" data-uid="${escHtml(uid)}" ${disabled}>
              <button class="btn btn-outline-secondary" type="button" data-action="qty-inc" data-uid="${escHtml(uid)}" ${disabled}>
                <i class="bi bi-plus"></i>
              </button>
            </div>

            <button class="btn btn-primary btn-sm flex-fill" type="submit" ${disabled} title="Ajouter au panier">
              <i class="bi bi-cart-plus"></i>
            </button>
          </form>
        </div>
      </div>
    `;
    return col;
  }

  function renderGrid(rows) {
    if (!grid) return;
    grid.innerHTML = "";
    if (empty) empty.classList.toggle("d-none", rows.length !== 0);

    for (const r of rows) {
      grid.appendChild(buildCard(r));
    }

    updateCardCartBadges();
  }

  // Qty buttons
  if (grid) {
    grid.addEventListener("click", (e) => {
      const btn = e.target.closest("[data-action]");
      if (!btn) return;

      const action = btn.getAttribute("data-action");
      const uid = btn.getAttribute("data-uid");
      if (!uid) return;

      const form = btn.closest("form");
      const input = form?.querySelector(`input[name="qty"][data-uid="${uid}"]`);
      if (!input) return;

      let q = Math.max(1, Math.floor(toNumber(input.value) || 1));
      if (action === "qty-dec") {
        q = Math.max(1, q - 1);
        input.value = String(q);
        cardQtyDraft.set(uid, q);
      }
      if (action === "qty-inc") {
        q = q + 1;
        input.value = String(q);
        cardQtyDraft.set(uid, q);
      }
    });

    grid.addEventListener("input", debounce((e) => {
      const inp = e.target.closest("[data-action='qty-input']");
      if (!inp) return;
      const uid = inp.getAttribute("data-uid");
      if (!uid) return;
      cardQtyDraft.set(uid, Math.max(1, Math.floor(toNumber(inp.value) || 1)));
    }, 120));

    // Add to cart -> CartUI (global)
    grid.addEventListener("submit", async (e) => {
      const form = e.target.closest("[data-cart-form]");
      if (!form) return;
      e.preventDefault();

      const fd = new FormData(form);
      const uid = String(fd.get("id") || "").trim();
      const qty = Math.max(1, Math.floor(toNumber(fd.get("qty")) || 1));
      if (!uid) return;

      try {
        if (CartUI && typeof CartUI.add === "function") {
          await CartUI.add(uid, qty);
          if (typeof window.showToast === "function") window.showToast(`Ajouté au panier (x${qty})`, "success");
        } else {
          console.warn("[catalogue.js] CartUI.add indisponible");
        }
      } catch (err) {
        console.error(err);
        if (typeof window.showToast === "function") window.showToast("Erreur lors de l'ajout au panier.", "danger");
      } finally {
        updateCardCartBadges();
      }
    });
  }

  // -----------------------------
  // Wire filters inputs
  // -----------------------------
  function wireFilters() {
    const applyDebounced = debounce(applyFilters, 220);

    if (qTop) qTop.addEventListener("input", applyDebounced);
    if (btnClearQ) btnClearQ.addEventListener("click", () => { if (qTop) qTop.value = ""; applyFilters(); });

    [fBrand, fFamily, fVehicle, fSort].forEach((x) => {
      if (x) x.addEventListener("change", applyFilters);
    });

    [fYearMin, fYearMax, fPriceMin, fPriceMax].forEach((x) => {
      if (x) x.addEventListener("input", applyDebounced);
    });

    document.querySelectorAll('input[name="stockMode"]').forEach((r) => r.addEventListener("change", applyFilters));

    document.querySelectorAll('input[name="priceMode"]').forEach((r) => {
      r.addEventListener("change", () => {
        priceMode = document.querySelector('input[name="priceMode"]:checked')?.value || "inc";
        applyFilters();
        // refresh total/prices in offcanvas if open
        if (CartUI && typeof CartUI.render === "function") CartUI.render();
      });
    });

    const btnReset2 = el("btnReset2");
    const btnReset = el("btnReset");
    if (btnReset2) btnReset2.addEventListener("click", resetFilters);
    if (btnReset) btnReset.addEventListener("click", resetFilters);
  }

  function resetFilters() {
    if (qTop) qTop.value = "";
    if (fBrand) fBrand.value = "";
    if (fFamily) fFamily.value = "";
    if (fVehicle) fVehicle.value = "";
    if (fYearMin) fYearMin.value = "";
    if (fYearMax) fYearMax.value = "";
    if (fPriceMin) fPriceMin.value = "";
    if (fPriceMax) fPriceMax.value = "";
    const smAll = el("smAll");
    if (smAll) smAll.checked = true;
    if (fSort) fSort.value = "relevance";

    const pmInc = el("pmInc");
    if (pmInc) pmInc.checked = true;
    priceMode = "inc";

    applyFilters();
    if (CartUI && typeof CartUI.render === "function") CartUI.render();
  }

  // -----------------------------
  // CSV load + hydrate
  // -----------------------------
  async function loadCsv() {
    if (hint) hint.textContent = "Chargement du catalogue…";

    let res, text;
    try {
      res = await fetch(CFG.csvUrl, {
        cache: "no-store",
        credentials: "same-origin",
        headers: { "Accept": "text/csv,*/*" }
      });
      text = await res.text();
    } catch (err) {
      console.error("FETCH CSV FAILED:", err);
      if (hint) hint.textContent = "Erreur réseau vers /catalogue/csv. Voir console.";
      return;
    }

    if (!res.ok) {
      console.error("CSV HTTP ERROR", res.status, res.statusText);
      console.error("BODY (first 800):", (text || "").slice(0, 800));
      if (hint) hint.textContent = `Erreur CSV HTTP ${res.status}. Voir console.`;
      return;
    }

    if (!window.Papa) {
      console.error("PapaParse manquant (papaparse)");
      if (hint) hint.textContent = "PapaParse manquant. Vérifie le chargement du CDN.";
      return;
    }

    window.Papa.parse(text, {
      header: true,
      delimiter: ";",
      skipEmptyLines: true,
      transformHeader: (h) => stripQuotes(h),
      transform: (v) => stripQuotes(v),
      complete: (results) => {
        let rows = (results.data || [])
          .map((raw) => {
            const out = {};
            for (const k in raw) out[stripQuotes(k)] = stripQuotes(raw[k]);
            return out;
          })
          .filter((r) => Object.values(r).some((v) => String(v || "").trim().length));

        hydrate(rows);
      }
    });
  }

  function hydrate(rows) {
    allRows = rows;
    allRowsByUid = new Map();

    for (const r of allRows) {
      r.__search = [
        field(r, "DesComClear"),
        field(r, "Id"),
        field(r, "UniqueId"),
        field(r, "xx_Marque"),
        field(r, "xx_Vehicule"),
        field(r, "FamilyName"),
        field(r, "xx_Position"),
        field(r, "xx_Annee"),
      ].join(" ").toLowerCase();

      const uid = getUid(r);
      if (uid) allRowsByUid.set(uid, r);
    }

    if (total) total.textContent = String(allRows.length);
    buildFacets();

    if (hint) hint.textContent = "";
    applyFilters();
    updateCardCartBadges();

    // debug nouveautés
    if (CFG.pageMode === "new") {
      const nd = Number.isFinite(+CFG.newDays) ? +CFG.newDays : 5;
      const nbNew = allRows.filter(isNewRow).length;
      console.log("[NOUVEAUTES] newDays=", nd, "items=", nbNew, "sur", allRows.length);
    }

    // -----------------------------
    // ✅ Resolver prix/labels UNIQUEMENT sur Catalogue
    // -----------------------------
    window.CART_RESOLVER = function (uid) {
      const r = allRowsByUid.get(uid);
      if (!r) return { title: uid, subtitle: "ID: " + uid };

      const title = field(r, "DesComClear") || "Produit";
      const brand = field(r, "xx_Marque");
      const family = field(r, "FamilyName");
      const veh = field(r, "xx_Vehicule");
      const year = field(r, "xx_Annee");
      const ref = getRef(r) || uid;

      const pm = document.querySelector('input[name="priceMode"]:checked')?.value || priceMode || "inc";
      const price = getPrice(r, pm);
      const priceLabel = Number.isFinite(price) ? fmtMoney(price) : null;

      const subtitleParts = [];
      if (brand) subtitleParts.push(brand);
      if (family) subtitleParts.push(family);
      if (veh || year) subtitleParts.push([veh, year].filter(Boolean).join(" • "));
      subtitleParts.push("Réf: " + ref);

      return {
        title,
        subtitle: subtitleParts.join(" • "),
        price: Number.isFinite(price) ? price : NaN,
        priceLabel
      };
    };

    // Sync le badge + offcanvas après chargement CSV
    if (CartUI && typeof CartUI.sync === "function") {
      CartUI.sync().catch(() => {});
    }
  }

  // -----------------------------
  // ✅ Total formaté dans le panier (uniquement ici)
  // On wrap CartUI.render pour formatter le total quand il est affiché.
  // -----------------------------
  function computeCartTotalFromResolver() {
    const cart = getCartState();
    const ids = Object.keys(cart).filter((k) => (toNumber(cart[k]) || 0) > 0);
    if (!ids.length) return { hasAnyPrice: false, total: 0 };

    let totalVal = 0;
    let hasAnyPrice = false;

    for (const uid of ids) {
      const qty = Math.floor(toNumber(cart[uid]) || 0);
      const info = (typeof window.CART_RESOLVER === "function") ? window.CART_RESOLVER(uid) : null;
      const price = Number.isFinite(info?.price) ? info.price : NaN;
      if (Number.isFinite(price)) {
        hasAnyPrice = true;
        totalVal += price * qty;
      }
    }
    return { hasAnyPrice, total: totalVal };
  }

  function wrapCartUiRender() {
    if (!CartUI || typeof CartUI.render !== "function") return;

    // éviter double wrap
    if (CartUI.__catalogueWrapped) return;
    CartUI.__catalogueWrapped = true;

    const originalRender = CartUI.render.bind(CartUI);

    CartUI.render = function () {
      originalRender();

      // Update badges sur les cards (quand panier change depuis offcanvas)
      updateCardCartBadges();

      // Total formaté si visible
      const totalWrap = el("cartTotalWrap");
      const totalEl = el("cartTotal");
      if (!totalWrap || !totalEl) return;

      if (totalWrap.classList.contains("d-none")) return;

      const r = computeCartTotalFromResolver();
      if (r.hasAnyPrice) {
        totalEl.textContent = fmtMoney(r.total);
      }
    };
  }

  // -----------------------------
  // Init
  // -----------------------------
  function init() {
    // DOM requis
    if (!grid || !qTop || !btnMore || !el("filtersDesktopMount") || !el("filtersClone")) {
      console.warn("[catalogue.js] DOM catalogue incomplet (vérifie _catalogue.html.twig)");
      // on continue quand même
    }

    // init priceMode
    priceMode = document.querySelector('input[name="priceMode"]:checked')?.value || "inc";

    // mount filters card between desktop/offcanvas
    mountFiltersResponsive();
    window.addEventListener("resize", debounce(mountFiltersResponsive, 150));

    // wire filters
    wireFilters();

    // more button
    if (btnMore) btnMore.addEventListener("click", () => { page++; renderVisible(); });

    // wrap render for total formatting + card badges refresh
    wrapCartUiRender();

    // sync cart badge quickly
    if (CartUI && typeof CartUI.sync === "function") {
      CartUI.sync().catch(() => {});
    }

    // load CSV
    loadCsv().catch((err) => {
      console.error(err);
      if (hint) hint.textContent = "Impossible de charger le CSV. Vérifie /catalogue/csv.";
    });
  }

  document.addEventListener("DOMContentLoaded", init);
})();
