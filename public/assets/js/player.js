document.addEventListener("DOMContentLoaded", function () {
  // --- DOM refs ---
  const btnToggleView = document.getElementById("viewToggleBtn");
  const iconToggleView = document.getElementById("viewToggleIcon");

  // SCROLLABLE inners (the elements that actually have overflow-y:auto)
  const clipScrollInner = document.getElementById("clipScrollInner");
  const dayScrollInner = document.getElementById("dayScrollInner");

  // LIST roots (just content, not scroll)
  const clipContainer = document.getElementById("clipListContainer");
  const dayContainer = document.getElementById("dayListContainer");

  // OUTER wrappers (shown/hidden as panes)
  const clipWrapper = document.querySelector(".clipScrollOuter");
  const dayWrapper = document.querySelector(".dayScrollOuter");

  const daySwitchBtn = document.getElementById("zd-day-switch-btn");
  const currentDayNode = document.getElementById("zd-current-day-label");
  const headerSlash = document.getElementById("zd-header-slash");
  const headerClips = document.getElementById("zd-header-clips");

  const layout = document.querySelector(".player-layout");
  const resizer = document.getElementById("sidebarResizer");
  const innerResizer = document.getElementById("innerResizer");

  const mainSection = document.querySelector("section.player-main"); // inner grid in theater
  const isTheater = () => layout.classList.contains("is-theater");

  // Which CSS variable are we driving right now?
  function currentVarName() {
    return isTheater() ? "--meta-width" : "--clipcol-width";
  }

  const theaterBtn = document.getElementById("theaterToggleBtn");
  const theaterIcon = document.getElementById("theaterToggleIcon");

  if (
    !btnToggleView ||
    !clipContainer ||
    !dayContainer ||
    !clipWrapper ||
    !dayWrapper ||
    !clipScrollInner ||
    !dayScrollInner ||
    !layout ||
    !daySwitchBtn ||
    !currentDayNode
  ) {
    return;
  }

  // Read project UUID from the page (set by PHP)
  const zdRoot = document.querySelector(".player-layout");
  const ZD_PROJECT_UUID = zdRoot?.dataset.projectUuid || "";

  const THEATER_KEY = "playerTheaterMode";
  function setTheater(on) {
    if (!zdRoot) return;
    zdRoot.classList.toggle("is-theater", !!on);
    // Swap header icon only if it exists
    if (theaterIcon) {
      theaterIcon.src = on
        ? "/assets/icons/theater-exit.svg"
        : "/assets/icons/theater.svg";
    }
  }

  // Restore from session
  const theaterSaved = sessionStorage.getItem(THEATER_KEY);
  setTheater(theaterSaved === "1");

  // Wire the button
  if (theaterBtn) {
    theaterBtn.addEventListener("click", () => {
      const nowOn = !zdRoot.classList.contains("is-theater");
      setTheater(nowOn);
      sessionStorage.setItem(THEATER_KEY, nowOn ? "1" : "0");
    });
  }

  // Day grid: navigate when a day is clicked
  document.addEventListener("click", (e) => {
    const btn = e.target.closest(".day-item");
    if (!btn) return;

    const dayUuid = btn.dataset.dayUuid;
    if (!ZD_PROJECT_UUID || !dayUuid) return;

    // Build server route purely in JS (no PHP in .js files!)
    const url = `/admin/projects/${encodeURIComponent(
      ZD_PROJECT_UUID
    )}/days/${encodeURIComponent(dayUuid)}/player`;
    window.location.assign(url);
  });

  // -------------------------------------------------
  // STATE
  // -------------------------------------------------
  // Which pane is currently visible in the sidebar: 'clips' or 'days'
  let activePane = "clips";

  // Remember the last chosen day label (what we show before "/ Clips")
  let lastSelectedDayLabel = currentDayNode.textContent.trim();

  // defaults per mode for sidebar width
  const defaults = {
    list: 360,
    grid: 720,
  };

  const keyMode = "clipViewMode"; // persist list/grid choice
  const keyW = (m) => `clipcolWidth:${m}`;

  const basePath = location.pathname.replace(/\/player\/[^/]+$/, "/player");
  // we now scope scroll state by pane ('clips' or 'days') + mode ('list'/'grid')
  const keyScroll = (m, pane) => `clipListScroll:${pane}:${m}:${basePath}`;

  // remember which pane was active when we navigated away
  const keyPane = (pane) => `activePane:${basePath}`;

  // helper to save pane + mode before leaving page
  function persistStateBeforeNav(pane, mode) {
    sessionStorage.setItem(keyPane(), pane);
    sessionStorage.setItem(keyMode, mode); // you already do this elsewhere too but repeat here so it's fresh
    saveScroll(mode); // save scroll for that pane/mode
  }

  // -------------------------------------------------
  // HELPERS
  // -------------------------------------------------

  function getMode() {
    return clipContainer.classList.contains("grid-view") ? "grid" : "list";
  }

  // Update header wording depending on which pane is visible
  function setHeaderForPane() {
    if (activePane === "days") {
      // Show "Days", hide "/ Clips"
      currentDayNode.textContent = "Days";
      headerSlash.style.display = "none";
      headerClips.style.display = "none";
    } else {
      // Show lastSelectedDayLabel + "/ Clips"
      currentDayNode.textContent = lastSelectedDayLabel || "Current Day";
      headerSlash.style.display = "";
      headerClips.style.display = "";
    }
  }

  function setToggleIcon(mode) {
    // mode is CURRENT mode, so button shows TARGET
    const isGrid = mode === "grid";
    iconToggleView.src = isGrid
      ? "/assets/icons/list.svg"
      : "/assets/icons/grid.svg";
    btnToggleView.title = isGrid ? "Switch to list" : "Switch to grid";
    btnToggleView.setAttribute("aria-label", btnToggleView.title);
  }

  function getScrollElForPane(paneName) {
    // the actual scrolling elements
    return paneName === "clips" ? clipScrollInner : dayScrollInner;
  }

  function saveScroll(mode) {
    const pane = activePane;
    const scEl = getScrollElForPane(pane);
    sessionStorage.setItem(keyScroll(mode, pane), String(scEl.scrollTop));
  }

  function restoreScroll(mode) {
    const pane = activePane;
    const scEl = getScrollElForPane(pane);
    const saved = sessionStorage.getItem(keyScroll(mode, pane));
    if (!saved) return;
    const y = parseInt(saved, 10) || 0;
    requestAnimationFrame(() => {
      scEl.scrollTop = y;
    });
  }

  // adjust day items for list vs grid (layout + which label is visible)
  function layoutDaysForMode(mode) {
    const dayItems = dayContainer.querySelectorAll(".day-item");
    dayItems.forEach((card) => {
      const overlay = card.querySelector(".day-overlay-label");
      const rowtext = card.querySelector(".day-rowtext");
      const thumbWrap = card.querySelector(".day-thumb");

      if (mode === "grid") {
        // GRID MODE: cards behave like tiles inside CSS grid
        card.style.display = "block";
        card.style.gridTemplateColumns = "";
        card.style.alignItems = "";
        card.style.gap = "";

        if (thumbWrap) {
          thumbWrap.style.width = "100%";
          thumbWrap.style.borderBottom = "1px solid var(--border)";
        }

        if (overlay) overlay.style.display = ""; // show centered overlay
        if (rowtext) rowtext.style.display = "none"; // hide row title

        const overlayMeta = card.querySelector(".day-overlay-meta");
        const rowMeta = card.querySelector(".day-rowmeta");
        if (overlayMeta) overlayMeta.style.display = ""; // show on thumb
        if (rowMeta) rowMeta.style.display = "none"; // hide row meta
      } else {
        // LIST MODE: cards behave like 2-col rows
        card.style.display = "grid";
        card.style.gridTemplateColumns = "120px 1fr";
        card.style.alignItems = "center";
        card.style.gap = "10px";

        if (thumbWrap) {
          thumbWrap.style.width = "120px";
          thumbWrap.style.borderBottom = "none";
        }

        if (overlay) overlay.style.display = "none"; // hide overlay in list rows
        if (rowtext) rowtext.style.display = "block"; // show row title

        const overlayMeta = card.querySelector(".day-overlay-meta");
        const rowMeta = card.querySelector(".day-rowmeta");
        if (overlayMeta) overlayMeta.style.display = "none"; // hide on thumb
        if (rowMeta) rowMeta.style.display = "block"; // show under title
      }
    });
  }

  // Switch list/grid styling for both containers
  function applyMode(mode) {
    // save scroll before we flip classes
    saveScroll(getMode());

    clipContainer.classList.remove("list-view", "grid-view");
    dayContainer.classList.remove("list-view", "grid-view");

    clipContainer.classList.add(mode + "-view");
    dayContainer.classList.add(mode + "-view");

    layoutDaysForMode(mode);

    // sidebar width memory based on mode
    const savedW = sessionStorage.getItem(keyW(mode));
    const width = parseInt(savedW || defaults[mode], 10);
    layout.style.setProperty("--clipcol-width", width + "px");

    setToggleIcon(mode);
    sessionStorage.setItem(keyMode, mode);

    // restore scroll on the now-active pane
    restoreScroll(mode);
  }

  // Actually show one wrapper and hide the other
  function showPane(paneName) {
    // save scroll of current pane/mode before leaving it
    saveScroll(getMode());

    activePane = paneName;

    if (paneName === "clips") {
      clipWrapper.style.display = "";
      dayWrapper.style.display = "none";
    } else {
      clipWrapper.style.display = "none";
      dayWrapper.style.display = "";
    }

    setHeaderForPane();
    restoreScroll(getMode());
  }

  // -------------------------------------------------
  // INIT
  // -------------------------------------------------

  // figure out last mode ("list"/"grid")
  const restoredMode =
    sessionStorage.getItem(keyMode) === "grid" ? "grid" : "list";

  // allow URL to force initial pane (e.g. ?pane=days)
  const urlPaneParam = new URLSearchParams(location.search).get("pane");
  const forcedPane =
    urlPaneParam && /^(clips|days)$/i.test(urlPaneParam)
      ? urlPaneParam.toLowerCase()
      : null;

  // figure out last pane ("clips"/"days"), default to "clips"; URL wins if present
  const restoredPane =
    forcedPane || sessionStorage.getItem(keyPane()) || "clips";

  // if URL forced it, persist so the back/forward flow keeps it
  if (forcedPane) {
    sessionStorage.setItem(keyPane(), restoredPane);
  }

  // Apply mode classes to both containers first
  clipContainer.classList.remove("list-view", "grid-view");
  dayContainer.classList.remove("list-view", "grid-view");

  clipContainer.classList.add(restoredMode + "-view");
  dayContainer.classList.add(restoredMode + "-view");

  // Make sure day cards layout matches the mode
  layoutDaysForMode(restoredMode);

  // Set toggle icon to reflect current mode
  setToggleIcon(restoredMode);

  // Restore sidebar width for this mode
  const savedInitialW = sessionStorage.getItem(keyW(restoredMode));
  layout.style.setProperty(
    "--clipcol-width",
    (savedInitialW || defaults[restoredMode]) + "px"
  );

  // Now set activePane manually (instead of calling showPane(), which would
  // call saveScroll/restoreScroll again in a weird order)
  activePane = restoredPane;

  // Reveal the correct wrapper based on restoredPane
  if (restoredPane === "clips") {
    clipWrapper.style.display = "";
    dayWrapper.style.display = "none";
  } else {
    clipWrapper.style.display = "none";
    dayWrapper.style.display = "";
  }

  // Update header text ("DAY 01 / Clips" vs "Days")
  setHeaderForPane();

  // Finally, restore scroll for that pane+mode
  restoreScroll(restoredMode);

  // -------------------------------------------------
  // EVENTS
  // -------------------------------------------------

  // Click the "(DAY NAME)" header button.
  // If we're in clips -> go to days (header becomes "Days")
  // If we're in days  -> go back to clips (header shows lastSelectedDayLabel)
  daySwitchBtn.addEventListener("click", function () {
    if (activePane === "clips") {
      showPane("days");
    } else {
      showPane("clips");
    }
  });

  // Toggle list/grid view button
  btnToggleView.addEventListener("click", function () {
    const next = getMode() === "grid" ? "list" : "grid";
    applyMode(next);
  });

  // Clicking a clip still navigates away to that clip's /player/{clip_uuid}
  clipContainer.addEventListener("click", function (e) {
    const a = e.target.closest("a.clip-item");
    if (!a) return;

    // before we navigate away, persist pane/mode/scroll
    const modeNow = getMode(); // "list" or "grid"
    persistStateBeforeNav(activePane, modeNow);

    // now follow the link normally
    // (letting the browser navigate via href is fine)
  });

  // drag-to-resize logic for sidebar width
  let dragging = false,
    startX = 0,
    startW = 0;

  function computeMaxAllowedWidth() {
    if (!isTheater()) {
      // NORMAL: resizer controls the LEFT sidebar (clip/day list)
      const layoutRect = layout.getBoundingClientRect();
      const layoutTotal = layoutRect.width;
      const minPlayerWidth = 400;
      const resizerTrackAndGaps = 18;
      let maxW = layoutTotal - minPlayerWidth - resizerTrackAndGaps;
      if (maxW < 240) maxW = 240;
      return maxW;
    } else {
      // THEATER: measure the whole grid (mainSection is display: contents)
      const total = layout.getBoundingClientRect().width;
      const minVideoWidth = 560; // keep the video comfortably wide
      const resizerTrack = 18;
      let maxW = total - minVideoWidth - resizerTrack;
      if (maxW < 280) maxW = 280;
      return maxW;
    }
  }

  [resizer, innerResizer].forEach((el) =>
    el?.addEventListener("mousedown", function (e) {
      dragging = true;
      startX = e.clientX;

      const cs = getComputedStyle(layout);
      const varName = currentVarName(); // "--clipcol-width" (normal) or "--meta-width" (theater)
      const fallback = isTheater() ? 420 : defaults[getMode()];
      startW = parseInt(cs.getPropertyValue(varName)) || fallback;

      document.body.style.cursor = "col-resize";
      document.body.style.userSelect = "none";
    })
  );

  window.addEventListener("mousemove", function (e) {
    if (!dragging) return;

    const dx = e.clientX - startX;
    const delta = isTheater() ? -dx : dx; // invert in theater
    let nw = startW + delta;

    // clamp between 240 and dynamic max
    const maxAllowed = computeMaxAllowedWidth();
    if (nw < 240) nw = 240;
    if (nw > maxAllowed) nw = maxAllowed;

    const varName = currentVarName();
    // Always set on the grid root, because CSS reads it on .player-layout
    layout.style.setProperty(varName, nw + "px");
  });

  window.addEventListener("mouseup", function () {
    if (!dragging) return;
    dragging = false;
    document.body.style.cursor = "";
    document.body.style.userSelect = "";

    document.addEventListener("keydown", (e) => {
      if (
        e.key.toLowerCase() === "t" &&
        !e.altKey &&
        !e.ctrlKey &&
        !e.metaKey
      ) {
        theaterBtn?.click();
      }
    });

    // after letting go, snap again to be safe and persist
    const varName = currentVarName();
    const cs = getComputedStyle(layout);
    let finalW = parseInt(cs.getPropertyValue(varName)) || defaults[getMode()];
    const maxAllowed = computeMaxAllowedWidth();
    if (finalW < (isTheater() ? 280 : 240)) finalW = isTheater() ? 280 : 240;
    if (finalW > maxAllowed) finalW = maxAllowed;
    layout.style.setProperty(varName, finalW + "px");

    // persist per-mode key you already use; ok to reuse the same keys
    sessionStorage.setItem(keyW(getMode()), String(finalW));
  });

  // double-click divider to reset width for current mode
  // double-click divider to reset width for current mode
  resizer.addEventListener("dblclick", function () {
    const varName = currentVarName(); // "--clipcol-width" or "--meta-width"
    const def = isTheater() ? 420 : defaults[getMode()];
    layout.style.setProperty(varName, def + "px");
    // Persist only the clip column width (we don't currently store meta width):
    if (!isTheater()) {
      sessionStorage.setItem(keyW(getMode()), String(def));
    }
  });

  // -------------------------------------------------
  // Metadata <details> open/close persistence
  // -------------------------------------------------
  const metaSections = document.querySelectorAll(
    "details.zd-metadata-group[data-meta-section]"
  );

  metaSections.forEach((det) => {
    const section = det.dataset.metaSection;
    if (!section) return;

    const key = `playerMetaOpen:${section}`;

    // Restore saved state (if any)
    const saved = sessionStorage.getItem(key);
    if (saved === "1") {
      det.open = true;
    } else if (saved === "0") {
      det.open = false;
    }

    // On toggle, persist new state
    det.addEventListener("toggle", () => {
      sessionStorage.setItem(key, det.open ? "1" : "0");
    });
  });
});

