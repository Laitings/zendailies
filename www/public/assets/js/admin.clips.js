// admin.clips.js  — Clips index page logic

import { BatchThumbnailLoader } from "./batch-thumbnails.js";

// Initialize on page load
document.addEventListener("DOMContentLoaded", () => {
  const loader = new BatchThumbnailLoader();
  loader.loadThumbnails();
});

(function () {
  const root = document.querySelector(".zd-clips-page");
  if (!root) return;

  // ---- Column visibility controls (popup) ----
  const columnsToggleBtn = document.getElementById("zd-columns-toggle");
  const columnsPopup = document.getElementById("zd-columns-popup");

  // -----------------------------
  // Column Visibility Persistence
  // -----------------------------

  const STORAGE_KEY = "zendailies.clipColumns";

  // Load from localStorage
  function loadColumnState() {
    try {
      return JSON.parse(localStorage.getItem(STORAGE_KEY)) || {};
    } catch (e) {
      return {};
    }
  }

  // Save to localStorage
  function saveColumnState(state) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
  }

  const columnState = loadColumnState();

  // Apply saved column state to page
  Object.entries(columnState).forEach(([col, isVisible]) => {
    const root = document.querySelector(".zd-clips-page");

    if (!isVisible) {
      root.classList.add(`hide-col-${col}`);
      // Uncheck the checkbox in popup
      const cb = document.querySelector(
        `.zd-columns-item input[data-col="${col}"]`,
      );
      if (cb) cb.checked = false;
    }
  });

  // Hook checkboxes to update state
  document
    .querySelectorAll('.zd-columns-item input[type="checkbox"]')
    .forEach((cb) => {
      cb.addEventListener("change", () => {
        const col = cb.dataset.col;

        // Update DOM
        const root = document.querySelector(".zd-clips-page");
        if (cb.checked) {
          root.classList.remove(`hide-col-${col}`);
        } else {
          root.classList.add(`hide-col-${col}`);
        }

        // Update and save state
        columnState[col] = cb.checked;
        saveColumnState(columnState);
      });
    });

  // All checkboxes inside popup
  const colToggles = columnsPopup
    ? columnsPopup.querySelectorAll("input[data-col]")
    : [];

  function applyColVisibility() {
    // For each checkbox, add/remove hide-col-XXX on root
    colToggles.forEach((cb) => {
      const col = cb.dataset.col;
      if (!col) return;
      const cls = "hide-col-" + col;
      if (cb.checked) {
        root.classList.remove(cls);
      } else {
        root.classList.add(cls);
      }
    });
  }

  function openColumnsPopup() {
    if (!columnsPopup) return;
    columnsPopup.classList.add("is-open");
  }

  function closeColumnsPopup() {
    if (!columnsPopup) return;
    columnsPopup.classList.remove("is-open");
  }

  if (columnsToggleBtn && columnsPopup) {
    // Toggle on button click
    columnsToggleBtn.addEventListener("click", (ev) => {
      ev.stopPropagation();
      if (columnsPopup.classList.contains("is-open")) {
        closeColumnsPopup();
      } else {
        openColumnsPopup();
      }
    });

    // Click inside popup should not close it
    columnsPopup.addEventListener("click", (ev) => {
      ev.stopPropagation();
    });

    // Click anywhere else closes it
    document.addEventListener("click", () => {
      closeColumnsPopup();
    });

    // React to checkbox changes
    colToggles.forEach((cb) => {
      cb.addEventListener("change", applyColVisibility);
    });

    // Initial state on load
    applyColVisibility();
  }

  // ---- Page-level inputs (from data-*)
  const projectUuid = root.dataset.project || "";
  const dayUuid = root.dataset.day || "";
  const converterCsrf = root.dataset.converterCsrf || "";
  const quickCsrf = root.dataset.quickCsrf || "";
  const canEdit = root.dataset.canEdit === "1";

  // ---- DOM refs used across functions
  const tbodyEl = document.querySelector(".zd-table tbody");
  const bulkPosterBtn = document.getElementById("zd-bulk-poster");
  const importForm = document.getElementById("zd-import-form");
  const importBtn = document.getElementById("zd-import-btn");
  const importUids = document.getElementById("zd-import-uuids");
  const selCountEl = document.getElementById("zd-selected-count");

  // ======================================================
  // Media Generation Core Function
  // ======================================================
  async function generatePosterAjax(clipUuid, dUuid, options = {}) {
    const targetDay = dUuid || dayUuid;
    console.log(
      `[DEBUG] Starting poster for: ${clipUuid} on day: ${targetDay}`,
    );

    const fd = new FormData();
    fd.append("csrf_token", converterCsrf);
    fd.append("clip_uuid", clipUuid);

    try {
      const url = `/admin/projects/${projectUuid}/days/${targetDay}/converter/poster`;
      console.log(`[DEBUG] Fetching URL: ${url}`);

      const resp = await fetch(url, { method: "POST", body: fd });
      console.log(`[DEBUG] Response Status: ${resp.status}`);

      const data = await resp.json();
      console.log("[DEBUG] Server Response Data:", data);

      if (resp.ok && data.ok) {
        if (!options.silent) {
          const selector = `#clip-${clipUuid} .zd-thumb`;
          const img = document.querySelector(selector);

          if (img) {
            // Use the path from the server. If the server didn't send one,
            // use the current src but strip the old timestamp first.
            const currentBaseSrc = img.src.split("?")[0];
            const newSrc = (data.href || currentBaseSrc) + "?v=" + Date.now();

            img.src = newSrc;
            console.log(`[DEBUG] Poster overwritten and refreshed: ${newSrc}`);
          }
        }
        return true;
      } else {
        console.error(
          "[DEBUG] Server returned error state:",
          data.error || "Unknown error",
        );
      }
    } catch (err) {
      console.error("[DEBUG] Network or Parsing Error:", err);
    }
    return false;
  }

  // ---- Day Filter (Dropdown)
  const daySelect = document.getElementById("zd-day-select");
  if (daySelect) {
    daySelect.addEventListener("change", function () {
      const url = this.value;
      if (url) {
        window.location.href = url;
      }
    });
  }

  // initial state
  applyColVisibility();

  // Live filter: AJAX-refresh table while keeping focus & caret
  const filterForm = root.querySelector("form.zd-filter-form");
  const filterControls = root.querySelectorAll(
    ".zd-filters input.zd-input, .zd-filters select.zd-select",
  );

  let filterDebounce = null;

  function handleFilterChange() {
    if (!filterForm || !tbodyEl) return;
    if (filterDebounce) clearTimeout(filterDebounce);

    filterDebounce = setTimeout(() => {
      // Build URL with current filter form values
      const url = new URL(window.location.href);
      const formData = new FormData(filterForm);

      // Replace all existing query params with current form values
      url.search = ""; // start clean
      for (const [key, value] of formData.entries()) {
        if (value !== "") {
          url.searchParams.append(key, value);
        }
      }

      // Optional: keep other non-filter params if you use them (page, sort, etc.)
      // e.g.:
      // const current = new URL(window.location.href);
      // const keep = ["sort", "direction"];
      // keep.forEach((k) => {
      //   if (current.searchParams.has(k) && !url.searchParams.has(k)) {
      //     url.searchParams.set(k, current.searchParams.get(k));
      //   }
      // });

      fetch(url.toString(), {
        method: "GET",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
        },
      })
        .then((res) => res.text())
        .then((html) => {
          // Parse response and grab the new tbody
          const parser = new DOMParser();
          const doc = parser.parseFromString(html, "text/html");
          const newTbody = doc.querySelector(".zd-table tbody");
          if (!newTbody) return;

          // Swap only the rows; filters & inputs stay untouched → caret preserved
          tbodyEl.innerHTML = newTbody.innerHTML;
        })
        .catch((err) => {
          console.error("Live filter update failed", err);
        });
    }, 400); // delay between keystrokes
  }

  filterControls.forEach((el) => {
    el.addEventListener("input", handleFilterChange);
    el.addEventListener("change", handleFilterChange);
  });

  // ---- Timecode helpers
  function formatDurationMsToTimecode(ms, fpsFallback, fpsFromServer) {
    if (!ms || ms <= 0) return "";
    const fps = fpsFromServer || fpsFallback || 24;
    const totalSecondsFloat = ms / 1000;
    const totalSecondsInt = Math.floor(totalSecondsFloat);
    const minutes = Math.floor(totalSecondsInt / 60);
    const seconds = totalSecondsInt % 60;
    const fractional = totalSecondsFloat - totalSecondsInt;
    const frame = Math.floor(fractional * fps);
    const mm = String(minutes).padStart(2, "0");
    const ss = String(seconds).padStart(2, "0");
    const ff = String(frame).padStart(2, "0");
    return `${mm}:${ss}:${ff}`;
  }

  function formatTotalMsToHHMMSSFF(totalMs, fps = 24) {
    if (!totalMs || totalMs <= 0) return "00:00:00:00";
    const totalSecondsFloat = totalMs / 1000;
    const totalSecondsInt = Math.floor(totalSecondsFloat);
    const hours = Math.floor(totalSecondsInt / 3600);
    const minutes = Math.floor((totalSecondsInt % 3600) / 60);
    const seconds = totalSecondsInt % 60;
    const fractional = totalSecondsFloat - totalSecondsInt;
    const frame = Math.floor(fractional * fps);
    const hh = String(hours).padStart(2, "0");
    const mm = String(minutes).padStart(2, "0");
    const ss = String(seconds).padStart(2, "0");
    const ff = String(frame).padStart(2, "0");
    return `${hh}:${mm}:${ss}:${ff}`;
  }

  function initDurationsOnPage() {
    const durCells = document.querySelectorAll('td[data-field="duration"]');
    let pageMsTotal = 0;
    durCells.forEach((cell) => {
      const msAttr = cell.getAttribute("data-duration-ms");
      if (!msAttr) return;
      const ms = parseInt(msAttr, 10);
      if (isNaN(ms) || ms <= 0) return;
      const fpsAttr = cell.getAttribute("data-fps");
      const fpsNum = fpsAttr ? parseInt(fpsAttr, 10) : null;
      const pretty = formatDurationMsToTimecode(ms, 24, fpsNum);
      cell.textContent = pretty;
      pageMsTotal += ms;
    });
    return pageMsTotal;
  }

  function renderTotalRuntimeFromDayAttribute() {
    const blockEl = document.getElementById("zd-runtime-block");
    const outEl = document.getElementById("zd-total-runtime");
    if (!blockEl || !outEl) return;
    const totalAttr = blockEl.getAttribute("data-day-total-ms");
    const totalMs = parseInt(totalAttr || "0", 10);
    outEl.textContent = formatTotalMsToHHMMSSFF(totalMs, 24);
  }

  function renderUnfilteredRuntime() {
    const el = document.getElementById("zd-unfiltered-runtime");
    if (!el) return;
    const ms = parseInt(el.getAttribute("data-ms") || "0", 10);
    // Reusing your existing timecode function
    el.textContent = formatTotalMsToHHMMSSFF(ms, 24);
  }

  // ---- Selection state
  const selectedRows = new Set();
  let lastClickedIndex = null;

  function renderSelectedRuntime() {
    const selRuntimeEl = document.getElementById("zd-selected-runtime");
    if (!selRuntimeEl) return;
    let totalSelMs = 0;
    selectedRows.forEach((clipUuid) => {
      const rowEl = document.querySelector(`#clip-${clipUuid}`);
      if (!rowEl) return;
      const durCell = rowEl.querySelector('td[data-field="duration"]');
      if (!durCell) return;
      const msAttr = durCell.getAttribute("data-duration-ms");
      if (!msAttr) return;
      const ms = parseInt(msAttr, 10);
      if (!isNaN(ms) && ms > 0) totalSelMs += ms;
    });
    selRuntimeEl.textContent = formatTotalMsToHHMMSSFF(totalSelMs, 24);
  }

  function updateBulkUI() {
    const count = selectedRows.size;
    if (selCountEl) selCountEl.textContent = count + " selected";
    if (bulkPosterBtn) bulkPosterBtn.disabled = count === 0;
    renderSelectedRuntime();
    if (importBtn) {
      if (count > 0) {
        importBtn.textContent = "Import metadata for selected";
        importBtn.title = "Only apply CSV rows to selected clips";
      } else {
        importBtn.textContent = "Import metadata";
        importBtn.title = "Apply CSV rows to matching clips in this day";
      }
    }
  }

  function toggleSingleRow(trEl, forceOn = null) {
    if (!trEl) return;
    const clipUuid = trEl.getAttribute("data-clip-uuid");
    if (!clipUuid) return;
    const nowOn =
      forceOn === true
        ? true
        : forceOn === false
          ? false
          : !selectedRows.has(clipUuid);
    if (nowOn) {
      selectedRows.add(clipUuid);
      trEl.classList.add("zd-selected-row");
    } else {
      selectedRows.delete(clipUuid);
      trEl.classList.remove("zd-selected-row");
    }
  }

  function clearAllSelection() {
    selectedRows.clear();
    tbodyEl
      .querySelectorAll("tr.zd-selected-row")
      .forEach((tr) => tr.classList.remove("zd-selected-row"));
  }

  function selectRange(idxA, idxB) {
    const start = Math.min(idxA, idxB);
    const end = Math.max(idxA, idxB);
    for (let i = start; i <= end; i++) {
      const trEl = tbodyEl.querySelector('tr[data-row-index="' + i + '"]');
      toggleSingleRow(trEl, true);
    }
  }

  // ---- Row click (selection)
  if (tbodyEl) {
    tbodyEl.addEventListener("click", (ev) => {
      if (
        ev.target.closest(
          "button.icon-btn, a.icon-btn, a[href], button[data-action], button.star-toggle, button.restrict-toggle",
        )
      )
        return;
      const trEl = ev.target.closest("tr[data-clip-uuid]");
      if (!trEl) return;
      const thisIdx = parseInt(trEl.getAttribute("data-row-index"), 10);
      const ctrl = ev.ctrlKey || ev.metaKey;
      const shift = ev.shiftKey;
      if (shift && lastClickedIndex !== null) {
        if (!ctrl) clearAllSelection();
        selectRange(lastClickedIndex, thisIdx);
      } else if (ctrl) {
        toggleSingleRow(trEl, null);
        lastClickedIndex = thisIdx;
      } else {
        clearAllSelection();
        toggleSingleRow(trEl, true);
        lastClickedIndex = thisIdx;
      }
      updateBulkUI();
    });

    // Double-click → edit for admins, play for regular users
    tbodyEl.addEventListener("dblclick", (ev) => {
      const trEl = ev.target.closest("tr[data-clip-uuid]");
      if (!trEl) return;
      const clipUuid = trEl.getAttribute("data-clip-uuid");
      if (!clipUuid) return;

      // Use per-row day UUID when available (important for All-days mode)
      const rowDayUuid = trEl.getAttribute("data-day-uuid") || dayUuid || "";

      const base = `/admin/projects/${encodeURIComponent(
        projectUuid,
      )}/days/${encodeURIComponent(rowDayUuid)}`;

      const path = canEdit
        ? `/clips/${encodeURIComponent(clipUuid)}/edit`
        : `/player/${encodeURIComponent(clipUuid)}`;

      window.location.href = base + path;
    });
  }

  // Ctrl/Cmd + A → select all
  document.addEventListener("keydown", (ev) => {
    const isSelectAll =
      (ev.key === "a" || ev.key === "A") && (ev.ctrlKey || ev.metaKey);
    if (!isSelectAll) return;
    ev.preventDefault();
    clearAllSelection();
    if (!tbodyEl) return;
    tbodyEl
      .querySelectorAll("tr[data-clip-uuid]")
      .forEach((tr) => toggleSingleRow(tr, true));
    updateBulkUI();
  });

  // ---- Init durations + totals
  initDurationsOnPage();
  renderTotalRuntimeFromDayAttribute();
  renderSelectedRuntime();
  renderUnfilteredRuntime();

  // ---- Import CSV — pack selected UUIDs
  if (importForm) {
    importForm.addEventListener("submit", (ev) => {
      if (importUids) {
        importUids.value =
          selectedRows.size > 0 ? Array.from(selectedRows).join(",") : "";
      }
      const file = document.getElementById("zd-import-file");
      if (!file || !file.files || file.files.length === 0) {
        ev.preventDefault();
        alert("Please choose a CSV file first.");
      }
    });
  }

  // ---- Quick edit (scene/slate/take)
  function beginQuickEdit(td) {
    if (!td || td.classList.contains("zd-editing")) return;
    const field = td.getAttribute("data-edit");
    if (!field) return;
    const tr = td.closest("tr[data-clip-uuid]");
    const clipUuid = tr?.getAttribute("data-clip-uuid");
    if (!clipUuid) return;

    const span = td.querySelector("span");
    const oldVal = span ? span.textContent : "";

    td.classList.add("zd-editing");
    const input = document.createElement("input");
    input.type = "text";
    input.className = "zd-inline-input";
    input.value = oldVal;
    td.innerHTML = "";
    td.appendChild(input);
    input.focus();
    input.select();

    const cancel = () => {
      td.classList.remove("zd-editing");
      td.innerHTML = `<span>${oldVal}</span>`;
    };

    const commit = async (newVal) => {
      if (newVal === oldVal) {
        cancel();
        return;
      }

      try {
        const body = new URLSearchParams({
          _csrf: quickCsrf,
          field: field,
          value: newVal,
        });

        // Use the row's real day UUID (important in "All days" mode)
        const rowDayUuid = tr?.getAttribute("data-day-uuid") || dayUuid || "";

        const resp = await fetch(
          `/admin/projects/${encodeURIComponent(
            projectUuid,
          )}/days/${encodeURIComponent(rowDayUuid)}/clips/${encodeURIComponent(
            clipUuid,
          )}/quick`,
          {
            method: "POST",
            headers: {
              "Content-Type": "application/x-www-form-urlencoded",
            },
            body,
          },
        );

        const json = await resp.json();
        if (resp.ok && json.ok) {
          const show = json.display ?? newVal ?? "";
          td.classList.remove("zd-editing");
          td.innerHTML = `<span>${show}</span>`;
        } else {
          alert(json.error || "Save failed");
          cancel();
        }
      } catch (e) {
        console.error(e);
        alert("Network error");
        cancel();
      }
    };

    input.addEventListener("keydown", (ev) => {
      if (ev.key === "Enter") {
        ev.preventDefault();
        commit(input.value.trim());
      } else if (ev.key === "Escape") {
        ev.preventDefault();
        cancel();
      }
    });
    // --- Tab navigation: scene → slate → take ---
    input.addEventListener("keydown", (ev) => {
      if (ev.key === "Tab") {
        ev.preventDefault();

        const cell = input.closest("td");
        const row = cell.closest("tr");

        const order = ["scene", "slate", "take", "camera"];
        const current = cell.dataset.edit;
        const idx = order.indexOf(current);

        if (idx >= 0 && idx < order.length - 1) {
          const nextField = order[idx + 1];
          const nextTd = row.querySelector(`td[data-edit="${nextField}"]`);
          if (nextTd) {
            nextTd.click(); // triggers beginQuickEdit(nextTd)
            return;
          }
        }

        // After "take", exit edit mode
        input.blur();
      }
    });

    input.addEventListener("blur", () => commit(input.value.trim()));
  }

  if (tbodyEl) {
    tbodyEl.addEventListener("click", (ev) => {
      const td = ev.target.closest("td.zd-editable");
      if (!td) return;
      if (ev.target.closest("a,button")) return; // don’t hijack buttons/links
      beginQuickEdit(td);
    });

    // Toggle star (selected)
    tbodyEl.addEventListener("click", async (ev) => {
      const btn = ev.target.closest("button.star-toggle");
      if (!btn) return;
      ev.stopPropagation();

      const clipUuid = btn.getAttribute("data-clip");
      if (!clipUuid) return;

      const cur = parseInt(btn.getAttribute("data-selected") || "0", 10);
      const next = cur ? 0 : 1;

      // Optimistic UI
      setStarVisual(btn, next);
      btn.setAttribute("data-selected", String(next));

      try {
        const body = new URLSearchParams({
          _csrf: quickCsrf,
          value: String(next),
        });

        const tr = btn.closest("tr[data-clip-uuid]");
        const rowDayUuid = tr?.getAttribute("data-day-uuid") || dayUuid || "";

        const resp = await fetch(
          `/admin/projects/${encodeURIComponent(
            projectUuid,
          )}/days/${encodeURIComponent(rowDayUuid)}/clips/${encodeURIComponent(
            clipUuid,
          )}/select`,
          {
            method: "POST",
            headers: {
              "Content-Type": "application/x-www-form-urlencoded",
            },
            body,
          },
        );

        const json = await resp.json();
        if (!(resp.ok && json.ok)) {
          // Revert on server / validation error
          setStarVisual(btn, cur);
          btn.setAttribute("data-selected", String(cur));
          alert(json.error || "Failed to save");
        }
      } catch (e) {
        console.error(e);
        // Revert on JS / network error
        setStarVisual(btn, cur);
        btn.setAttribute("data-selected", String(cur));
        alert("Network error");
      }
    });

    // --- Background Job Polling ---
    function pollJobStatus() {
      const activeSpinners = document.querySelectorAll(".zd-spinner-small");
      if (activeSpinners.length === 0) return;

      const pUuid = root.dataset.project;
      const dUuid = root.dataset.day;

      if (dUuid === "all") return; // Avoid 404 on global view

      // We hit a status endpoint (you'll need to add this to DayConverterController)
      fetch(`/admin/projects/${pUuid}/days/${dUuid}/converter/status`)
        .then((res) => res.json())
        .then((data) => {
          if (!data.jobs) return;
          data.jobs.forEach((job) => {
            const container = document.querySelector(
              `.zd-job-status[data-clip="${job.clip_uuid}"]`,
            );
            if (!container) return;

            if (job.state === "done") {
              container.innerHTML =
                '<span class="zd-meta-ok" style="color:#3aa0ff;">Ready</span>';
            } else if (job.state === "failed") {
              container.innerHTML =
                '<span class="zd-meta-err" style="color:#d62828;">Failed</span>';
            }
          });
        })
        .catch((err) => console.error("Status poll error:", err));
    }

    // Poll every 4 seconds
    // setInterval(pollJobStatus, 4000); // Disabled until endpoint is ready
    // --- Group-Based Restriction Handling (Multi-select) ---
    // --- Group-Based Restriction Handling (Multi-select) ---
    document.addEventListener("submit", async (e) => {
      // 1. Identify the restriction form
      if (!e.target.classList.contains("restrict-form")) return;
      e.preventDefault();

      const form = e.target;
      const clipUuid = form.dataset.clip;
      const menuRoot = form.closest(".zd-pro-menu");
      const trigger = menuRoot.querySelector(".restrict-trigger");
      const label = trigger.querySelector(".restrict-label");
      const lockIcon = trigger.querySelector(".lock");

      // 2. Prepare Multi-Select Data (includes group array)
      const formData = new FormData(form);
      // Sync the global quick_csrf token from the root dataset
      const csrfVal = root.dataset.quickCsrf || "";
      formData.append("_csrf", csrfVal);

      // 3. UI Loading State
      trigger.style.opacity = "0.5";
      trigger.style.pointerEvents = "none";

      try {
        // Use the row context for the URL to handle "All Days" view correctly
        const tr = form.closest("tr[data-clip-uuid]");
        const rowDayUuid =
          tr?.getAttribute("data-day-uuid") || root.dataset.day || "";

        const response = await fetch(
          `/admin/projects/${root.dataset.project}/days/${rowDayUuid}/clips/${clipUuid}/restrict`,
          {
            method: "POST",
            body: formData,
          },
        );

        const result = await response.json();

        if (response.ok && result.ok) {
          // 4. Update UI based on if groups were actually selected
          const isRestricted = result.is_restricted;

          // --- 4. Update Lock UI ---
          trigger.classList.toggle("is-active", isRestricted);
          if (label) label.textContent = isRestricted ? "Restricted" : "Public";

          if (lockIcon) {
            lockIcon.classList.toggle("on", isRestricted);
            lockIcon.classList.toggle("off", !isRestricted);
          }

          // --- NEW: Update Thumbnail Sensitive Badge ---
          const thumbContainer = tr.querySelector(".col-thumb > div");
          if (thumbContainer) {
            let badge = thumbContainer.querySelector(".zd-sensitive-chip");
            if (isRestricted) {
              if (!badge) {
                badge = document.createElement("div");
                badge.className = "zd-sensitive-chip";
                badge.textContent = "SENSITIVE";
                thumbContainer.appendChild(badge);
              }
            } else if (badge) {
              badge.remove();
            }
          }

          // 5. SUCCESS: Close the menu and reset row z-index
          menuRoot.classList.remove("is-active");
          if (tr) tr.style.zIndex = "";
        } else {
          alert(
            "Error updating restriction: " + (result.error || "Unknown error"),
          );
        }
      } catch (err) {
        console.error("Restriction update failed:", err);
      } finally {
        trigger.style.opacity = "1";
        trigger.style.pointerEvents = "auto";
      }
    });
  }

  function setStarVisual(btn, isOn) {
    const svg = btn.querySelector("svg.star");
    if (!svg) return;
    svg.classList.toggle("on", !!isOn);
    svg.classList.toggle("off", !isOn);
  }

  // ---- Live Filtering (As you type) ----
  // Renamed to liveFilterForm to avoid conflicts
  const liveFilterForm = document.querySelector(".zd-filters");

  if (liveFilterForm) {
    // Inputs to trigger live refresh
    const liveInputs = liveFilterForm.querySelectorAll(
      'input[name="scene"], input[name="slate"], input[name="take"], input[name="text"]',
    );

    // Debounce helper: wait 300ms after typing stops before fetching
    function debounce(fn, delay) {
      let timer;
      return function (...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), delay);
      };
    }

    const updateTableViaAjax = async () => {
      // Visual feedback
      if (tbodyEl) tbodyEl.style.opacity = "0.5";

      try {
        // Build URL with current form data, forcing page=1
        const formData = new FormData(liveFilterForm);
        const params = new URLSearchParams(formData);
        params.set("page", "1"); // Reset pagination on filter change

        // Preserve current day context in URL
        const targetUrl = window.location.pathname + "?" + params.toString();

        const resp = await fetch(targetUrl);
        if (!resp.ok) throw new Error("Filter request failed");

        const html = await resp.text();
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, "text/html");

        // 1. Replace Table Body Rows
        const newTbody = doc.querySelector(".zd-table tbody");
        if (newTbody && tbodyEl) {
          tbodyEl.innerHTML = newTbody.innerHTML;
        }

        // 2. REFRESH CSRF TOKENS (Essential for Bulk/Single Actions)
        const newRoot = doc.querySelector(".zd-clips-page");
        if (newRoot && root) {
          // Sync the fresh tokens from the server into our live root dataset
          root.dataset.converterCsrf = newRoot.dataset.converterCsrf;
          root.dataset.quickCsrf = newRoot.dataset.quickCsrf;

          // Log for debugging if needed: console.log("Tokens refreshed:", root.dataset.converterCsrf);
        }

        // 3. Replace Pager
        const oldPager = document.querySelector(".zd-pager");
        const newPager = doc.querySelector(".zd-pager");
        if (oldPager) {
          if (newPager) oldPager.replaceWith(newPager);
          else oldPager.innerHTML = ""; // Clear if no pages left
        }

        // 4. Replace Header Stats (Count / Total Runtime)
        const oldHead = document.querySelector(".zd-clips-head");
        const newHead = doc.querySelector(".zd-clips-head");
        if (oldHead && newHead) {
          oldHead.innerHTML = newHead.innerHTML;
        }

        // 5. Re-initialize JS logic on new elements
        initDurationsOnPage(); // Recalculate pretty timecodes
        renderTotalRuntimeFromDayAttribute(); // Update total text
        renderUnfilteredRuntime();

        // Re-apply visual selection state
        selectedRows.forEach((uuid) => {
          const row = document.getElementById("clip-" + uuid);
          if (row) row.classList.add("zd-selected-row");
        });
        updateBulkUI(); // Recalculate selected stats

        // 6. Update Browser URL (so refresh keeps filters)
        window.history.replaceState({}, "", targetUrl);

        // 7. Re-sync layout so pager and headers stay centered with the table
      } catch (err) {
        console.error("Live filter error:", err);
      } finally {
        if (tbodyEl) tbodyEl.style.opacity = "";
      }
    };

    const debouncedUpdate = debounce(updateTableViaAjax, 300);

    // Attach listeners
    liveInputs.forEach((input) => {
      input.addEventListener("input", debouncedUpdate);
    });

    // ---- New: Live Filtering for Dropdowns ----
    const liveSelects = liveFilterForm.querySelectorAll(
      'select[name="camera"], select[name="rating"], select[name="select"]',
    );

    liveSelects.forEach((sel) => {
      // Update immediately on change (no debounce needed for dropdowns)
      sel.addEventListener("change", updateTableViaAjax);
    });
  }

  // =======================
  // Publish / Unpublish Modal
  // =======================
  document.addEventListener("DOMContentLoaded", function () {
    const backdrop = document.getElementById("zd-publish-backdrop");

    const btnOpenPublish = document.getElementById("zd-publish-open");
    const btnOpenUnpublish = document.getElementById("zd-unpublish-open");

    const btnCancel = document.getElementById("zd-publish-cancel");
    const btnConfirm = document.getElementById("zd-publish-confirm");

    const sendEmailBlock = document.getElementById("zd-modal-email-block");
    const sendEmailInput = document.getElementById("zd-publish-send-email");
    const titleEl = document.getElementById("zd-modal-title");
    const messageEl = document.getElementById("zd-modal-message");
    const publishExtra = document.getElementById("zd-modal-publish-extra");

    const publishForm = document.getElementById("zd-publish-form");
    const unpublishForm = document.getElementById("zd-unpublish-form");
    const sendField = document.getElementById("zd-publish-send-email-field");

    let currentMode = "publish";

    function openModal(mode) {
      currentMode = mode;

      if (mode === "publish") {
        titleEl.textContent = "Publish day";
        messageEl.textContent = "Are you sure you want to publish this day?";
        btnConfirm.textContent = "Publish";

        sendEmailBlock.style.display = "block";
        publishExtra.style.display = "block";
      } else {
        titleEl.textContent = "Unpublish day";
        messageEl.textContent =
          "Unpublish this day? It will no longer be visible to regular users.";
        btnConfirm.textContent = "Unpublish";

        sendEmailBlock.style.display = "none";
        publishExtra.style.display = "none";
      }

      if (backdrop) backdrop.hidden = false;
    }

    function closeModal() {
      if (backdrop) backdrop.hidden = true;
    }

    if (btnOpenPublish) {
      btnOpenPublish.addEventListener("click", () => openModal("publish"));
    }
    if (btnOpenUnpublish) {
      btnOpenUnpublish.addEventListener("click", () => openModal("unpublish"));
    }

    if (btnCancel) {
      btnCancel.addEventListener("click", closeModal);
    }

    if (backdrop) {
      backdrop.addEventListener("click", (e) => {
        if (e.target === backdrop) closeModal();
      });
    }

    if (btnConfirm) {
      btnConfirm.addEventListener("click", () => {
        if (currentMode === "publish") {
          if (sendField && sendEmailInput) {
            sendField.value = sendEmailInput.checked ? "1" : "0";
          }
          if (publishForm) publishForm.submit();
        } else {
          if (unpublishForm) unpublishForm.submit();
        }
      });
    }
  });

  // =======================
  // ⋯ Actions menu + bulk actions
  // =======================
  document.addEventListener("click", (e) => {
    const btn = e.target.closest(".zd-actions-btn");
    const wrap = btn?.closest(".zd-actions-wrap");
    const isInsideRestrictionMenu = e.target.closest(".zd-pro-menu");

    // 1. Logic for Single Restriction Menu (Click Outside)
    if (!isInsideRestrictionMenu) {
      document.querySelectorAll(".zd-pro-menu.is-active").forEach((menu) => {
        menu.classList.remove("is-active");
        const tr = menu.closest("tr");
        if (tr) tr.style.zIndex = "";
      });
    }

    // 2. Logic for ⋯ Actions Menu (Click Outside)
    if (!btn) {
      document.querySelectorAll(".zd-actions-wrap.open").forEach((w) => {
        w.classList.remove("open");
        w.classList.remove("drop-up");
      });
    }

    // 3. Page + shared refs (moved inside listener scope)
    const pageRoot = document.querySelector(".zd-clips-page");
    const projectUuid = pageRoot?.dataset.project || "";
    const currentDayUuid = pageRoot?.dataset.day || "";

    const importFileInput = document.getElementById("zd-import-file");
    const importOverwrite = document.getElementById("zd-import-overwrite");
    const importUuids = document.getElementById("zd-import-uuids");

    const bulkDeleteForm = document.getElementById("zd-bulk-delete-form");
    const bulkDeleteUuids = document.getElementById("zd-bulk-delete-uuids");

    // Helper functions inside the listener
    function getSelectedClipUuids() {
      return Array.from(
        document.querySelectorAll(".zd-table tbody tr.zd-selected-row"),
      )
        .map((tr) => tr.dataset.clipUuid)
        .filter(Boolean);
    }

    function closeAllMenus() {
      document.querySelectorAll(".zd-actions-wrap.open").forEach((w) => {
        w.classList.remove("open");
        w.classList.remove("drop-up");
      });
    }

    // === Handle Opening the ⋯ menu ===
    if (btn && wrap) {
      const wasOpen = wrap.classList.contains("open");
      closeAllMenus();

      if (!wasOpen) {
        wrap.classList.remove("drop-up");
        if (!wrap.classList.contains("zd-actions-wrap-head")) {
          const rect = wrap.getBoundingClientRect();
          const spaceBelow = window.innerHeight - rect.bottom;
          if (spaceBelow < 160) wrap.classList.add("drop-up");
        }
        wrap.classList.add("open");
      }
      e.stopPropagation();
      return;
    }

    // === Header bulk: Import metadata for selected (overwrite) ===
    const bulkImportBtn = e.target.closest("[data-bulk-import]");
    if (bulkImportBtn) {
      e.preventDefault();
      const uuids = getSelectedClipUuids();
      if (!uuids.length) {
        alert("No clips selected.");
        closeAllMenus();
        return;
      }
      if (!importFileInput || !importOverwrite || !importUuids) return;

      importOverwrite.checked = true;
      importUuids.value = uuids.join(",");
      importFileInput.click(); // open file picker
      closeAllMenus();
      return;
    }

    // === Header bulk: Manage Restrictions (Modal) ===
    const bulkRestrictOpen = document.getElementById("zd-bulk-restrict-open");
    const bulkRestrictModal = document.getElementById("zd-bulk-restrict-modal");
    const bulkRestrictCancel = document.getElementById(
      "zd-bulk-restrict-cancel",
    );
    const bulkRestrictConfirm = document.getElementById(
      "zd-bulk-restrict-confirm",
    );

    if (bulkRestrictOpen) {
      bulkRestrictOpen.addEventListener("click", (e) => {
        e.preventDefault();
        const uuids = getSelectedClipUuids();
        if (!uuids.length) return alert("No clips selected.");

        document.getElementById("zd-bulk-restrict-count").textContent =
          uuids.length;
        bulkRestrictModal.hidden = false;
        closeAllMenus();
      });
    }

    if (bulkRestrictCancel) {
      bulkRestrictCancel.addEventListener(
        "click",
        () => (bulkRestrictModal.hidden = true),
      );
    }

    if (bulkRestrictConfirm) {
      bulkRestrictConfirm.addEventListener("click", async () => {
        if (bulkRestrictConfirm.disabled) return; // Prevent double-clicks
        bulkRestrictConfirm.disabled = true;
        const uuids = getSelectedClipUuids();
        const form = document.getElementById("zd-bulk-restrict-form");
        const formData = new FormData(form);

        formData.append("clip_uuids", uuids.join(","));
        formData.append("_csrf", root.dataset.quickCsrf);

        bulkRestrictConfirm.disabled = true;
        bulkRestrictConfirm.textContent = "Updating...";

        try {
          const resp = await fetch(
            `/admin/projects/${projectUuid}/days/${currentDayUuid}/clips/bulk-restrict`,
            {
              method: "POST",
              body: formData,
            },
          );
          const result = await resp.json();

          if (resp.ok && result.ok) {
            window.location.reload(); // Simplest way to refresh all lock icons
          } else {
            alert("Error: " + (result.error || "Unknown error"));
            bulkRestrictConfirm.disabled = false;
            bulkRestrictConfirm.textContent = "Update Selected";
          }
        } catch (err) {
          console.error(err);
          alert("Network error.");
          bulkRestrictConfirm.disabled = false;
        }
      });
    }

    // === Header bulk: Delete selected ===
    const bulkDeleteBtn = e.target.closest("[data-bulk-delete]");
    if (bulkDeleteBtn) {
      e.preventDefault();
      const uuids = getSelectedClipUuids();
      if (!uuids.length) {
        alert("No clips selected.");
        closeAllMenus();
        return;
      }
      if (!bulkDeleteForm || !bulkDeleteUuids) return;

      if (!confirm("Delete " + uuids.length + " selected clip(s)?")) {
        closeAllMenus();
        return;
      }
      bulkDeleteUuids.value = uuids.join(",");
      bulkDeleteForm.submit();
      return;
    }

    // === Per-clip: Import metadata for THIS clip only ===
    const clipImportLink = e.target.closest("[data-clip-import]");
    if (clipImportLink) {
      e.preventDefault();
      const clipUuid = clipImportLink.getAttribute("data-clip-import");
      if (!clipUuid || !importFileInput || !importOverwrite || !importUuids)
        return;

      importOverwrite.checked = true;
      importUuids.value = clipUuid;
      importFileInput.click(); // same CSV form, limited to single clip
      closeAllMenus();
      return;
    }

    // === Per-clip: Generate SINGLE waveform ===
    const singleWaveformLink = e.target.closest("[data-clip-waveform]");
    if (singleWaveformLink) {
      e.preventDefault();
      const row = singleWaveformLink.closest("tr[data-clip-uuid]");
      const clipUuid =
        singleWaveformLink.getAttribute("data-clip-waveform") ||
        (row ? row.dataset.clipUuid : "");
      const rowDayUuid = (row && row.dataset.dayUuid) || currentDayUuid;

      const fd = new FormData();
      fd.append("csrf_token", root.dataset.converterCsrf);
      fd.append("clip_uuid", clipUuid);

      fetch(
        `/admin/projects/${projectUuid}/days/${rowDayUuid}/converter/waveform`,
        {
          method: "POST",
          body: fd,
        },
      );

      closeAllMenus();
      return;
    }

    // === Per-clip: Generate SINGLE poster ===
    const singlePosterLink = e.target.closest("[data-clip-poster]");
    if (singlePosterLink) {
      e.preventDefault();
      const row = singlePosterLink.closest("tr[data-clip-uuid]");
      const clipUuid =
        singlePosterLink.getAttribute("data-clip-poster") ||
        (row ? row.dataset.clipUuid : "");
      const rowDayUuid = (row && row.dataset.dayUuid) || currentDayUuid;

      generatePosterAjax(clipUuid, rowDayUuid);

      closeAllMenus();
      return;
    }

    // === Header bulk: Generate waveforms ===
    const bulkWaveformBtn = e.target.closest("[data-bulk-waveform]");
    if (bulkWaveformBtn) {
      e.preventDefault();
      const selectedUuids = getSelectedClipUuids();
      if (!selectedUuids.length) return alert("No clips selected.");

      const overlay = document.getElementById("zd-processing-overlay");
      const titleEl = document.getElementById("zd-processing-title");
      const textEl = document.getElementById("zd-processing-text");

      if (overlay) {
        titleEl.textContent = "Queuing Waveforms...";
        textEl.innerHTML = `Adding <b>${selectedUuids.length}</b> jobs to queue.<br><b>Please do not navigate away.</b>`;

        // Force the display immediately using the style object
        overlay.style.setProperty("display", "flex", "important");
        overlay.removeAttribute("hidden");
      }

      setTimeout(async () => {
        const fd = new FormData();
        fd.append("clip_uuids", selectedUuids.join(","));
        fd.append("csrf_token", root.dataset.converterCsrf);

        try {
          const res = await fetch(
            `/admin/projects/${projectUuid}/days/${dayUuid}/converter/waveforms-bulk`,
            {
              method: "POST",
              body: fd,
            },
          );
          const data = await res.json();
          if (res.ok && data.ok) {
            titleEl.textContent = "Jobs Queued!";
            titleEl.style.color = "#3aa0ff";
            setTimeout(() => window.location.reload(), 1200);
          } else {
            overlay.style.display = "none"; // Hide if it fails
            alert("Queue failed: " + (data.error || "Unknown error"));
          }
        } catch (err) {
          overlay.setAttribute("hidden", "");
          alert("Network error.");
        }
      }, 50);

      closeAllMenus();
      return;
    }

    // === Header bulk: Generate posters (Queued) ===
    const bulkPosterBtnInternal = e.target.closest("[data-bulk-poster]");
    if (bulkPosterBtnInternal) {
      e.preventDefault();
      const selectedUuids = getSelectedClipUuids();
      if (!selectedUuids.length) return alert("No clips selected.");

      const overlay = document.getElementById("zd-processing-overlay");
      const titleEl = document.getElementById("zd-processing-title");
      const textEl = document.getElementById("zd-processing-text");

      if (overlay) {
        titleEl.textContent = "Queuing Poster Jobs...";
        textEl.innerHTML = `Adding <b>${selectedUuids.length}</b> posters to background queue.<br><b>Please do not navigate away.</b>`;
        overlay.style.setProperty("display", "flex", "important");
      }

      setTimeout(async () => {
        const fd = new FormData();
        fd.append("clip_uuids", selectedUuids.join(","));
        fd.append("csrf_token", root.dataset.converterCsrf);

        try {
          // Send ONE request to the bulk endpoint instead of 147 individual calls
          const res = await fetch(
            `/admin/projects/${projectUuid}/days/${dayUuid}/converter/posters-bulk`,
            {
              method: "POST",
              body: fd,
            },
          );
          const data = await res.json();
          if (res.ok && data.ok) {
            titleEl.textContent = "All Jobs Queued!";
            titleEl.style.color = "#3aa0ff";
            textEl.innerHTML = `<b>${data.done}</b> posters added to the background worker.`;
            setTimeout(() => window.location.reload(), 1200);
          } else {
            overlay.style.display = "none";
            alert("Queue failed: " + (data.error || "Unknown error"));
          }
        } catch (err) {
          overlay.style.display = "none";
          alert("Network error while queuing bulk posters.");
        }
      }, 50);

      closeAllMenus();
      return;
    }

    // === Click elsewhere: close any open menus ===
    closeAllMenus();
  });

  // --- Queue Dashboard Polling ---
  function updateQueueDashboard() {
    const dash = document.getElementById("zd-job-dashboard");
    const countEl = document.getElementById("zd-dash-count");

    // "root" is defined in the IIFE closure at the top of admin.clips.js
    // If you can't access it, use document.querySelector('.zd-clips-page')
    const pageRoot = document.querySelector(".zd-clips-page");
    const pUuid = pageRoot?.dataset.project;
    const dUuid = pageRoot?.dataset.day;

    if (!dash || !pUuid || !dUuid) return;

    // [FIX] Removed the (dUuid === "all") check so it works in Project View too
    fetch(`/admin/projects/${pUuid}/days/${dUuid}/converter/queue-summary`)
      .then((res) => res.json())
      .then((data) => {
        if (data.ok) {
          const total = data.queued + data.running;
          if (total > 0) {
            dash.style.display = "inline-flex";
            countEl.textContent = total;

            // Optional: Add a 'running' class if jobs are active
            if (data.running > 0) dash.classList.add("is-running");
            else dash.classList.remove("is-running");
          } else {
            dash.style.display = "none";
          }
        }
      })
      .catch((err) => console.warn("Queue poll failed", err));
  }

  // --- Handling Generator Buttons (Poster & Waveform) with Spinners ---
  document.addEventListener("click", async (e) => {
    // 1. Detect Click on Generator Buttons
    const btn = e.target.closest(
      'button[data-action="gen-poster"], button[data-action="gen-waveform"]',
    );
    if (!btn) return;

    e.preventDefault();
    e.stopPropagation();

    if (btn.disabled) return;

    // 2. Gather Data
    const clipUuid = btn.dataset.clipUuid;
    const action = btn.dataset.action;
    const pageRoot = document.querySelector(".zd-clips-page");
    const projectUuid = pageRoot?.dataset.project;
    const csrfToken = pageRoot?.dataset.converterCsrf;

    // Find the specific day for this clip
    const clipRow = document.querySelector(
      `tr[data-clip-uuid="${clipUuid}"], div[data-clip-uuid="${clipUuid}"]`,
    );
    const dayUuid = clipRow?.dataset.dayUuid || pageRoot?.dataset.day;

    if (!clipUuid || !projectUuid || !dayUuid || !csrfToken) {
      alert("Error: Missing clip data. Please refresh.");
      return;
    }

    // 3. Locate Thumbnail Wrapper & Show Spinner
    // [FIX] Added .col-thumb > div to find the wrapper in the table
    const thumbWrap = clipRow
      ? clipRow.querySelector(
          ".thumb-wrap, .zd-thumb-container, .col-thumb > div",
        )
      : null;
    let spinner = null;

    if (thumbWrap) {
      const old = thumbWrap.querySelector(".thumb-spinner-overlay");
      if (old) old.remove();

      spinner = document.createElement("div");
      spinner.className = "thumb-spinner-overlay";
      spinner.innerHTML = '<div class="thumb-spinner"></div>';

      // Ensure relative positioning so spinner overlays correctly
      if (getComputedStyle(thumbWrap).position === "static") {
        thumbWrap.style.position = "relative";
      }

      thumbWrap.appendChild(spinner);
    }

    btn.disabled = true;
    const originalIcon = btn.innerHTML;

    try {
      const endpoint =
        action === "gen-poster"
          ? `/admin/projects/${projectUuid}/days/${dayUuid}/converter/poster`
          : `/admin/projects/${projectUuid}/days/${dayUuid}/converter/waveform`;

      const formData = new FormData();
      formData.append("clip_uuid", clipUuid);
      formData.append("csrf_token", csrfToken);
      if (action === "gen-poster") formData.append("force", "1");

      const res = await fetch(endpoint, {
        method: "POST",
        body: formData,
        headers: { "X-Requested-With": "XMLHttpRequest" },
      });

      const data = await res.json();

      if (data.ok) {
        // 7. Success: Poll for the NEW image before displaying
        if (action === "gen-poster" && data.href && thumbWrap) {
          // [FIX] Handle case where no image existed before (skeleton state)
          let img = thumbWrap.querySelector("img");
          if (!img) {
            // Remove skeleton/placeholder if present
            const placeholder = thumbWrap.querySelector(
              ".zd-thumb-skeleton, .no-thumb",
            );
            if (placeholder) placeholder.remove();

            // Create new image element
            img = document.createElement("img");
            img.className = "zd-thumb";
            img.style.width = "100%";
            img.style.height = "100%";
            thumbWrap.appendChild(img);
          }

          if (img) {
            // We expect the new file to have this specific byte size
            const targetBytes = data.bytes;

            // Start polling (Max 10 attempts over ~5 seconds)
            await waitForFreshImage(data.href, targetBytes);

            // Update the DOM
            // We generate a unique URL to force the browser to paint the new pixels
            const freshUrl = data.href + "?ts=" + Date.now();

            // Clear srcset if present, as it overrides src
            img.removeAttribute("srcset");
            img.src = freshUrl;

            // Wait for the 'load' event of the *element* to be sure
            await new Promise((r) => {
              if (img.complete) r();
              else img.onload = r;
            });
          }
        }
        if (action === "gen-waveform") console.log("Waveform generated.");
      } else {
        alert("Error: " + (data.error || "Unknown error"));
      }
    } catch (err) {
      console.error("Generator failed", err);
      alert("Request failed.");
    } finally {
      if (spinner) spinner.remove();
      btn.disabled = false;
      btn.innerHTML = originalIcon;
    }
  });

  /**
   * Polls the server until the file at 'url' matches 'expectedBytes'
   * This overcomes NFS latency where the web server serves stale files.
   */
  async function waitForFreshImage(url, expectedBytes) {
    if (!expectedBytes) {
      // Fallback: just wait 1s if we don't know the size
      return new Promise((resolve) => setTimeout(resolve, 1000));
    }

    const maxAttempts = 10;
    for (let i = 0; i < maxAttempts; i++) {
      try {
        // Use HEAD request to get file size from headers (fast)
        const checkUrl = url + "?check=" + Date.now() + Math.random();
        const resp = await fetch(checkUrl, { method: "HEAD" });

        const serverBytes = Number(resp.headers.get("content-length"));

        // Allow a tiny margin of error (sometimes servers add/strip a byte),
        // but usually it's exact.
        if (serverBytes === expectedBytes) {
          return true; // Found the new file!
        }
      } catch (e) {
        console.warn("Polling check failed", e);
      }

      // Wait 500ms before next try
      await new Promise((r) => setTimeout(r, 500));
    }
    // If timeout, return anyway and hope for the best
    return false;
  }

  // Add this to your admin_clips.js file
})();

