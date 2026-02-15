// public/js/cart-ui.js
// Offcanvas panier + contrôle stock (stock - réservations actives - panier)
// ✅ CSV via fetch() + Papa.parse(text) => pas de "Invalid URL"
// ✅ clamp +/setQty quand stock épuisé
// ✅ badges stock dans le panier offcanvas

(function () {
  const E = window.CART_ENDPOINTS;
  const C = window.CART_CONFIG || {};
  const STOCK = window.STOCK_ENDPOINTS || {}; // { reserved: "/stock/reserved" }

  if (!E) {
    console.error("[cart-ui] window.CART_ENDPOINTS manquant");
    return;
  }

  const CSV_URL = C.csvUrl ? String(C.csvUrl) : "";
  if (!CSV_URL) {
    console.warn("[cart-ui] CART_CONFIG.csvUrl manquant -> pas de stock/prix (fallback UID).");
  }

  // Elements (partials/_cart_offcanvas.html.twig)
  const badge = document.getElementById("cartBadge");
  const canvasEl = document.getElementById("cartCanvas");
  const emptyEl = document.getElementById("cartEmpty");
  const listEl = document.getElementById("cartSummary");
  const totalEl = document.getElementById("cartTotal");
  const btnClear = document.getElementById("btnCartClear");

  // -----------------------------
  // Helpers
  // -----------------------------
  function escHtml(s) {
    return String(s ?? "")
      .replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;").replaceAll("'", "&#039;");
  }

  function stripQuotes(s) {
    if (s === null || s === undefined) return "";
    s = String(s).trim();
    if (s.startsWith('"') && s.endsWith('"')) s = s.slice(1, -1);
    return s.trim();
  }

  function toNumber(v) {
    const n = Number(String(v ?? "").replace(/\s/g, "").replace(",", ".").replace(/[^0-9.\-]/g, ""));
    return Number.isFinite(n) ? n : NaN;
  }

  function fmtMoney(n) {
    const LOCALE = C.locale || "fr-FR";
    const CUR = C.currency || "MAD";
    if (!Number.isFinite(n)) return "—";
    try {
      return new Intl.NumberFormat(LOCALE, { style: "currency", currency: CUR, maximumFractionDigits: 2 }).format(n);
    } catch (e) {
      return n.toFixed(2) + " " + CUR;
    }
  }

  function imgUrl(uid) {
    if (!uid || !C.imagesBase) return "";
    return C.imagesBase + "/" + encodeURIComponent(uid) + ".png";
  }

  // normalise une clé CSV (BOM + espaces + guillemets)
  function normKey(k) {
    return stripQuotes(String(k ?? "").replace(/^\uFEFF/, "").trim());
  }

  // lecture robuste d’un champ (gère BOM/quotes)
  function fieldAny(row, ...names) {
    if (!row) return "";
    // direct
    for (const n of names) {
      if (row[n] !== undefined && row[n] !== null && String(row[n]).trim() !== "") {
        return stripQuotes(row[n]);
      }
    }
    // fallback sur clés normalisées
    const keys = Object.keys(row);
    for (const k of keys) {
      const nk = normKey(k);
      if (names.includes(nk)) {
        const v = row[k];
        if (v !== undefined && v !== null && String(v).trim() !== "") return stripQuotes(v);
      }
    }
    return "";
  }

  function uidFromRow(r) {
    return fieldAny(r, "UniqueId", "Id", "ID", "id");
  }

  function refFromRow(r, uid) {
    return fieldAny(r, "Id", "ID", "UniqueId") || uid || "";
  }

  function isCataloguePage() {
    return !!window.CATALOG_CONFIG;
  }

  function getRowPrice(row) {
    // respecte TTC/HT du catalogue si présent
    const pm = window.CATALOG_CONFIG?.priceMode || "inc";
    const key = (pm === "exc") ? "SalePriceVatExcluded" : "SalePriceVatIncluded";
    const n = toNumber(fieldAny(row, key));
    return Number.isFinite(n) ? n : NaN;
  }

  function getRowStock(row) {
    const n = toNumber(fieldAny(row, "RealStock"));
    return Number.isFinite(n) ? Math.max(0, Math.floor(n)) : null; // null => inconnu
  }

  // -----------------------------
  // State
  // -----------------------------
  let cartState = {};
  let lastSync = 0;

  // Cache produits CSV
  const productByUid = new Map();
  let ensureInFlight = null;

  // CSV text cache
  let csvTextCache = null;
  let csvTextCacheTs = 0;
  let csvFetchInFlight = null;
  const CSV_CACHE_TTL_MS = 20000;

  // Réservations actives
  let reservedMap = {}; // uid => qty
  let reservedLoading = false;

  // -----------------------------
  // Badge
  // -----------------------------
  function cartQtyTotal() {
    let n = 0;
    for (const k in cartState) {
      const q = Math.floor(toNumber(cartState[k]) || 0);
      if (q > 0) n += q;
    }
    return n;
  }

  function updateBadge() {
    if (!badge) return;
    const n = cartQtyTotal();
    badge.textContent = String(n);
    badge.classList.toggle("bg-danger", n > 0);
    badge.classList.toggle("bg-secondary", n === 0);
  }

  // -----------------------------
  // API
  // -----------------------------
  async function syncState(force = false) {
    const now = Date.now();
    if (!force && (now - lastSync) < 500) return;
    lastSync = now;

    const res = await fetch(E.state, { credentials: "same-origin" });
    const data = await res.json().catch(() => null);
    if (!res.ok || !data?.ok) return;

    cartState = data.cart || {};
    updateBadge();
  }

  async function apiPost(url, payloadObj) {
    const res = await fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        "X-Requested-With": "XMLHttpRequest",
        "Accept": "application/json"
      },
      body: new URLSearchParams(payloadObj)
    });

    const data = await res.json().catch(() => null);
    if (!res.ok || !data?.ok) throw new Error(data?.message || "api failed");

    cartState = data.cart || {};
    updateBadge();
    return data;
  }

  // -----------------------------
  // Product resolver (title/subtitle/price)
  // -----------------------------
  function resolveProduct(uid) {
    if (!uid) return { title: "—", subtitle: "", price: NaN, ref: uid };

    // 1) si catalogue.js fournit window.CART_RESOLVER (rapide)
    if (typeof window.CART_RESOLVER === "function") {
      const p = window.CART_RESOLVER(uid);
      if (p && p.title) {
        return { title: p.title, subtitle: p.subtitle || ("Réf: " + uid), price: p.price, ref: uid };
      }
    }

    // 2) via CSV
    const row = productByUid.get(uid) || null;
    if (row) {
      const title = fieldAny(row, "DesComClear", "Designation", "Libelle") || uid;
      const brand = fieldAny(row, "xx_Marque", "Marque");
      const veh = fieldAny(row, "xx_Vehicule");
      const year = fieldAny(row, "xx_Annee");
      const ref = refFromRow(row, uid);

      const subtitleParts = [];
      if (brand) subtitleParts.push(brand);
      const vehYear = [veh, year].filter(Boolean).join(" • ");
      if (vehYear) subtitleParts.push(vehYear);
      subtitleParts.push("Réf: " + ref);

      const price = isCataloguePage() ? getRowPrice(row) : NaN;

      return { title, subtitle: subtitleParts.join(" • "), price, ref };
    }

    // 3) fallback
    return { title: uid, subtitle: "ID: " + uid, price: NaN, ref: uid };
  }

  // -----------------------------
  // CSV loader (fetch text) + ensure products
  // -----------------------------
  async function fetchCsvText(force = false) {
    if (!CSV_URL || !window.Papa) return null;

    const now = Date.now();
    if (!force && csvTextCache && (now - csvTextCacheTs) < CSV_CACHE_TTL_MS) {
      return csvTextCache;
    }

    if (csvFetchInFlight) return csvFetchInFlight;

    csvFetchInFlight = (async () => {
      try {
        const res = await fetch(CSV_URL, {
          credentials: "same-origin",
          cache: "no-store",
          headers: { "Accept": "text/csv,*/*" }
        });
        if (!res.ok) return null;
        const text = await res.text();
        csvTextCache = text;
        csvTextCacheTs = Date.now();
        return text;
      } catch (e) {
        return null;
      } finally {
        csvFetchInFlight = null;
      }
    })();

    return csvFetchInFlight;
  }

  async function ensureProductsForCart() {
    if (!CSV_URL || !window.Papa) return;

    const ids = Object.keys(cartState).filter(k => (toNumber(cartState[k]) || 0) > 0);
    if (!ids.length) return;

    const missing = ids.filter(id => !productByUid.has(id));
    if (!missing.length) return;

    if (ensureInFlight) return ensureInFlight;

    ensureInFlight = (async () => {
      const text = await fetchCsvText(false);
      if (!text) return;

      const wanted = new Set(missing);

      function parseWithDelimiter(delim) {
        return new Promise((resolve) => {
          try {
            window.Papa.parse(text, {
              header: true,
              delimiter: delim,
              skipEmptyLines: true,
              step: (results, parser) => {
                const row = results.data || {};
                const uid = uidFromRow(row);
                if (uid && wanted.has(uid)) {
                  productByUid.set(uid, row);
                  wanted.delete(uid);
                  if (wanted.size === 0) parser.abort();
                }
              },
              complete: () => resolve(),
              error: () => resolve()
            });
          } catch (e) {
            resolve();
          }
        });
      }

      // 1) ;
      await parseWithDelimiter(";");

      // 2) fallback , si encore manquants
      if (wanted.size > 0) {
        await parseWithDelimiter(",");
      }
    })().finally(() => {
      ensureInFlight = null;
    });

    return ensureInFlight;
  }

  // -----------------------------
  // Stock (reserved endpoint)
  // -----------------------------
  function getReserved(uid) {
    const n = toNumber(reservedMap?.[uid]);
    return Number.isFinite(n) ? Math.max(0, Math.floor(n)) : 0;
  }

  function getAvailable(uid) {
    const row = productByUid.get(uid) || null;
    const stock = row ? getRowStock(row) : null; // null => inconnu
    const reserved = getReserved(uid);
    const inCart = Math.max(0, Math.floor(toNumber(cartState?.[uid]) || 0));

    // remaining = stock - reserved - inCart
    const remaining = (stock === null) ? Infinity : Math.max(0, stock - reserved - inCart);
    return { stock, reserved, inCart, remaining };
  }

  async function fetchReserved(uids = []) {
    const urlBase = STOCK?.reserved;
    if (!urlBase) return;

    if (reservedLoading) return;
    reservedLoading = true;

    try {
      const qs = (uids && uids.length)
        ? ("?uids=" + uids.map(encodeURIComponent).join(","))
        : "";

      const res = await fetch(urlBase + qs, {
        credentials: "same-origin",
        cache: "no-store",
        headers: { "Accept": "application/json" }
      });

      const data = await res.json().catch(() => null);
      if (res.ok && data?.ok) reservedMap = data.reserved || {};
    } catch (e) {
      // silencieux
    } finally {
      reservedLoading = false;
    }
  }

  async function refreshStockForCart() {
    await ensureProductsForCart();
    const uids = Object.keys(cartState).filter(k => (toNumber(cartState[k]) || 0) > 0);
    await fetchReserved(uids);
  }

  // clamp qty par rapport au max autorisé (si stock connu)
  function clampQtyToAvailable(uid, wantedQty) {
    let q = Math.max(0, Math.floor(toNumber(wantedQty) || 0));
    const { stock, inCart, remaining } = getAvailable(uid);

    if (stock === null) return q; // stock inconnu => pas de clamp

    const maxTotal = Math.max(0, inCart + remaining);
    if (q > maxTotal) q = maxTotal;
    return q;
  }

  // -----------------------------
  // Render
  // -----------------------------
  function shouldRender() {
    return !!(listEl && emptyEl);
  }

  function computeTotal() {
    const ids = Object.keys(cartState).filter(k => (toNumber(cartState[k]) || 0) > 0);
    let totalVal = 0;
    let hasAnyPrice = false;

    for (const uid of ids) {
      const qty = Math.floor(toNumber(cartState[uid]) || 0);
      const p = resolveProduct(uid);
      if (isCataloguePage() && Number.isFinite(p.price)) {
        hasAnyPrice = true;
        totalVal += p.price * qty;
      }
    }
    return { hasAnyPrice, totalVal };
  }

  function renderOffcanvas() {
    if (!shouldRender()) return;

    listEl.innerHTML = "";
    if (totalEl) totalEl.textContent = "—";

    const ids = Object.keys(cartState).filter(k => (toNumber(cartState[k]) || 0) > 0);
    const has = ids.length > 0;

    emptyEl.classList.toggle("d-none", has);
    if (!has) return;

    let invalidCount = 0;

    for (const uid of ids.sort((a, b) => a.localeCompare(b, "fr"))) {
      const qty = Math.floor(toNumber(cartState[uid]) || 0);
      const p = resolveProduct(uid);

      const { stock, reserved, inCart, remaining } = getAvailable(uid);
      const stockKnown = (stock !== null);
      const maxTotal = stockKnown ? Math.max(0, inCart + remaining) : Infinity;

      const isOver = stockKnown && qty > maxTotal;
      if (isOver) invalidCount++;

      const canInc = !stockKnown ? true : (qty < maxTotal);

      const stockLine = stockKnown
        ? (isOver
            ? `<div class="mt-1"><span class="badge text-bg-danger">Stock insuffisant • max: ${maxTotal} • réservé: ${reserved}</span></div>`
            : `<div class="mt-1"><span class="badge text-bg-success">Max: ${maxTotal}</span> <span class="badge text-bg-secondary">Réservé: ${reserved}</span></div>`
          )
        : `<div class="mt-1"><span class="badge text-bg-secondary">Stock non renseigné</span></div>`;

      const div = document.createElement("div");
      div.className = "cart-line";
      div.innerHTML = `
        <div class="cart-thumb">
          <img src="${escHtml(imgUrl(uid))}" alt=""
               loading="lazy"
               onerror="this.onerror=null;this.style.display='none';">
        </div>

        <div style="min-width:0; flex:1;">
          <div class="fw-semibold text-truncate">${escHtml(p.title)}</div>
          <div class="small text-muted text-truncate">${escHtml(p.subtitle)}</div>
          ${isCataloguePage()
            ? `<div class="small text-muted mt-1">PU: <span class="fw-semibold">${Number.isFinite(p.price) ? escHtml(fmtMoney(p.price)) : "—"}</span></div>`
            : ``}
          ${stockLine}
        </div>

        <div class="d-flex flex align-items-end gap-2">
          <div class="d-flex align-items-center gap-1">
            <button class="btn btn-outline-secondary qty-step" data-dec="${escHtml(uid)}" type="button">
              <i class="bi bi-dash"></i>
            </button>

            <div class="fw-semibold" style="min-width:34px;text-align:center;">${qty}</div>

            <button class="btn btn-outline-secondary qty-step" data-inc="${escHtml(uid)}" type="button" ${canInc ? "" : "disabled"}>
              <i class="bi bi-plus"></i>
            </button>
          </div>

          <button class="btn btn-sm btn-outline-danger" data-rm="${escHtml(uid)}" type="button">
            <i class="bi bi-trash"></i>
          </button>
        </div>
      `;
      listEl.appendChild(div);
    }

    const t = computeTotal();
    if (totalEl) totalEl.textContent = (isCataloguePage() && t.hasAnyPrice) ? fmtMoney(t.totalVal) : "—";

    if (invalidCount > 0) {
      const warn = document.createElement("div");
      warn.className = "alert alert-danger border-0 rounded-4 mb-2";
      warn.innerHTML = `<i class="bi bi-exclamation-triangle me-2"></i>${invalidCount} article(s) dépassent le stock disponible. Ajuste les quantités.`;
      listEl.prepend(warn);
    }
  }

  // -----------------------------
  // Actions (add/set/remove/clear) with enforcement
  // -----------------------------
  async function add(uid, qty) {
    await refreshStockForCart();

    const prev = Math.floor(toNumber(cartState[uid]) || 0);
    const wanted = prev + Math.max(1, Math.floor(toNumber(qty) || 1));

    const safe = clampQtyToAvailable(uid, wanted);

    if (safe <= prev) {
      window.showToast?.("Stock insuffisant (impossible d'ajouter).", "warning");
      renderOffcanvas();
      return;
    }

    await apiPost(E.set, { id: uid, qty: String(safe) });
    await refreshStockForCart();
    renderOffcanvas();
  }

  async function setQty(uid, qty) {
    await refreshStockForCart();
    const safe = clampQtyToAvailable(uid, qty);
    await apiPost(E.set, { id: uid, qty: String(safe) });
    await refreshStockForCart();
    renderOffcanvas();
  }

  async function remove(uid) {
    await apiPost(E.remove, { id: uid });
    await refreshStockForCart();
    renderOffcanvas();
  }

  async function clear() {
    const res = await fetch(E.clear, {
      method: "POST",
      credentials: "same-origin",
      headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" }
    });
    const data = await res.json().catch(() => null);
    if (!res.ok || !data?.ok) throw new Error(data?.message || "clear failed");

    cartState = {};
    updateBadge();
    renderOffcanvas();
  }

  // -----------------------------
  // Events on offcanvas
  // -----------------------------
  if (listEl) {
    listEl.addEventListener("click", async (e) => {
      const inc = e.target.closest("[data-inc]")?.getAttribute("data-inc");
      const dec = e.target.closest("[data-dec]")?.getAttribute("data-dec");
      const rm  = e.target.closest("[data-rm]")?.getAttribute("data-rm");

      try {
        if (inc) {
          await refreshStockForCart();
          const prev = Math.floor(toNumber(cartState[inc]) || 0);
          const safe = clampQtyToAvailable(inc, prev + 1);

          if (safe <= prev) {
            window.showToast?.("Stock insuffisant.", "warning");
            renderOffcanvas();
            return;
          }

          // optimistic
          cartState[inc] = safe;
          updateBadge();
          renderOffcanvas();

          await apiPost(E.set, { id: inc, qty: String(safe) });
          await refreshStockForCart();
          renderOffcanvas();
        }

        if (dec) {
          const prev = Math.floor(toNumber(cartState[dec]) || 0);
          const next = Math.max(1, prev - 1);

          cartState[dec] = next;
          updateBadge();
          renderOffcanvas();

          await apiPost(E.set, { id: dec, qty: String(next) });
          await refreshStockForCart();
          renderOffcanvas();
        }

        if (rm) {
          delete cartState[rm];
          updateBadge();
          renderOffcanvas();

          await apiPost(E.remove, { id: rm });
          await refreshStockForCart();
          renderOffcanvas();
        }
      } catch (err) {
        console.error(err);
        try { await syncState(true); } catch (_) {}
        try { await refreshStockForCart(); } catch (_) {}
        renderOffcanvas();
        window.showToast?.("Erreur panier.", "danger");
      }
    });
  }

  if (btnClear) {
    btnClear.addEventListener("click", async () => {
      try {
        await clear();
      } catch (err) {
        console.error(err);
        window.showToast?.("Erreur panier.", "danger");
      }
    });
  }

  // -----------------------------
  // Init
  // -----------------------------
  document.addEventListener("DOMContentLoaded", () => {
    syncState(true).catch(() => {});
  });

  // When opening offcanvas
  document.addEventListener("show.bs.offcanvas", async (e) => {
    if (!e.target || e.target.id !== "cartCanvas") return;
    try {
      await syncState(true);
      await refreshStockForCart();
      renderOffcanvas();
    } catch (err) {
      console.error(err);
      renderOffcanvas();
    }
  });

  // refresh reservations every 20s (only if open)
  setInterval(async () => {
    try {
      const isOpen = canvasEl?.classList?.contains("show");
      if (!isOpen) return;
      await refreshStockForCart();
      renderOffcanvas();
    } catch (_) {}
  }, 20000);

  // Expose API
  window.CartUI = {
    sync: () => syncState(true),
    add,
    setQty,
    remove,
    clear,
    getState: () => cartState
  };
})();