// ---- Player timecode overlay (with drop-frame support) ----
(function () {
  const vid = document.getElementById("zdVideo");
  const chip = document.getElementById("tcChip");
  if (!vid || !chip) return;

  const fpsNum = Number(vid.dataset.fpsnum) || 0;
  const fpsDen = Number(vid.dataset.fpsden) || 0;
  const fps =
    fpsNum > 0 && fpsDen > 0
      ? fpsNum / fpsDen
      : Number((vid.dataset.fps || "").replace(",", ".")) || 25;
  const startTC = (vid.dataset.tcStart || "00:00:00:00").trim();

  // Auto-detect drop-frame: true for ≈29.97 or ≈59.94
  const drop =
    Math.abs(fps - 29.97) < 0.02 || Math.abs(fps - 59.94) < 0.02 ? true : false;

  // ---------- Helpers ----------
  const pad2 = (n) => String(n).padStart(2, "0");

  function dropCountPerMinute(fps) {
    const f = Math.round(fps);
    return Math.round(f / 15); // 2 for 29.97, 4 for 59.94
  }

  // ---- Non-drop parse/format ----
  function tcToFrames_ND(tc, fps) {
    const m = /^(\d{2}):(\d{2}):(\d{2})[:;](\d{2})$/.exec(tc);
    if (!m) return 0;
    const f = Math.round(fps);
    const hh = +m[1],
      mm = +m[2],
      ss = +m[3],
      ff = +m[4];
    return ((hh * 60 + mm) * 60 + ss) * f + ff;
  }

  function framesToTC_ND(totalFrames, fps) {
    const f = Math.round(fps);
    let frames = Math.max(0, Math.round(totalFrames));
    const ff = frames % f;
    frames = (frames - ff) / f;
    const ss = frames % 60;
    frames = (frames - ss) / 60;
    const mm = frames % 60;
    const hh = (frames - mm) / 60;
    return `${pad2(hh)}:${pad2(mm)}:${pad2(ss)}:${pad2(ff)}`;
  }

  // ---- Drop-frame parse/format ----
  function tcToFrames_DF(tc, fps) {
    const f = Math.round(fps);
    const df = dropCountPerMinute(fps);
    const m = /^(\d{2}):(\d{2}):(\d{2})[:;](\d{2})$/.exec(tc);
    if (!m) return 0;
    const hh = +m[1],
      mm = +m[2],
      ss = +m[3],
      ff = +m[4];
    const totalMinutes = hh * 60 + mm;
    const dropped = df * (totalMinutes - Math.floor(totalMinutes / 10));
    return (hh * 3600 + mm * 60 + ss) * f + ff - dropped;
  }

  function framesToTC_DF(totalFrames, fps) {
    const f = Math.round(fps);
    const df = dropCountPerMinute(fps);
    const framesPerHour = f * 60 * 60;
    const framesPer10Min = f * 60 * 10;
    const framesPerMin = f * 60;

    let frames = Math.max(0, Math.round(totalFrames));

    // compute adjustment for dropped frames
    const d =
      Math.floor(frames / (framesPer10Min - df * 9)) * 9 * df +
      Math.max(
        0,
        Math.floor((frames % (framesPer10Min - df * 9)) / (framesPerMin - df))
      ) *
        df;

    let adj = frames + d;

    const hh = Math.floor(adj / framesPerHour);
    adj -= hh * framesPerHour;

    const mm = Math.floor(adj / framesPerMin);
    adj -= mm * framesPerMin;

    const ss = Math.floor(adj / f);
    const ff = adj % f;

    return `${pad2(hh)}:${pad2(mm)}:${pad2(ss)};${pad2(ff)}`;
  }

  // ---------- Init ----------
  const startFrames = drop
    ? tcToFrames_DF(startTC, fps)
    : tcToFrames_ND(startTC, fps);

  // ---------- Per-frame update ----------
  const useRVFC = "requestVideoFrameCallback" in HTMLVideoElement.prototype;

  function renderFrame(timeSeconds) {
    const curFrames = startFrames + Math.round((timeSeconds || 0) * fps);
    chip.textContent = drop
      ? framesToTC_DF(curFrames, fps)
      : framesToTC_ND(curFrames, fps);
  }

  if (useRVFC) {
    const frameLoop = (now, metadata) => {
      const t =
        metadata && typeof metadata.mediaTime === "number"
          ? metadata.mediaTime
          : vid.currentTime || 0;
      renderFrame(t);
      if (!vid.ended) vid.requestVideoFrameCallback(frameLoop);
    };
    vid.requestVideoFrameCallback(frameLoop);
  } else {
    let rafId = 0,
      playing = false;

    const tick = () => {
      if (!playing) return;
      renderFrame(vid.currentTime || 0);
      rafId = requestAnimationFrame(tick);
    };

    vid.addEventListener("play", () => {
      playing = true;
      cancelAnimationFrame(rafId);
      tick();
    });
    vid.addEventListener("pause", () => {
      playing = false;
      cancelAnimationFrame(rafId);
    });
    vid.addEventListener("ended", () => {
      playing = false;
      cancelAnimationFrame(rafId);
    });

    vid.addEventListener("seeked", () => {
      if (!playing) renderFrame(vid.currentTime || 0);
    });
    vid.addEventListener("loadedmetadata", () => {
      renderFrame(vid.currentTime || 0);
    });

    // initial paint
    renderFrame(0);
  }
})();

