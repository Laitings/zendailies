// admin.clips.js  — Clips index page logic

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

        const order = ["scene", "slate", "take"];
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

    // Toggle lock (restricted)
    tbodyEl.addEventListener("click", async (ev) => {
      const btn = ev.target.closest("button.restrict-toggle");
      if (!btn) return;
      ev.stopPropagation();

      const clipUuid = btn.getAttribute("data-clip");
      if (!clipUuid) return;

      // Use the row's real day UUID (important in "All days" mode)
      const tr = btn.closest("tr[data-clip-uuid]");
      const rowDayUuid = tr?.getAttribute("data-day-uuid") || dayUuid || "";

      const cur = parseInt(btn.getAttribute("data-restricted") || "0", 10);
      const next = cur ? 0 : 1;

      const body = new URLSearchParams({
        _csrf: quickCsrf,
        value: String(next),
      });

      try {
        const resp = await fetch(
          `/admin/projects/${encodeURIComponent(
            projectUuid,
          )}/days/${encodeURIComponent(rowDayUuid)}/clips/${encodeURIComponent(
            clipUuid,
          )}/restrict`,
          {
            method: "POST",
            headers: {
              "Content-Type": "application/x-www-form-urlencoded",
            },
            body,
          },
        );

        const json = await resp.json();
        if (!resp.ok || !json.ok) {
          alert(json.error || "Toggle failed");
          return;
        }

        // Update state + icon
        btn.setAttribute("data-restricted", next ? "1" : "0");
        const lockEl = btn.querySelector(".lock");
        if (lockEl) {
          lockEl.classList.toggle("on", !!next);
          lockEl.classList.toggle("off", !next);
        }
      } catch (e) {
        console.error(e);
        alert("Network error");
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

        // 2. Replace Pager
        const oldPager = document.querySelector(".zd-pager");
        const newPager = doc.querySelector(".zd-pager");
        if (oldPager) {
          if (newPager) oldPager.replaceWith(newPager);
          else oldPager.innerHTML = ""; // Clear if no pages left
        }

        // 3. Replace Header Stats (Count / Total Runtime)
        const oldHead = document.querySelector(".zd-clips-head");
        const newHead = doc.querySelector(".zd-clips-head");
        if (oldHead && newHead) {
          oldHead.innerHTML = newHead.innerHTML;
        }

        // 4. Re-initialize JS logic on new elements
        initDurationsOnPage(); // Recalculate pretty timecodes
        renderTotalRuntimeFromDayAttribute(); // Update total text
        renderUnfilteredRuntime();

        // Re-apply visual selection state
        selectedRows.forEach((uuid) => {
          const row = document.getElementById("clip-" + uuid);
          if (row) row.classList.add("zd-selected-row");
        });
        updateBulkUI(); // Recalculate selected stats

        // 5. Update Browser URL (so refresh keeps filters)
        window.history.replaceState({}, "", targetUrl);

        // 6. Re-sync layout so pager and headers stay centered with the table
        syncClipsLayout();
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

    // Page + shared refs
    const pageRoot = document.querySelector(".zd-clips-page");
    const projectUuid = pageRoot?.dataset.project || "";
    const currentDayUuid = pageRoot?.dataset.day || "";

    const importFileInput = document.getElementById("zd-import-file");
    const importOverwrite = document.getElementById("zd-import-overwrite");
    const importUuids = document.getElementById("zd-import-uuids");

    const bulkDeleteForm = document.getElementById("zd-bulk-delete-form");
    const bulkDeleteUuids = document.getElementById("zd-bulk-delete-uuids");

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

    // === Open/close any ⋯ menu ===
    if (btn && wrap) {
      const wasOpen = wrap.classList.contains("open");

      // Close everything first so we never have two open
      closeAllMenus();

      if (!wasOpen) {
        wrap.classList.remove("drop-up");

        // Auto-direction (skip header)
        if (!wrap.classList.contains("zd-actions-wrap-head")) {
          const rect = wrap.getBoundingClientRect();
          const menuHeight = 160; // estimated dropdown height
          const spaceBelow = window.innerHeight - rect.bottom;
          const spaceAbove = rect.top;

          // Not enough room below → fold up
          if (spaceBelow < menuHeight && spaceAbove > menuHeight) {
            wrap.classList.add("drop-up");
          }
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

    // === Click elsewhere: close any open menus ===
    closeAllMenus();
  });

  // =======================
  // Center header/filters with table width
  // =======================
  function syncClipsLayout() {
    const table = document.querySelector(".zd-clips-table-wrap");
    if (!table) return;

    const tableWidth = table.offsetWidth;

    const sections = [
      ".zd-clips-head",
      ".zd-actions",
      ".zd-bulk-actions",
      ".zd-filters-container",
      ".zd-pager",
    ];

    sections.forEach((selector) => {
      const el = document.querySelector(selector);
      if (el) {
        el.style.width = tableWidth + "px";
        el.style.marginLeft = "auto";
        el.style.marginRight = "auto";
      }
    });
  }

  document.addEventListener("DOMContentLoaded", syncClipsLayout);
  window.addEventListener("load", syncClipsLayout);
  window.addEventListener("resize", syncClipsLayout);

  const clipsPage = document.querySelector(".zd-clips-page");
  if (clipsPage) {
    const observer = new MutationObserver(syncClipsLayout);
    observer.observe(clipsPage, {
      attributes: true,
      attributeFilter: ["class"],
    });
  }

  document.addEventListener("click", (e) => {
    if (e.target.closest('.zd-columns-item input[type="checkbox"]')) {
      setTimeout(syncClipsLayout, 50);
    }
  });

  // =======================
  // Poster generation (single + bulk, AJAX)
  // =======================
  document.addEventListener("DOMContentLoaded", () => {
    const root = document.querySelector(".zd-clips-page");
    if (!root) return;

    const projectUuid = root.dataset.project || "";
    const dayUuid = root.dataset.day || "";
    const csrf = root.dataset.converterCsrf || "";

    // Generate poster for a specific clip + day
    async function generatePosterAjax(clipUuid, dayId, opts = {}) {
      const { silent = false } = opts;

      if (!projectUuid || !dayId || !csrf) {
        if (!silent)
          console.warn("Missing data for poster generation.", {
            projectUuid,
            dayId,
            csrfPresent: !!csrf,
          });
        return false;
      }

      const url =
        `/admin/projects/${encodeURIComponent(projectUuid)}` +
        `/days/${encodeURIComponent(dayId)}/converter/poster`;

      const body = new FormData();
      body.append("csrf_token", csrf);
      body.append("clip_uuid", clipUuid);
      body.append("force", "1"); // always overwrite

      try {
        const res = await fetch(url, {
          method: "POST",
          body,
        });

        const text = await res.text();
        let data = null;
        try {
          data = text ? JSON.parse(text) : null;
        } catch (e) {
          // not JSON, ignore
        }

        if (!res.ok) {
          if (!silent) {
            console.error("Poster HTTP error:", {
              status: res.status,
              body: data || text,
            });
          }
          return false;
        }

        if (!data || !data.ok) {
          if (!silent) {
            console.warn(
              "Poster failed:",
              data && data.error ? data.error : "Unknown error",
              data,
            );
          }
          return false;
        }

        // Success – update thumb in place
        if (data.href) {
          const row = document.querySelector(
            `tr[data-clip-uuid="${clipUuid}"]`,
          );
          if (row) {
            const cell = row.querySelector(".col-thumb");
            if (cell) {
              let img = cell.querySelector("img.zd-thumb");
              const src = data.href + "?v=" + Date.now(); // cache-bust

              if (!img) {
                img = document.createElement("img");
                img.className = "zd-thumb";
                img.alt = "";
                cell.innerHTML = "";
                cell.appendChild(img);
              }

              img.src = src;
            }
          }
        }

        if (data.db_warning) {
          console.warn("Poster DB warning:", data.db_warning);
        }

        return true;
      } catch (err) {
        console.error("Poster error", err);
        return false;
      }
    }

    // --- Single clip: "Generate poster" in per-row menu ---
    document.addEventListener("click", (e) => {
      const link = e.target.closest("[data-clip-poster]");
      if (!link) return;

      e.preventDefault();

      const row = link.closest("tr[data-clip-uuid]");
      const clipUuid =
        link.getAttribute("data-clip-poster") ||
        (row ? row.dataset.clipUuid : "");
      const rowDayUuid = (row && row.dataset.dayUuid) || dayUuid || "";

      if (!clipUuid || !rowDayUuid) {
        console.warn("Missing clip or day UUID for poster generation.", {
          clipUuid,
          rowDayUuid,
        });
        return;
      }

      // Use the row's day (works in All days + single-day)
      generatePosterAjax(clipUuid, rowDayUuid);
    });

    // --- Bulk: "Generate posters for selected" in header menu (silent) ---
    document.addEventListener("click", async (e) => {
      const bulkBtn = e.target.closest("[data-bulk-poster]");
      if (!bulkBtn) return;

      e.preventDefault();

      const rows = Array.from(
        document.querySelectorAll(".zd-table tbody tr.zd-selected-row"),
      );

      const jobs = rows
        .map((tr) => {
          const clipUuid = tr.dataset.clipUuid;
          const rowDayUuid = tr.dataset.dayUuid || dayUuid || "";
          if (!clipUuid || !rowDayUuid) return null;
          return { clipUuid, dayUuid: rowDayUuid };
        })
        .filter(Boolean);

      if (!jobs.length) {
        console.warn("Bulk posters: no valid clips selected.");
        return;
      }

      let ok = 0;
      let fail = 0;

      for (const job of jobs) {
        const success = await generatePosterAjax(job.clipUuid, job.dayUuid, {
          silent: true,
        });
        if (success) ok++;
        else fail++;
      }

      console.log(`Bulk posters done: ok=${ok}, failed=${fail}`);
    });
  });
})();
