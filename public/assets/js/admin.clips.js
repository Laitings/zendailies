// admin.clips.js  — Clips index page logic

(function () {
  const root = document.querySelector(".zd-clips-page");
  if (!root) return;

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

  // Live filter: AJAX-refresh table while keeping focus & caret
  const filterForm = root.querySelector("form.zd-filter-form");
  const filterControls = root.querySelectorAll(
    ".zd-filters input.zd-input, .zd-filters select.zd-select"
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
          "button.icon-btn, a.icon-btn, a[href], button[data-action], button.star-toggle"
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
        projectUuid
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

  // ---- Poster (single)
  document.addEventListener("click", async (ev) => {
    const btn = ev.target.closest('button.icon-btn[data-action="poster"]');
    if (!btn) return;

    const clipUuid = btn.getAttribute("data-clip");
    const rowEl = document.querySelector(`#clip-${clipUuid}`);
    const rowDayUuid = rowEl?.getAttribute("data-day-uuid") || dayUuid || "";

    const baseUrl = `/admin/projects/${encodeURIComponent(
      projectUuid
    )}/days/${encodeURIComponent(rowDayUuid)}/converter/`;
    const endpoint = baseUrl + "poster";

    btn.disabled = true;
    const originalTitle = btn.title;
    btn.title = "Working…";

    try {
      const body = new URLSearchParams({
        csrf_token: converterCsrf,
        clip_uuid: clipUuid,
        force: "1",
      });

      const resp = await fetch(endpoint, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body,
      });
      const json = await resp.json();

      if (resp.ok && json.ok) {
        btn.title = "Poster OK";
        if (json.href) {
          const thumbCellImg = rowEl?.querySelector('[data-field="thumb"] img');
          if (thumbCellImg) {
            thumbCellImg.src = json.href + "?v=" + Date.now();
          } else {
            const thumbDiv = rowEl?.querySelector(
              '[data-field="thumb"] .zd-thumb'
            );
            if (thumbDiv)
              thumbDiv.outerHTML = `<img class="zd-thumb" src="${
                json.href
              }?v=${Date.now()}" alt="">`;
          }
        }
        initDurationsOnPage();
        renderTotalRuntimeFromDayAttribute();
        renderSelectedRuntime();
      } else {
        btn.title = "Poster ERR";
        alert(json.error || json.message || "Failed poster");
      }
    } catch (err) {
      console.error(err);
      btn.title = "Poster ERR";
      alert("Network/JS error during poster");
    } finally {
      btn.disabled = false;
      setTimeout(() => (btn.title = originalTitle), 2000);
    }
  });

  // ---- Bulk poster
  async function runBulkPosterAction() {
    const baseUrl = `/admin/projects/${encodeURIComponent(
      projectUuid
    )}/days/${encodeURIComponent(dayUuid)}/converter/`;
    const endpoint = baseUrl + "poster";

    for (const clipUuid of selectedRows) {
      const rowEl = document.querySelector(`#clip-${clipUuid}`);
      try {
        const body = new URLSearchParams({
          csrf_token: converterCsrf,
          clip_uuid: clipUuid,
          force: "1",
        });
        const resp = await fetch(endpoint, {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body,
        });
        const json = await resp.json();
        if (resp.ok && json.ok) {
          if (json.href) {
            const thumbCellImg = rowEl?.querySelector(
              '[data-field="thumb"] img'
            );
            if (thumbCellImg) {
              thumbCellImg.src = json.href + "?v=" + Date.now();
            } else {
              const thumbDiv = rowEl?.querySelector(
                '[data-field="thumb"] .zd-thumb'
              );
              if (thumbDiv)
                thumbDiv.outerHTML = `<img class="zd-thumb" src="${
                  json.href
                }?v=${Date.now()}" alt="">`;
            }
          }
        } else {
          console.error("Bulk poster failed for", clipUuid, json);
          alert(
            "Problem with poster on " +
              clipUuid +
              ": " +
              (json.error || json.message || "unknown error")
          );
        }
      } catch (err) {
        console.error("Bulk poster error for", clipUuid, err);
        alert("Network/JS error during bulk poster on " + clipUuid);
      }
    }

    initDurationsOnPage();
    renderTotalRuntimeFromDayAttribute();
    renderSelectedRuntime();
  }

  if (bulkPosterBtn) {
    bulkPosterBtn.addEventListener("click", runBulkPosterAction);
  }

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
            projectUuid
          )}/days/${encodeURIComponent(rowDayUuid)}/clips/${encodeURIComponent(
            clipUuid
          )}/quick`,
          {
            method: "POST",
            headers: {
              "Content-Type": "application/x-www-form-urlencoded",
            },
            body,
          }
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
            projectUuid
          )}/days/${encodeURIComponent(rowDayUuid)}/clips/${encodeURIComponent(
            clipUuid
          )}/select`,
          {
            method: "POST",
            headers: {
              "Content-Type": "application/x-www-form-urlencoded",
            },
            body,
          }
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
      'input[name="scene"], input[name="slate"], input[name="take"], input[name="text"]'
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
      'select[name="camera"], select[name="rating"], select[name="select"]'
    );

    liveSelects.forEach((sel) => {
      // Update immediately on change (no debounce needed for dropdowns)
      sel.addEventListener("change", updateTableViaAjax);
    });
  }
})();
