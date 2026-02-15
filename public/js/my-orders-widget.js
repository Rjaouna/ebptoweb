// public/js/my-orders-widget.js
(function () {
  const root = document.getElementById("myOrdersWidget");
  if (!root) return;

  const endpoint = root.getAttribute("data-endpoint");     // /ajax/mes-commandes
  const allUrl = root.getAttribute("data-all-url") || "";  // /mes-commandes
  const showUrlPattern = root.getAttribute("data-show-url") || ""; // /mes-commandes/0

  const listEl = document.getElementById("myOrdersList");
  const emptyEl = document.getElementById("myOrdersEmpty");
  const loadingEl = document.getElementById("myOrdersLoading");
  const btn = root.querySelector('[data-action="refresh"]');

  // ------------------------------------------------------------
  // Helpers
  // ------------------------------------------------------------
  function esc(s) {
    return String(s ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function toNumber(v) {
    const s = String(v ?? "")
      .replace(/\s/g, "")
      .replace(",", ".")
      .replace(/[^0-9.\-]/g, "");
    const n = Number(s);
    return Number.isFinite(n) ? n : NaN;
  }

  function fmtMoney(n) {
    const val = toNumber(n);
    if (!Number.isFinite(val)) return "—";
    try {
      const LOCALE = window.CATALOG_CONFIG?.locale || "fr-FR";
      const CUR = window.CATALOG_CONFIG?.currency || "MAD";
      return new Intl.NumberFormat(LOCALE, {
        style: "currency",
        currency: CUR,
        maximumFractionDigits: 2
      }).format(val);
    } catch (e) {
      return String(val);
    }
  }

  function buildShowUrl(orderId) {
    if (!orderId) return "";
    // Pattern expected like "/mes-commandes/0"
    if (showUrlPattern && showUrlPattern.includes("/0")) {
      return showUrlPattern.replace("/0", "/" + encodeURIComponent(String(orderId)));
    }
    // fallback
    if (allUrl) return allUrl;
    return "";
  }

  function statusChip(st) {
    if (!st) return "";
    // petit mapping de style (tu peux adapter)
    const s = String(st).toLowerCase();
    let klass = "order-chip";
    if (s.includes("paid") || s.includes("paye") || s.includes("valid")) klass += " chip-ok";
    else if (s.includes("cancel") || s.includes("annul")) klass += " chip-bad";
    else if (s.includes("reserved") || s.includes("reserve")) klass += " chip-warn";
    return `<span class="${klass}">${esc(st)}</span>`;
  }

  function setState(state) {
    // state: "loading" | "empty" | "ready" | "error"
    if (state === "loading") {
      loadingEl?.classList.remove("d-none");
      emptyEl?.classList.add("d-none");
      if (listEl) listEl.innerHTML = "";
      btn && (btn.disabled = true);
      return;
    }
    loadingEl?.classList.add("d-none");
    btn && (btn.disabled = false);

    if (state === "empty" || state === "error") {
      emptyEl?.classList.remove("d-none");
      if (listEl) listEl.innerHTML = "";
      return;
    }
    // ready
    emptyEl?.classList.add("d-none");
  }

  // ------------------------------------------------------------
  // Render
  // ------------------------------------------------------------
  function renderOrders(orders) {
    if (!listEl) return;

    listEl.innerHTML = "";

    for (const o of orders) {
      const id = o?.id;
      const ref = o?.reference || (id ? "CMD-" + id : "CMD");
      const date = o?.createdAt || "";
      const total = fmtMoney(o?.totalTtc);

      const itemsCount = (o?.itemsCount !== null && o?.itemsCount !== undefined)
        ? `<span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">${esc(o.itemsCount)} art.</span>`
        : "";

      const showUrl = buildShowUrl(id);

      const div = document.createElement("div");
      div.className = "order-mini";

      div.innerHTML = `
        <div class="d-flex align-items-start justify-content-between gap-3">
          <div class="min-w-0">
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <div class="fw-black text-truncate"><a href="${esc(showUrl)}">${esc(ref)}</a></div>
              ${statusChip(o?.status)}
              ${itemsCount}
            </div>

            <div class="text-muted small mt-1 d-flex align-items-center gap-2 flex-wrap">
              ${date ? `<span><i class="bi bi-clock me-1"></i>${esc(date)}</span>` : ""}
            
            </div>
          </div>

          <div class="text-end">
            <div class="fw-black text-nowrap">${esc(total)}</div>
            <div class="text-muted small">TTC</div>
          </div>
        </div>
      `;

      listEl.appendChild(div);
    }
  }

  // ------------------------------------------------------------
  // Load (AJAX)
  // ------------------------------------------------------------
  let inFlight = null;

  async function load() {
    if (!endpoint || !listEl) return;

    // anti double click
    if (inFlight) return inFlight;

    setState("loading");

    inFlight = (async () => {
      try {
        // endpoint peut être relatif -> on construit proprement
        const url = new URL(endpoint, window.location.origin);
        url.searchParams.set("page", "1");
        url.searchParams.set("limit", "2");

        const res = await fetch(url.toString(), {
          credentials: "same-origin",
          cache: "no-store",
          headers: {
            "Accept": "application/json",
            "X-Requested-With": "XMLHttpRequest"
          }
        });

        const data = await res.json().catch(() => null);

        if (!res.ok || !data?.ok) {
          setState("error");
          return;
        }

        const orders = Array.isArray(data.orders) ? data.orders : [];
        if (!orders.length) {
          setState("empty");
          return;
        }

        setState("ready");
        renderOrders(orders);
      } catch (e) {
        setState("error");
      } finally {
        inFlight = null;
      }
    })();

    return inFlight;
  }

  // ------------------------------------------------------------
  // Events / Expose
  // ------------------------------------------------------------
  btn?.addEventListener("click", () => load());

  // Expose refresh API
  window.MyOrdersWidget = {
    refresh: load
  };

  // Auto load
  document.addEventListener("DOMContentLoaded", () => load());

  // Refresh auto si tu déclenches un event global après confirm
  window.addEventListener("order:confirmed", () => load());

  // ------------------------------------------------------------
  // Option: si tu veux injecter un bouton "Voir toutes mes commandes"
  // (si tu ne veux pas le mettre dans Twig)
  // ------------------------------------------------------------
  // (Tu as déjà le bouton dans ton twig, donc tu peux ignorer ça.)
})();