// === Clip / Day list sorting: mode + direction ===
document.addEventListener("DOMContentLoaded", () => {
  const clipContainer = document.getElementById("clipListContainer");
  const dayContainer = document.getElementById("dayListContainer");
  const sortModeEl = document.getElementById("clipSortMode");
  const sortDirBtn = document.getElementById("sortDirBtn");
  const sortGroup = document.getElementById("clipSortGroup");
  const daySwitchBtn = document.getElementById("zd-day-switch-btn");

  if (!sortDirBtn) return; // arrow must exist

  const getClipItems = () =>
    clipContainer
      ? Array.from(clipContainer.querySelectorAll(".clip-item"))
      : [];

  const getDayItems = () =>
    dayContainer ? Array.from(dayContainer.querySelectorAll(".day-item")) : [];

  // Detect whether we're in "day mode" (day list visible)
  const isDayMode = () => {
    const dayOuter = document.querySelector(".dayScrollOuter");
    if (!dayOuter) return false;
    // display: none => clips mode, otherwise day mode
    return dayOuter.style.display !== "none";
  };

  // Show/hide the sort dropdown group based on mode
  const updateSortVisibility = () => {
    if (!sortGroup) return;
    if (isDayMode()) {
      sortGroup.style.display = "none";
    } else {
      sortGroup.style.display = "flex";
    }
  };

  // Decide default direction for each mode (clips only)
  const defaultDirForMode = (mode) => {
    if (mode === "select" || mode === "comments") return "desc";
    return "asc"; // scene/name
  };

  let sortDir = sortDirBtn.getAttribute("data-dir") || "asc";

  function sortLists() {
    // If day mode → sort days by label/text only, using current direction
    if (isDayMode()) {
      const items = getDayItems();
      if (!items.length) return;

      items.sort((a, b) => {
        const aLabel = (a.dataset.dayLabel || "").toLowerCase();
        const bLabel = (b.dataset.dayLabel || "").toLowerCase();
        const cmp = aLabel.localeCompare(bLabel);
        return sortDir === "asc" ? cmp : -cmp;
      });

      items.forEach((el) => dayContainer.appendChild(el));
      return;
    }

    // Clip mode
    if (!clipContainer || !sortModeEl) return;

    const mode = sortModeEl.value || "scene";
    const items = getClipItems();
    if (!items.length) return;

    const compare = (a, b) => {
      const aData = a.dataset;
      const bData = b.dataset;
      let aVal;
      let bVal;

      switch (mode) {
        case "name":
          aVal = (aData.filename || "").toLowerCase();
          bVal = (bData.filename || "").toLowerCase();
          break;

        case "select":
          aVal = Number(aData.isSelect || 0);
          bVal = Number(bData.isSelect || 0);
          break;

        case "comments":
          aVal = Number(aData.commentCount || 0);
          bVal = Number(bData.commentCount || 0);
          break;

        case "scene":
        default:
          aVal = (aData.label || aData.filename || "").toLowerCase();
          bVal = (bData.label || bData.filename || "").toLowerCase();
          break;
      }

      let result;
      if (typeof aVal === "string" || typeof bVal === "string") {
        result = String(aVal).localeCompare(String(bVal));
      } else {
        result = aVal - bVal;
      }

      return sortDir === "asc" ? result : -result;
    };

    items.sort(compare);
    items.forEach((el) => clipContainer.appendChild(el));
  }

  // Change sort mode (clips only)
  if (sortModeEl) {
    sortModeEl.addEventListener("change", () => {
      const mode = sortModeEl.value || "scene";
      sortDir = defaultDirForMode(mode);
      sortDirBtn.setAttribute("data-dir", sortDir);
      sortLists();
    });
  }

  // Manual direction toggle (works for both clips and days)
  sortDirBtn.addEventListener("click", () => {
    sortDir = sortDir === "asc" ? "desc" : "asc";
    sortDirBtn.setAttribute("data-dir", sortDir);
    sortLists();
  });

  // Hook into day/clip toggle button to update visibility after it runs
  if (daySwitchBtn) {
    daySwitchBtn.addEventListener("click", () => {
      // Let existing handler run first, then adjust
      setTimeout(() => {
        updateSortVisibility();
        // Optional: when switching back to clips, re-apply current sort
        sortLists();
      }, 0);
    });
  }

  // === Initial default when opening a day (clip mode) ===
  const clipItems = getClipItems();
  const hasScene = clipItems.some((el) => {
    const label = (el.dataset.label || "").trim();
    return label !== "";
  });

  if (sortModeEl) {
    const initialMode = hasScene ? "scene" : "name";

    const hasInitialOption = Array.from(sortModeEl.options).some(
      (opt) => opt.value === initialMode
    );
    if (hasInitialOption) {
      sortModeEl.value = initialMode;
    }

    sortDir = defaultDirForMode(sortModeEl.value || initialMode);
    sortDirBtn.setAttribute("data-dir", sortDir);
  }

  // Initial visibility + initial sort
  updateSortVisibility();
  sortLists();
});
