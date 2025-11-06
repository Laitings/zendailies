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

  if (
    !btnToggleView ||
    !clipContainer ||
    !dayContainer ||
    !clipWrapper ||
    !dayWrapper ||
    !clipScrollInner ||
    !dayScrollInner ||
    !layout ||
    !resizer ||
    !daySwitchBtn ||
    !currentDayNode
  ) {
    return;
  }

  // Read project UUID from the page (set by PHP)
  const zdRoot = document.querySelector(".player-layout");
  const ZD_PROJECT_UUID = zdRoot?.dataset.projectUuid || "";

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

  // figure out last pane ("clips"/"days"), default to "clips"
  const restoredPane = sessionStorage.getItem(keyPane()) || "clips";

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
    // how wide is the full 3-col layout right now?
    const layoutRect = layout.getBoundingClientRect();
    const layoutTotal = layoutRect.width;

    // we reserve some space for:
    // - the resizer track (~10px plus gaps)
    // - the player column min width
    const minPlayerWidth = 400; // <- tweak if you want the player section never below this
    const resizerTrackAndGaps = 18; // 10px track + ~8px column-gap

    // sidebar can't be bigger than what's left after reserving player min width
    let maxW = layoutTotal - minPlayerWidth - resizerTrackAndGaps;

    // don't let it go negative or tiny
    if (maxW < 240) {
      maxW = 240;
    }

    return maxW;
  }

  resizer.addEventListener("mousedown", function (e) {
    dragging = true;
    startX = e.clientX;
    const cs = getComputedStyle(layout);
    startW =
      parseInt(cs.getPropertyValue("--clipcol-width")) || defaults[getMode()];
    document.body.style.cursor = "col-resize";
    document.body.style.userSelect = "none";
  });

  window.addEventListener("mousemove", function (e) {
    if (!dragging) return;

    const dx = e.clientX - startX;
    let nw = startW + dx;

    // clamp between 240 and dynamic max
    const maxAllowed = computeMaxAllowedWidth();
    if (nw < 240) nw = 240;
    if (nw > maxAllowed) nw = maxAllowed;

    layout.style.setProperty("--clipcol-width", nw + "px");
  });

  window.addEventListener("mouseup", function () {
    if (!dragging) return;
    dragging = false;
    document.body.style.cursor = "";
    document.body.style.userSelect = "";

    // after letting go, snap again to be safe and persist
    const cs = getComputedStyle(layout);
    let finalW =
      parseInt(cs.getPropertyValue("--clipcol-width")) || defaults[getMode()];
    const maxAllowed = computeMaxAllowedWidth();
    if (finalW < 240) finalW = 240;
    if (finalW > maxAllowed) finalW = maxAllowed;

    layout.style.setProperty("--clipcol-width", finalW + "px");
    sessionStorage.setItem(keyW(getMode()), String(finalW));
  });

  // double-click divider to reset width for current mode
  resizer.addEventListener("dblclick", function () {
    const m = getMode();
    layout.style.setProperty("--clipcol-width", defaults[m] + "px");
    sessionStorage.setItem(keyW(m), String(defaults[m]));
  });
});

// ---- Player timecode overlay (with drop-frame support) ----
(function () {
  const vid = document.getElementById("zdVideo");
  const chip = document.getElementById("tcOverlay");
  if (!vid || !chip) return;

  const fps = parseFloat(vid.dataset.fps || "25") || 25;
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