// ======================================================
// AJAX Sorting - Prevents Layout Shift
// ======================================================
(function initAjaxSorting() {
  const root = document.querySelector(".zd-clips-page");
  if (!root) return;

  const tbodyEl = document.querySelector(".zd-table tbody");
  const pagerEl = document.querySelector(".zd-pager");
  const headEl = document.querySelector(".zd-clips-head");

  // Intercept all sort link clicks
  document.addEventListener("click", async (e) => {
    const sortLink = e.target.closest(".zd-sortable-header");
    if (!sortLink) return;

    e.preventDefault();

    const href = sortLink.getAttribute("href");
    if (!href) return;

    // Visual feedback - dim the table slightly
    if (tbodyEl) tbodyEl.style.opacity = "0.6";

    try {
      // Fetch the new sorted page
      const resp = await fetch(href);
      if (!resp.ok) throw new Error("Sort request failed");

      const html = await resp.text();
      const parser = new DOMParser();
      const doc = parser.parseFromString(html, "text/html");

      // 1. Replace table body
      const newTbody = doc.querySelector(".zd-table tbody");
      if (newTbody && tbodyEl) {
        tbodyEl.innerHTML = newTbody.innerHTML;
      }

      // 2. Update sort indicators in header
      const newTable = doc.querySelector(".zd-table");
      const currentTable = document.querySelector(".zd-table");
      if (newTable && currentTable) {
        // Replace thead to update arrow states
        const newThead = newTable.querySelector("thead");
        const currentThead = currentTable.querySelector("thead");
        if (newThead && currentThead) {
          currentThead.innerHTML = newThead.innerHTML;
        }
      }

      // 3. Update pager if it exists
      const newPager = doc.querySelector(".zd-pager");
      if (pagerEl && newPager) {
        pagerEl.innerHTML = newPager.innerHTML;
      }

      // 4. Update header stats
      const newHead = doc.querySelector(".zd-clips-head");
      if (headEl && newHead) {
        headEl.innerHTML = newHead.innerHTML;
      }

      // 5. Update browser URL without reload
      window.history.pushState({}, "", href);

      // 6. Re-initialize any JS that needs to run on new content
      if (typeof initDurationsOnPage === "function") {
        initDurationsOnPage();
      }
      if (typeof renderTotalRuntimeFromDayAttribute === "function") {
        renderTotalRuntimeFromDayAttribute();
      }
      if (typeof renderUnfilteredRuntime === "function") {
        renderUnfilteredRuntime();
      }

      // Re-apply selection state if you have multi-select
      if (typeof selectedRows !== "undefined" && selectedRows.size > 0) {
        selectedRows.forEach((uuid) => {
          const row = document.getElementById("clip-" + uuid);
          if (row) row.classList.add("zd-selected-row");
        });
      }
      if (typeof updateBulkUI === "function") {
        updateBulkUI();
      }
    } catch (err) {
      console.error("Ajax sort failed:", err);
      // Fallback to normal page load
      window.location.href = href;
    } finally {
      // Restore opacity
      if (tbodyEl) tbodyEl.style.opacity = "";
    }
  });
})();
