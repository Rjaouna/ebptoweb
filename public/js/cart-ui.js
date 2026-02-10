// public/js/cart-ui.js
(function () {
  const E = window.CART_ENDPOINTS;
  const C = window.CART_CONFIG;

  if (!E) {
    console.error("[cart-ui] window.CART_ENDPOINTS manquant");
    return;
  }
  if (!C?.csvUrl) {
    console.warn("[cart-ui] window.CART_CONFIG.csvUrl manquant -> fallback UID");
  }

  // Elements (doivent exister dans partials/_cart_offcanvas.html.twig)
  const badge = document.getElementById("cartBadge");
  const canvasEl = document.getElementById("cartCanvas");
  const emptyEl = document.getElementById("cartEmpty");
  const listEl = document.getElementById("cartSummary");
  const totalEl = document.getElementById("cartTotal");
  const btnClear = document.getElementById("btnCartClear");

  // Helpers
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
    try {
      return new Intl.NumberFormat(C?.locale || "fr-FR", {
        style: "currency",
        currency: C?.currency || "MAD",
        maximumFractionDigits: 2
      }).format(n);
    } catch (e) {
      return (Number.isFinite(n) ? n.toFixed(2) : "—") + " " + (C?.currency || "MAD");
    }
  }
  function imgUrl(uid) {
    if (!uid || !C?.imagesBase) return "";
    return C.imagesBase + "/" + encodeURIComponent(uid) + ".png";
  }
  function uidFromRow(r) {
    return String(r?.UniqueId || r?.Id || r?.ID || r?.id || "").trim();
  }
  function refFromRow(r, uid) {
    return String(r?.Id || r?.ID || r?.UniqueId || uid || "").trim();
  }

  // ✅ Prix/total UNIQUEMENT sur Catalogue (catalogue pages définissent window.CATALOG_CONFIG)
  function isCataloguePage() {
    return !!window.CATALOG_CONFIG;
  }
  function getRowPrice(row) {
    // si tu veux respecter TTC/HT du catalogue quand on est sur catalogue
    const pm = window.CATALOG_CONFIG?.priceMode || "inc";
    const key = (pm === "exc") ? "SalePriceVatExcluded" : "SalePriceVatIncluded";
    const n = toNumber(row?.[key]);
    return Number.isFinite(n) ? n : NaN;
  }

  // State
  let cartState = {};
  let lastSync = 0;

  // Cache produits résolus via CSV
  const productByUid = new Map();
  let csvScanInFlight = null; // évite double scan simultané

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

async function add(uid, qty) {
  await apiPost(E.add, { id: uid, qty: String(qty) });
  await ensureProductsForCart();
  if (shouldRender()) renderOffcanvas();
}

async function setQty(uid, qty) {
  await apiPost(E.set, { id: uid, qty: String(qty) });
  await ensureProductsForCart();
  if (shouldRender()) renderOffcanvas();
}

async function remove(uid) {
  await apiPost(E.remove, { id: uid });
  if (shouldRender()) renderOffcanvas();
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
  if (shouldRender()) renderOffcanvas();
}


function shouldRender() {
  // si le panier offcanvas existe (et les zones sont là), on peut rerender
  return !!(listEl && emptyEl);
}


  // ✅ Résolution produit :
  // 1) si catalogue.js fournit window.CART_RESOLVER -> on l’utilise (super rapide)
  // 2) sinon on utilise productByUid (rempli depuis CSV)
  // 3) fallback UID
  function resolveProduct(uid) {
    if (!uid) return { title: "—", subtitle: "", price: NaN, ref: "" };

    if (typeof window.CART_RESOLVER === "function") {
      const p = window.CART_RESOLVER(uid);
      if (p && p.title) {
        return {
          title: p.title,
          subtitle: p.subtitle || ("Réf: " + uid),
          price: p.price,
          ref: uid
        };
      }
    }

    const row = productByUid.get(uid) || null;
    if (row) {
      const title = row.DesComClear || row.Designation || row.Libelle || uid;
      const brand = row.xx_Marque || row.Marque || "";
      const veh = row.xx_Vehicule || "";
      const year = row.xx_Annee || "";
      const ref = refFromRow(row, uid);

      const subtitleParts = [];
      if (brand) subtitleParts.push(brand);
      const vehYear = [veh, year].filter(Boolean).join(" • ");
      if (vehYear) subtitleParts.push(vehYear);
      subtitleParts.push("Réf: " + ref);

      const price = isCataloguePage() ? getRowPrice(row) : NaN;

      return {
        title,
        subtitle: subtitleParts.join(" • "),
        price,
        ref
      };
    }

    return { title: uid, subtitle: "ID: " + uid, price: NaN, ref: uid };
  }

  // ✅ Charge les infos produits via CSV pour les UID du panier
  async function ensureProductsForCart() {
    if (!C?.csvUrl || !window.Papa) return;

    const ids = Object.keys(cartState).filter(k => (toNumber(cartState[k]) || 0) > 0);
    if (ids.length === 0) return;

    const missing = ids.filter(id => !productByUid.has(id));
    if (missing.length === 0) return;

    // évite plusieurs scans simultanés
    if (csvScanInFlight) return csvScanInFlight;

    const wanted = new Set(missing);

    csvScanInFlight = new Promise((resolve) => {
      window.Papa.parse(C.csvUrl, {
        download: true,
        header: true,
        delimiter: ";",
        skipEmptyLines: true,
        worker: true,
        transformHeader: (h) => stripQuotes(h),
        transform: (v) => stripQuotes(v),

        step: (results, parser) => {
          const r = results.data || {};
          const uid = uidFromRow(r);
          if (uid && wanted.has(uid)) {
            productByUid.set(uid, r);
            wanted.delete(uid);
            if (wanted.size === 0) parser.abort(); // ✅ stop dès qu’on a tout trouvé
          }
        },

        complete: () => {
          csvScanInFlight = null;
          resolve();
        },

        error: () => {
          csvScanInFlight = null;
          resolve();
        }
      });
    });

    return csvScanInFlight;
  }

  function renderOffcanvas() {
    if (!listEl || !emptyEl) return;

    listEl.innerHTML = "";
    if (totalEl) totalEl.textContent = "—";

    const ids = Object.keys(cartState).filter(k => (toNumber(cartState[k]) || 0) > 0);
    const has = ids.length > 0;

    emptyEl.classList.toggle("d-none", has);
    if (!has) return;

    let totalVal = 0;
    let hasAnyPrice = false;

    for (const uid of ids.sort((a, b) => a.localeCompare(b, "fr"))) {
      const qty = Math.floor(toNumber(cartState[uid]) || 0);
      const p = resolveProduct(uid);

      // total UNIQUEMENT sur catalogue
      if (isCataloguePage() && Number.isFinite(p.price)) {
        hasAnyPrice = true;
        totalVal += p.price * qty;
      }

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
          ${
            isCataloguePage()
              ? `<div class="small text-muted mt-1">PU: <span class="fw-semibold">${Number.isFinite(p.price) ? escHtml(fmtMoney(p.price)) : "—"}</span></div>`
              : ``
          }
        </div>

        <div class="d-flex flex align-items-end gap-2">
          <div class="d-flex align-items-center gap-1">
            <button class="btn btn-outline-secondary qty-step" data-dec="${escHtml(uid)}" type="button"><i class="bi bi-dash"></i></button>
            <div class="fw-semibold" style="min-width:34px;text-align:center;">${qty}</div>
            <button class="btn btn-outline-secondary qty-step" data-inc="${escHtml(uid)}" type="button"><i class="bi bi-plus"></i></button>
          </div>
          <button class="btn btn-sm btn-outline-danger" data-rm="${escHtml(uid)}" type="button"><i class="bi bi-trash"></i></button>
        </div>
      `;
      listEl.appendChild(div);
    }

    if (totalEl) {
      totalEl.textContent = (isCataloguePage() && hasAnyPrice) ? fmtMoney(totalVal) : "—";
    }
  }

  // Events: + / - / remove
 if (listEl) {
  listEl.addEventListener("click", async (e) => {
    const inc = e.target.closest("[data-inc]")?.getAttribute("data-inc");
    const dec = e.target.closest("[data-dec]")?.getAttribute("data-dec");
    const rm  = e.target.closest("[data-rm]")?.getAttribute("data-rm");

    try {
      if (inc) {
        const prev = Math.floor(toNumber(cartState[inc]) || 0);
        const next = prev + 1;

        // ✅ update immédiat UI
        cartState[inc] = next;
        updateBadge();
        if (shouldRender()) renderOffcanvas();

        // ✅ confirmation serveur
        await setQty(inc, next);
      }

      if (dec) {
        const prev = Math.floor(toNumber(cartState[dec]) || 0);
        const next = Math.max(1, prev - 1);

        cartState[dec] = next;
        updateBadge();
        if (shouldRender()) renderOffcanvas();

        await setQty(dec, next);
      }

      if (rm) {
        // sauvegarde pour rollback si erreur
        const prev = cartState[rm];

        delete cartState[rm];
        updateBadge();
        if (shouldRender()) renderOffcanvas();

        await remove(rm);

        // si remove() remet déjà cartState via apiPost, c’est ok
      }
    } catch (err) {
      console.error(err);
      // 🔁 en cas d’erreur, on resync et on rerender
      try { await syncState(true); } catch(_) {}
      if (shouldRender()) renderOffcanvas();
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

  // Sync badge everywhere
  document.addEventListener("DOMContentLoaded", () => {
    syncState(true).catch(() => { /* noop */ });
  });

  // When opening the offcanvas: sync -> resolve -> render
  document.addEventListener("show.bs.offcanvas", async (e) => {
    if (!e.target || e.target.id !== "cartCanvas") return;
    try {
      await syncState(true);
      await ensureProductsForCart();
      renderOffcanvas();
    } catch (err) {
      console.error(err);
      renderOffcanvas(); // au moins on affiche UID
    }
  });

  // Expose API for catalogue.js
  window.CartUI = {
    sync: () => syncState(true),
    add,
    setQty,
    remove,
    clear,
    getState: () => cartState
  };
})();
