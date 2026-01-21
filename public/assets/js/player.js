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
  const sceneContainer = document.getElementById("sceneListContainer");

  // OUTER wrappers (shown/hidden as panes)
  const clipWrapper = document.querySelector(".clipScrollOuter");
  const dayWrapper = document.querySelector(".dayScrollOuter");
  const sceneWrapper = document.querySelector(".sceneScrollOuter");

  const daySwitchBtn = document.getElementById("zd-day-switch-btn");
  const currentDayNode = document.getElementById("zd-current-day-label");

  const layout = document.querySelector(".player-layout");
  const resizer = document.getElementById("sidebarResizer");
  const innerResizer = document.getElementById("innerResizer");

  const mainSection = document.querySelector("section.player-main");
  const isTheater = () => layout.classList.contains("is-theater");

  function currentVarName() {
    return isTheater() ? "--meta-width" : "--clipcol-width";
  }

  const theaterBtn = document.getElementById("btnTheater");
  const theaterIcon = document.querySelector("#btnTheater .theater-icon");

  if (
    !btnToggleView ||
    !clipContainer ||
    !dayContainer ||
    !clipWrapper ||
    !dayWrapper ||
    !clipScrollInner ||
    !dayScrollInner ||
    !layout ||
    !currentDayNode
  ) {
    return;
  }

  // Read project UUID from the page (set by PHP)
  const zdRoot = document.querySelector(".player-layout");
  const ZD_PROJECT_UUID = zdRoot?.dataset.projectUuid || "";
  const ZD_DAY_UUID = zdRoot?.dataset.dayUuid || "";
  const ZD_CURRENT_CLIP = zdRoot?.dataset.clipUuid || "";

  const THEATER_KEY = "playerTheaterMode";
  function setTheater(on) {
    if (!zdRoot) return;
    zdRoot.classList.toggle("is-theater", !!on);

    // Update icon state
    const enterIcon = document.querySelector(
      '#btnTheater .theater-icon[data-state="enter"]',
    );
    const exitIcon = document.querySelector(
      '#btnTheater .theater-icon[data-state="exit"]',
    );
    if (enterIcon && exitIcon) {
      enterIcon.style.display = on ? "none" : "";
      exitIcon.style.display = on ? "" : "none";
    }
  }

  const theaterSaved = sessionStorage.getItem(THEATER_KEY);
  setTheater(theaterSaved === "1");

  if (theaterBtn) {
    theaterBtn.addEventListener("click", () => {
      const nowOn = !zdRoot.classList.contains("is-theater");
      setTheater(nowOn);
      sessionStorage.setItem(THEATER_KEY, nowOn ? "1" : "0");
    });
  }

  // -------------------------------------------------
  // STATE
  // -------------------------------------------------
  let activePane = "clips";
  let lastSelectedDayLabel = currentDayNode.textContent.trim();

  const defaults = {
    list: 360,
    grid: 720,
  };

  const keyMode = "clipViewMode";
  const keyW = (m) => `clipcolWidth:${m}`;

  const basePath = location.pathname.replace(/\/player\/[^/]+$/, "/player");
  const keyScroll = (m, pane) => `clipListScroll:${pane}:${m}:${basePath}`;
  const keyPane = () => `activePane:${basePath}`;

  function persistStateBeforeNav(pane, mode) {
    sessionStorage.setItem(keyPane(), pane);
    sessionStorage.setItem(keyMode, mode);
    saveScroll(mode);
  }

  // -------------------------------------------------
  // HELPERS
  // -------------------------------------------------

  function getMode() {
    return clipContainer.classList.contains("grid-view") ? "grid" : "list";
  }

  function setToggleIcon(mode) {
    const isGrid = mode === "grid";
    iconToggleView.src = isGrid
      ? "/assets/icons/list.svg"
      : "/assets/icons/grid.svg";
    btnToggleView.title = isGrid ? "Switch to list" : "Switch to grid";
    btnToggleView.setAttribute("aria-label", btnToggleView.title);
  }

  function getScrollElForPane(paneName) {
    if (paneName === "scenes") {
      return document.getElementById("sceneScrollInner");
    }
    return paneName === "clips" ? clipScrollInner : dayScrollInner;
  }

  function saveScroll(mode) {
    const pane = activePane;
    const scEl = getScrollElForPane(pane);
    if (scEl) {
      sessionStorage.setItem(keyScroll(mode, pane), String(scEl.scrollTop));
    }
  }

  function restoreScroll(mode) {
    const pane = activePane;
    const scEl = getScrollElForPane(pane);
    if (!scEl) return;

    // 1. First, try to restore the exact pixel position from sessionStorage
    const saved = sessionStorage.getItem(keyScroll(mode, pane));
    if (saved) {
      scEl.scrollTop = parseInt(saved, 10) || 0;
    }

    // 2. Second, if we are looking at clips, make sure the active clip is visible
    if (pane === "clips") {
      requestAnimationFrame(() => {
        const activeClip = scEl.querySelector(".clip-item.is-active");
        if (activeClip) {
          activeClip.scrollIntoView({
            behavior: "instant", // Use instant so it doesn't "slide" on load
            block: "nearest", // Only scrolls if it's actually off-screen
          });
        }
      });
    }
  }

  function layoutModeForCards(mode) {
    const cards = document.querySelectorAll(".day-item, .scene-item");
    cards.forEach((card) => {
      const overlay = card.querySelector(".day-overlay-label");
      const rowtext = card.querySelector(".day-rowtext");
      const thumbWrap = card.querySelector(".day-thumb");

      if (mode === "grid") {
        card.style.display = "block";
        card.style.gridTemplateColumns = "";
        card.style.alignItems = "";
        card.style.gap = "";

        if (thumbWrap) {
          thumbWrap.style.width = "100%";
          thumbWrap.style.borderBottom = "1px solid var(--border)";
        }
        if (overlay) overlay.style.display = "";
        if (rowtext) rowtext.style.display = "none";

        const overlayMeta = card.querySelector(".day-overlay-meta");
        const rowMeta = card.querySelector(".day-rowmeta");
        if (overlayMeta) overlayMeta.style.display = "";
        if (rowMeta) rowMeta.style.display = "none";
      } else {
        card.style.display = "grid";
        card.style.gridTemplateColumns = "120px 1fr";
        card.style.alignItems = "center";
        card.style.gap = "10px";

        if (thumbWrap) {
          thumbWrap.style.width = "120px";
          thumbWrap.style.borderBottom = "none";
        }
        if (overlay) overlay.style.display = "none";
        if (rowtext) rowtext.style.display = "block";

        const overlayMeta = card.querySelector(".day-overlay-meta");
        const rowMeta = card.querySelector(".day-rowmeta");
        if (overlayMeta) overlayMeta.style.display = "none";
        if (rowMeta) rowMeta.style.display = "block";
      }
    });
  }

  function applyMode(mode) {
    saveScroll(getMode());

    clipContainer.classList.remove("list-view", "grid-view");
    dayContainer.classList.remove("list-view", "grid-view");
    if (sceneContainer) {
      sceneContainer.classList.remove("list-view", "grid-view");
      sceneContainer.classList.add(mode + "-view");
    }

    clipContainer.classList.add(mode + "-view");
    dayContainer.classList.add(mode + "-view");

    layoutModeForCards(mode);

    const savedW = sessionStorage.getItem(keyW(mode));
    const width = parseInt(savedW || defaults[mode], 10);
    layout.style.setProperty("--clipcol-width", width + "px");

    setToggleIcon(mode);
    sessionStorage.setItem(keyMode, mode);

    restoreScroll(mode);
  }

  function updateBreadcrumb(paneName) {
    const breadcrumbParent =
      document.getElementById("breadcrumbParentScenes") ||
      document.getElementById("breadcrumbParentDays");
    const currentLabel = document.getElementById("zd-current-day-label");
    const slash = document.querySelector(".hdr-slash");
    const countSpan = document.querySelector(".hdr-count");

    if (!breadcrumbParent || !currentLabel) return;

    if (paneName === "days") {
      // Show just "Days /"
      breadcrumbParent.textContent = "Days";
      breadcrumbParent.style.display = "";
      if (slash) slash.style.display = "none";
      if (currentLabel) currentLabel.style.display = "none";
      if (countSpan) countSpan.style.display = "none";
    } else if (paneName === "scenes") {
      // Show just "Scenes /"
      breadcrumbParent.textContent = "Scenes";
      breadcrumbParent.style.display = "";
      if (slash) slash.style.display = "none";
      if (currentLabel) currentLabel.style.display = "none";
      if (countSpan) countSpan.style.display = "none";
    } else if (paneName === "clips") {
      // Show full breadcrumb: "Days / DAY 03 / N Clips" or "Scenes / Scene XX / N Clips"
      if (slash) slash.style.display = "";
      if (currentLabel) currentLabel.style.display = "";
      if (countSpan) countSpan.style.display = "";
    }
  }

  function showPane(paneName) {
    saveScroll(getMode());
    activePane = paneName;

    if (paneName === "days" || paneName === "scenes") {
      const allClips = clipContainer.querySelectorAll(".clip-item");
      allClips.forEach((c) => (c.style.display = ""));
    }

    clipWrapper.style.display = "none";
    dayWrapper.style.display = "none";
    if (sceneWrapper) sceneWrapper.style.display = "none";

    if (paneName === "clips") {
      clipWrapper.style.display = "";
    } else if (paneName === "days") {
      dayWrapper.style.display = "";
    } else if (paneName === "scenes") {
      if (sceneWrapper) sceneWrapper.style.display = "";
    }

    const isSceneMode = new URLSearchParams(window.location.search).has(
      "scene",
    );

    document
      .getElementById("switchToDays")
      ?.classList.toggle(
        "active",
        paneName === "days" || (paneName === "clips" && !isSceneMode),
      );
    document
      .getElementById("switchToScenes")
      ?.classList.toggle(
        "active",
        paneName === "scenes" || (paneName === "clips" && isSceneMode),
      );

    updateBreadcrumb(paneName);
    updateSortVisibility();
    restoreScroll(getMode());
  }

  // -------------------------------------------------
  // SORTING LOGIC
  // -------------------------------------------------
  const sortModeEl = document.getElementById("clipSortMode");
  const sortDirBtn = document.getElementById("sortDirBtn");
  const sortGroup = document.getElementById("clipSortGroup");

  const isOverviewMode = () => {
    return activePane === "days" || activePane === "scenes";
  };

  const updateSortVisibility = () => {
    if (!sortGroup) return;
    if (isOverviewMode()) {
      sortGroup.style.display = "none";
    } else {
      sortGroup.style.display = "flex";
    }
  };

  const defaultDirForMode = (mode) => {
    if (mode === "select" || mode === "comments") return "desc";
    return "asc";
  };

  let sortDir = sortDirBtn?.getAttribute("data-dir") || "asc";

  function sortLists() {
    if (isOverviewMode()) {
      const container = activePane === "days" ? dayContainer : sceneContainer;
      const items = Array.from(
        container.querySelectorAll(".day-item, .scene-item"),
      );
      if (!items.length) return;

      items.sort((a, b) => {
        const aVal = (
          a.dataset.scene ||
          a.dataset.dayLabel ||
          ""
        ).toLowerCase();
        const bVal = (
          b.dataset.scene ||
          b.dataset.dayLabel ||
          ""
        ).toLowerCase();

        const cmp =
          !isNaN(aVal) && !isNaN(bVal)
            ? parseFloat(aVal) - parseFloat(bVal)
            : aVal.localeCompare(bVal);

        return sortDir === "asc" ? cmp : -cmp;
      });

      items.forEach((el) => container.appendChild(el));
      return;
    }

    if (!clipContainer || !sortModeEl) return;

    const mode = sortModeEl.value || "scene";
    const items = Array.from(clipContainer.querySelectorAll(".clip-item"));
    if (!items.length) return;

    const compare = (a, b) => {
      const aData = a.dataset;
      const bData = b.dataset;
      let aVal, bVal;

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

  if (sortModeEl) {
    sortModeEl.addEventListener("change", () => {
      const mode = sortModeEl.value || "scene";
      sortDir = defaultDirForMode(mode);
      sortDirBtn.setAttribute("data-dir", sortDir);
      sortLists();
    });
  }

  if (sortDirBtn) {
    sortDirBtn.addEventListener("click", () => {
      sortDir = sortDir === "asc" ? "desc" : "asc";
      sortDirBtn.setAttribute("data-dir", sortDir);
      sortLists();
    });
  }

  // -------------------------------------------------
  // INIT
  // -------------------------------------------------

  const restoredMode =
    sessionStorage.getItem(keyMode) === "grid" ? "grid" : "list";
  const urlPaneParam = new URLSearchParams(location.search).get("pane");
  const forcedPane =
    urlPaneParam && /^(clips|days|scenes)$/i.test(urlPaneParam)
      ? urlPaneParam.toLowerCase()
      : null;
  const restoredPane =
    forcedPane || sessionStorage.getItem(keyPane()) || "clips";

  if (forcedPane) {
    sessionStorage.setItem(keyPane(), restoredPane);
  }

  clipContainer.classList.remove("list-view", "grid-view");
  dayContainer.classList.remove("list-view", "grid-view");
  if (sceneContainer) {
    sceneContainer.classList.remove("list-view", "grid-view");
  }

  clipContainer.classList.add(restoredMode + "-view");
  dayContainer.classList.add(restoredMode + "-view");
  if (sceneContainer) {
    sceneContainer.classList.add(restoredMode + "-view");
  }

  layoutModeForCards(restoredMode);
  setToggleIcon(restoredMode);

  // Width already applied in pre-DOMContentLoaded block, just ensure consistency
  const savedInitialW = sessionStorage.getItem(keyW(restoredMode));
  if (savedInitialW) {
    layout.style.setProperty("--clipcol-width", savedInitialW + "px");
  }

  activePane = restoredPane;

  // Force highlight correction if we are in clip view but came from a specific context
  const isSceneMode = new URLSearchParams(window.location.search).has("scene");
  if (activePane === "clips") {
    const btnDays = document.getElementById("switchToDays");
    const btnScenes = document.getElementById("switchToScenes");
    btnDays?.classList.toggle("active", !isSceneMode);
    btnScenes?.classList.toggle("active", isSceneMode);
  }

  showPane(restoredPane);

  restoreScroll(restoredMode);

  // Set initial sort mode
  if (sortModeEl) {
    const clipItems = Array.from(clipContainer.querySelectorAll(".clip-item"));
    const hasScene = clipItems.some((el) => {
      const label = (el.dataset.label || "").trim();
      return label !== "";
    });

    const initialMode = hasScene ? "scene" : "name";
    const hasInitialOption = Array.from(sortModeEl.options).some(
      (opt) => opt.value === initialMode,
    );
    if (hasInitialOption) {
      sortModeEl.value = initialMode;
    }

    sortDir = defaultDirForMode(sortModeEl.value || initialMode);
    sortDirBtn.setAttribute("data-dir", sortDir);
  }

  updateSortVisibility();
  sortLists();

  // -------------------------------------------------
  // EVENTS
  // -------------------------------------------------

  const breadcrumbScenes = document.getElementById("breadcrumbParentScenes");
  const breadcrumbDays = document.getElementById("breadcrumbParentDays");

  if (breadcrumbScenes) {
    breadcrumbScenes.addEventListener("click", (e) => {
      e.stopPropagation();
      showPane("scenes");
    });
  }

  if (breadcrumbDays) {
    breadcrumbDays.addEventListener("click", (e) => {
      e.stopPropagation();
      showPane("days");
    });
  }

  const btnShowDays = document.getElementById("switchToDays");
  const btnShowScenes = document.getElementById("switchToScenes");

  if (btnShowDays && btnShowScenes) {
    btnShowDays.addEventListener("click", (e) => {
      e.stopPropagation();
      showPane("days");
    });

    btnShowScenes.addEventListener("click", (e) => {
      e.stopPropagation();
      showPane("scenes");
    });
  }

  if (sceneContainer) {
    sceneContainer.addEventListener("click", (e) => {
      const btn = e.target.closest(".scene-item");
      if (!btn) return;

      const sceneNum = btn.dataset.scene;
      if (!ZD_PROJECT_UUID || !sceneNum) return;

      const url = `/admin/projects/${encodeURIComponent(ZD_PROJECT_UUID)}/player?scene=${encodeURIComponent(sceneNum)}&pane=clips`;
      window.location.assign(url);
    });
  }

  document.addEventListener("click", (e) => {
    const btn = e.target.closest(".day-item:not(.scene-item)");
    if (!btn) return;

    const dayUuid = btn.dataset.dayUuid;
    if (!ZD_PROJECT_UUID || !dayUuid) return;

    const url = `/admin/projects/${encodeURIComponent(ZD_PROJECT_UUID)}/days/${encodeURIComponent(dayUuid)}/player`;
    window.location.assign(url);
  });

  btnToggleView.addEventListener("click", function () {
    const next = getMode() === "grid" ? "list" : "grid";
    applyMode(next);
  });

  clipContainer.addEventListener("click", function (e) {
    const a = e.target.closest("a.clip-item");
    if (!a) return;
    const modeNow = getMode();
    persistStateBeforeNav(activePane, modeNow);
  });

  // -------------------------------------------------
  // RESIZE LOGIC
  // -------------------------------------------------
  let dragging = false,
    startX = 0,
    startW = 0,
    cachedMaxAllowed = 0,
    ticking = false;

  function getFreshMaxAllowed() {
    const total = layout.getBoundingClientRect().width;
    const resizerTrack = 18;
    if (!isTheater()) {
      const minPlayerWidth = 400;
      return Math.max(240, total - minPlayerWidth - resizerTrack);
    } else {
      const minVideoWidth = 560;
      return Math.max(280, total - minVideoWidth - resizerTrack);
    }
  }

  [resizer, innerResizer].forEach((el) =>
    el?.addEventListener("mousedown", function (e) {
      dragging = true;
      startX = e.clientX;

      // Cache values ONCE on mousedown to prevent layout thrashing
      const cs = getComputedStyle(layout);
      const varName = currentVarName();
      const fallback = isTheater() ? 420 : defaults[getMode()];
      startW = parseInt(cs.getPropertyValue(varName)) || fallback;
      cachedMaxAllowed = getFreshMaxAllowed();

      document.body.classList.add("zd-resizing"); // Use CSS to disable pointer-events/transitions
      document.body.style.cursor = "col-resize";
      document.body.style.userSelect = "none";
    }),
  );

  // Optimized mousemove using requestAnimationFrame to prevent lag
  window.addEventListener("mousemove", function (e) {
    if (!dragging || ticking) return;

    ticking = true;
    requestAnimationFrame(() => {
      const dx = e.clientX - startX;
      const delta = isTheater() ? -dx : dx;
      let nw = startW + delta;

      // Use the cached values instead of calling getBoundingClientRect() repeatedly
      if (nw < (isTheater() ? 280 : 240)) nw = isTheater() ? 280 : 240;
      if (nw > cachedMaxAllowed) nw = cachedMaxAllowed;

      layout.style.setProperty(currentVarName(), nw + "px");
      ticking = false;
    });
  });

  window.addEventListener("mouseup", function () {
    if (!dragging) return;
    dragging = false;
    document.body.classList.remove("zd-resizing");
    document.body.style.cursor = "";
    document.body.style.userSelect = "";

    const finalW = parseInt(
      getComputedStyle(layout).getPropertyValue(currentVarName()),
    );
    sessionStorage.setItem(keyW(getMode()), String(finalW));
  });

  document.addEventListener("keydown", (e) => {
    if (e.key.toLowerCase() === "t" && !e.altKey && !e.ctrlKey && !e.metaKey) {
      theaterBtn?.click();
    }
  });

  resizer.addEventListener("dblclick", function () {
    const varName = currentVarName();
    const def = isTheater() ? 420 : defaults[getMode()];
    layout.style.setProperty(varName, def + "px");
    if (!isTheater()) {
      sessionStorage.setItem(keyW(getMode()), String(def));
    }
  });

  // -------------------------------------------------
  // Metadata <details> persistence
  // -------------------------------------------------
  const metaSections = document.querySelectorAll(
    "details.zd-metadata-group[data-meta-section]",
  );

  metaSections.forEach((det) => {
    const section = det.dataset.metaSection;
    if (!section) return;

    const key = `playerMetaOpen:${section}`;
    const saved = sessionStorage.getItem(key);
    if (saved === "1") {
      det.open = true;
    } else if (saved === "0") {
      det.open = false;
    }

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

  const drop = Math.abs(fps - 29.97) < 0.02 || Math.abs(fps - 59.94) < 0.02;

  const pad2 = (n) => String(n).padStart(2, "0");

  function dropCountPerMinute(fps) {
    const f = Math.round(fps);
    return Math.round(f / 15);
  }

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

    const d =
      Math.floor(frames / (framesPer10Min - df * 9)) * 9 * df +
      Math.max(
        0,
        Math.floor((frames % (framesPer10Min - df * 9)) / (framesPerMin - df)),
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

  const startFrames = drop
    ? tcToFrames_DF(startTC, fps)
    : tcToFrames_ND(startTC, fps);

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

    renderFrame(0);
  }
})();
