document.addEventListener("DOMContentLoaded", () => {
  const vid = document.getElementById("zdVideo");
  if (!vid) return;

  // ADD THIS: Global flag to stop the render loop
  let shouldStopLoop = false;
  let activeFrameId = null;

  const TC_OFFSET_FRAMES = 0;
  const EPS = 1e-6;
  const MUTE_KEY = "zd_player_is_muted";
  const DRAWER_STATE_KEY = "zd_player_drawer_state";

  const frame = document.getElementById("playerFrame");
  const scrub = document.getElementById("mScrub");
  const tcChip = document.getElementById("mTcDisplay");
  const iconWrap = document.getElementById("mPlayIconContainer");
  const picker = document.getElementById("mBlankPicker");
  const volBtn = document.getElementById("mVolBtn");

  let isDragging = false;

  const icons = {
    play: `<svg viewBox="0 0 24 24" width="60" height="60" fill="white"><path d="M8 5v14l11-7z"/></svg>`,
    pause: `<svg viewBox="0 0 24 24" width="60" height="60" fill="white"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>`,
  };

  // === 1. MATH & RENDERING ===
  const fpsNum = Number(vid.dataset.fpsnum) || 0;
  const fpsDen = Number(vid.dataset.fpsden) || 0;
  const fps =
    fpsNum > 0 && fpsDen > 0 ? fpsNum / fpsDen : Number(vid.dataset.fps) || 25;
  const startTC = (vid.dataset.tcStart || "00:00:00:00").trim();
  const isDrop = Math.abs(fps - 29.97) < 0.02 || Math.abs(fps - 59.94) < 0.02;

  const pad2 = (n) => String(n).padStart(2, "0");

  function getFramesFromTC(tc) {
    const m = /^(\d{2}):(\d{2}):(\d{2})[:;](\d{2})$/.exec(tc);
    if (!m) return 0;
    const [hh, mm, ss, ff] = [m[1], m[2], m[3], m[4]].map(Number);
    if (!isDrop) return (hh * 3600 + mm * 60 + ss) * Math.round(fps) + ff;
    const f = Math.round(fps),
      df = Math.round(f / 15);
    const totalMin = hh * 60 + mm;
    return (
      (hh * 3600 + mm * 60 + ss) * f +
      ff -
      df * (totalMin - Math.floor(totalMin / 10))
    );
  }

  function formatTC(totalFrames) {
    const f = Math.round(fps);
    let fr = Math.max(0, Math.round(totalFrames));
    if (isDrop) {
      const df = Math.round(f / 15);
      fr +=
        Math.floor(fr / (f * 600 - df * 9)) * 9 * df +
        Math.max(0, Math.floor((fr % (f * 600 - df * 9)) / (f * 60 - df))) * df;
    }
    const ff = fr % f,
      ss = (fr = (fr - ff) / f) % 60,
      mm = (fr = (fr - ss) / 60) % 60;
    return `${pad2((fr - mm) / 60)}:${pad2(mm)}:${pad2(ss)}${
      isDrop ? ";" : ":"
    }${pad2(ff)}`;
  }

  const startFrames = getFramesFromTC(startTC);

  const renderFrame = (time) => {
    const currentFrames =
      startFrames + Math.round(time * fps + EPS) + TC_OFFSET_FRAMES;
    tcChip.textContent = formatTC(currentFrames);
    if (vid.duration > 0 && !isDragging) {
      scrub.value = Math.round((time / vid.duration) * 1000);
    }
  };

  const updatePlayPauseUI = () => {
    if (vid.paused) {
      iconWrap.innerHTML = icons.play;
      iconWrap.style.opacity = "1";
    } else {
      iconWrap.innerHTML = icons.pause;
      iconWrap.style.opacity = "0.4";
    }
  };

  const updateLoop = () => {
    if (shouldStopLoop) return; // Stop condition

    renderFrame(vid.currentTime);

    if ("requestVideoFrameCallback" in vid) {
      activeFrameId = vid.requestVideoFrameCallback(updateLoop);
    } else {
      activeFrameId = requestAnimationFrame(updateLoop);
    }
  };

  // ADD THIS: Function to stop the loop
  window.stopPlayerLoop = () => {
    shouldStopLoop = true;
    if (activeFrameId !== null) {
      if ("cancelVideoFrameCallback" in vid) {
        vid.cancelVideoFrameCallback(activeFrameId);
      } else {
        cancelAnimationFrame(activeFrameId);
      }
    }
  };

  // === 2. INITIALIZATION ===
  const setupInitialState = () => {
    const shouldMute = sessionStorage.getItem(MUTE_KEY) === "true";
    vid.muted = shouldMute;
    if (volBtn) volBtn.textContent = shouldMute ? "🔇" : "🔊";

    const urlParams = new URLSearchParams(window.location.search);

    if (urlParams.get("autoplay") === "1") {
      vid.play().catch(() => {
        vid.muted = true;
        vid.play();
        if (volBtn) volBtn.textContent = "🔇"; // Fixed
      });
    }

    // FIXED: Restore drawer state immediately (no setTimeout needed)
    const targetPane =
      urlParams.get("pane") || localStorage.getItem(DRAWER_STATE_KEY);
    if (targetPane && targetPane !== "none") {
      toggleDrawer(targetPane, false);
    }

    updatePlayPauseUI();
    updateLoop();
  };

  setupInitialState();

  // === 3. THE "WAKE" LOGIC ===
  let idleTimer;
  const wakeControls = () => {
    frame.classList.remove("controls-idle");
    clearTimeout(idleTimer);
    if (!vid.paused) {
      idleTimer = setTimeout(() => frame.classList.add("controls-idle"), 2500);
    }
  };

  // --- Updated Playback & UI Toggle Logic ---
  document.getElementById("mPlayTrigger").addEventListener("click", (e) => {
    // 1. If the UI is currently hidden (idle), wake it and STOP.
    // This allows bringing up the info/controls without pausing the video.
    if (frame.classList.contains("controls-idle")) {
      wakeControls();
      return;
    }

    // 2. If the UI is already visible:
    // Check if the user specifically tapped the Play/Pause icon in the center.
    const isIconClick = e.target.closest("#mPlayIconContainer");

    if (isIconClick) {
      // Only toggle playback if the center icon was the target
      if (vid.paused) vid.play();
      else vid.pause();
      wakeControls();
    } else {
      // Tapping the background while the UI is already awake simply hides it.
      // The video keeps playing.
      frame.classList.add("controls-idle");
      clearTimeout(idleTimer);
    }
  });

  // === 4. OTHER HANDLERS ===
  vid.addEventListener("play", updatePlayPauseUI);
  vid.addEventListener("pause", () => {
    updatePlayPauseUI();
    requestAnimationFrame(() => renderFrame(vid.currentTime));
  });

  if (volBtn) {
    volBtn.addEventListener("click", (e) => {
      e.stopPropagation();
      vid.muted = !vid.muted;

      // Toggle the emoji based on state
      volBtn.textContent = vid.muted ? "🔇" : "🔊";

      sessionStorage.setItem(MUTE_KEY, vid.muted);
      if (typeof wakeControls === "function") wakeControls();
    });
  }

  scrub.addEventListener("input", () => {
    isDragging = true;
    vid.currentTime = (scrub.value / 1000) * vid.duration;
    renderFrame(vid.currentTime);
    wakeControls();
  });
  scrub.addEventListener("change", () => {
    isDragging = false;
  });

  document.getElementById("mFsBtn").addEventListener("click", (e) => {
    e.stopPropagation();
    const enter = frame.requestFullscreen || frame.webkitRequestFullscreen;
    if (!document.fullscreenElement && enter) enter.call(frame);
    else document.exitFullscreen?.();
    wakeControls();
  });

  // --- Aspect Ratio Blanking (Mobile Pro) ---
  const projectUuid = picker.dataset.projectUuid || "global";
  const projectDefault = picker.dataset.default || "none";
  const sessionKey = "playerBlanking_" + projectUuid;

  // 1. The Setter Function
  window.setMobileBlanking = (v) => {
    if (!frame) return;
    frame.setAttribute("data-blanking", v);
    sessionStorage.setItem(sessionKey, v);
    picker.classList.remove("is-open");
  };

  // 2. The Initialization Logic
  const initMobileBlanking = () => {
    if (!picker || !frame) return;
    const sessionOverride = sessionStorage.getItem(sessionKey);
    const initialRatio = sessionOverride || projectDefault;

    // Apply the string (e.g., "2.00") directly to the data attribute
    frame.setAttribute("data-blanking", initialRatio);
  };

  initMobileBlanking();

  // 3. The Toggle Button Listener
  const blankBtn = document.getElementById("mBlankBtn");

  // Helper function to position the popover
  const positionPopover = () => {
    if (!picker.classList.contains("is-open")) return;

    const btnRect = blankBtn.getBoundingClientRect();
    const popoverHeight = 240; // Approximate height with 6 buttons
    const headerHeight = 54; // Mobile header height
    const gap = 8; // Gap between button and popover

    const spaceAbove = btnRect.top - headerHeight;
    const spaceBelow = window.innerHeight - btnRect.bottom;

    // Calculate horizontal position (center the popover on the button)
    const popoverWidth = picker.offsetWidth || 80;
    const centerX = btnRect.left + btnRect.width / 2;
    const popoverLeft = Math.max(
      8,
      Math.min(
        window.innerWidth - popoverWidth - 8,
        centerX - popoverWidth / 2,
      ),
    );

    picker.style.left = `${popoverLeft}px`;

    // Decide direction and position
    if (spaceAbove < popoverHeight && spaceBelow > spaceAbove) {
      // Open downward
      picker.classList.add("opens-down");
      picker.style.top = `${btnRect.bottom + gap}px`;
      picker.style.bottom = "auto";
    } else {
      // Open upward (default)
      picker.classList.remove("opens-down");
      picker.style.bottom = `${window.innerHeight - btnRect.top + gap}px`;
      picker.style.top = "auto";
    }
  };

  blankBtn.addEventListener("click", (e) => {
    e.stopPropagation();

    const wasOpen = picker.classList.contains("is-open");
    picker.classList.toggle("is-open");

    if (!wasOpen) {
      // Opening - position it
      positionPopover();
    }

    // If your script has wakeControls(), keep it here:
    if (typeof wakeControls === "function") wakeControls();
  });

  // Reposition on orientation change or fullscreen toggle
  window.addEventListener("resize", () => {
    if (picker.classList.contains("is-open")) {
      positionPopover();
    }
  });

  // Also reposition on fullscreen changes
  document.addEventListener("fullscreenchange", () => {
    if (picker.classList.contains("is-open")) {
      setTimeout(positionPopover, 100); // Small delay for layout to settle
    }
  });

  // Close blanking popover when clicking outside
  document.addEventListener("click", (e) => {
    if (!picker.contains(e.target) && !blankBtn.contains(e.target)) {
      picker.classList.remove("is-open");
    }
  });

  // --- Seamless Fullscreen Autoplay ---
  vid.addEventListener("ended", () => {
    const activeLink = document.querySelector(".mobile-clip-link.is-active");
    const nextLink = activeLink?.nextElementSibling;

    if (nextLink && nextLink.classList.contains("mobile-clip-link")) {
      // If we are in Fullscreen, stay on the page and swap the source
      if (document.fullscreenElement || document.webkitFullscreenElement) {
        const d = nextLink.dataset;

        // 1. Swap Video Source
        vid.src = d.proxy;
        vid.dataset.fps = d.fps;
        vid.dataset.tcStart = d.tcStart;

        // 2. Update Overlay Metadata
        const titleEl = document.querySelector(".m-clip-info-overlay h3");
        const fileEl = document.querySelector(".m-clip-info-overlay .filename");
        if (titleEl)
          titleEl.textContent = `${d.scene} / ${d.slate} - ${d.take}`;
        if (fileEl) fileEl.textContent = d.filename;

        // 3. Update Clip List UI
        activeLink.classList.remove("is-active");
        nextLink.classList.add("is-active");
        nextLink.scrollIntoView({ behavior: "smooth", block: "center" });

        // 4. Update Browser URL (so "Back" button works correctly)
        history.pushState(null, "", nextLink.href);

        // 5. Play
        vid.load();
        vid.play();
      } else {
        // Not in Fullscreen: Standard reload behavior
        const nextUrl = new URL(nextLink.href, window.location.origin);
        nextUrl.searchParams.set("autoplay", "1");
        window.location.assign(nextUrl.toString());
      }
    }
  });

  // === 9. COMMENT TC LOGIC ===
  const commentTcInput = document.getElementById("mCommentTc");
  const getTcBtn = document.getElementById("mGetTcBtn");

  if (getTcBtn && commentTcInput) {
    getTcBtn.addEventListener("click", () => {
      commentTcInput.value = tcChip.textContent;
      commentTcInput.style.borderColor = "var(--m-accent)";
      setTimeout(() => {
        commentTcInput.style.borderColor = "#2a3342";
      }, 300);
    });
  }

  // === 10. COMMENT TC CLICK TO JUMP ===
  document.querySelectorAll(".m-comment-tc").forEach((tcElement) => {
    tcElement.addEventListener("click", () => {
      const tc = tcElement.textContent.trim();
      if (!tc) return;

      // Parse the comment TC (absolute frames)
      const absFrames = getFramesFromTC(tc);
      // Convert to clip-relative frames
      let offsetFrames = absFrames - startFrames;
      if (!Number.isFinite(offsetFrames) || offsetFrames < 0) {
        offsetFrames = 0;
      }

      // Convert frames to seconds
      const seconds = offsetFrames / fps;

      // Seek to the timecode (with small offset to land in middle of frame)
      vid.currentTime = seconds + (1 / fps) * 0.5;
      vid.pause();

      // Scroll video into view smoothly
      vid.scrollIntoView({ behavior: "smooth", block: "center" });
    });
  });
});

document.addEventListener("DOMContentLoaded", () => {
  let longPressTimer;
  let isLongPress = false;
  const contextMenu = document.getElementById("mContextMenu");
  const favoriteBtn = document.getElementById("btnCtxFavorite");

  // We need these from the data attributes in your layout
  const projectUuid =
    document.querySelector(".player-layout")?.dataset.projectUuid;
  const dayUuid = document.querySelector(".player-layout")?.dataset.dayUuid;
  let activeClipUuid = null;
  let activeLinkElement = null;

  document.querySelectorAll(".mobile-clip-link").forEach((link) => {
    link.addEventListener("touchstart", (e) => {
      isLongPress = false;
      activeLinkElement = link;
      // Extract clip UUID from the URL
      const urlParts = link.href.split("/");
      activeClipUuid = urlParts[urlParts.length - 1];

      longPressTimer = setTimeout(() => {
        isLongPress = true;
        showContextMenu();
      }, 600); // 0.6s hold
    });

    link.addEventListener("touchend", (e) => {
      clearTimeout(longPressTimer);
      if (isLongPress) {
        e.preventDefault(); // Stop navigation if it was a hold
      }
    });

    link.addEventListener("touchmove", () => clearTimeout(longPressTimer));
  });

  function showContextMenu() {
    if (!contextMenu) return;

    // 1. Clear accidental text selection (fixes the "Cancel" button highlight)
    if (window.getSelection) {
      window.getSelection().removeAllRanges();
    }

    contextMenu.style.display = "flex";

    // 2. Use a more reliable selector (title="Select") instead of the style string
    const hasStar = activeLinkElement.querySelector('span[title="Select"]');

    favoriteBtn.textContent = hasStar
      ? "☆ Remove from Selects"
      : "★ Mark as Select";
  }

  window.closeMContextMenu = () => {
    contextMenu.style.display = "none";
  };

  favoriteBtn.addEventListener("click", () => {
    // Use the consistent selector from the top of the file
    const playerLayout = document.querySelector(".player-layout");
    const pUuid = playerLayout?.dataset.projectUuid;
    const dUuid = playerLayout?.dataset.dayUuid;

    if (!activeClipUuid || !pUuid || !dUuid) {
      console.error("Missing IDs:", { activeClipUuid, pUuid, dUuid });
      alert("Error: Missing required information"); // Add user feedback
      closeMContextMenu();
      return;
    }

    const csrfInput = document.querySelector('input[name="_csrf"]');
    if (!csrfInput) {
      console.error("CSRF token not found");
      alert("Error: Security token missing");
      closeMContextMenu();
      return;
    }

    const formData = new FormData();
    formData.append("_csrf", csrfInput.value);

    fetch(
      `/admin/projects/${pUuid}/days/${dUuid}/clips/${activeClipUuid}/select`,
      {
        method: "POST",
        body: formData,
      },
    )
      .then((res) => {
        if (!res.ok) {
          throw new Error(`HTTP ${res.status}`);
        }
        return res.json();
      })
      .then((data) => {
        if (data.ok) {
          updateStarUI(activeLinkElement, data.is_select);

          const successEl = document.getElementById("ctxSuccess");
          if (successEl) {
            successEl.style.display = "flex";
            setTimeout(() => {
              closeMContextMenu();
              successEl.style.display = "none";
            }, 800);
          }
        } else {
          alert("Error: " + (data.error || "Permission denied"));
          closeMContextMenu();
        }
      })
      .catch((err) => {
        console.error("Fetch error:", err);
        alert("Network error: " + err.message);
        closeMContextMenu();
      });
  });

  // Strictly prevent the native browser menu on clip links
  document.querySelectorAll(".mobile-clip-link").forEach((link) => {
    link.addEventListener("contextmenu", (e) => {
      e.preventDefault();
    });
  });
});

// DRAWER TOGGLE LOGIC - CORRECTED
function toggleDrawer(pane, shouldSave = true) {
  const meta = document.getElementById("drawerMeta");
  const comm = document.getElementById("drawerComments");
  const commentsList = document.getElementById("mCommentsList");
  const mBtn = document.getElementById("tabBtnMeta");
  const cBtn = document.getElementById("tabBtnComments");

  let stateToSave = "none";
  let shouldOpen = false; // Define this properly

  if (pane === "meta") {
    shouldOpen = meta.style.display !== "block";
    meta.style.display = shouldOpen ? "block" : "none";
    comm.style.display = "none";
    if (commentsList) commentsList.style.display = "none";
    mBtn.classList.toggle("is-active", shouldOpen);
    cBtn.classList.remove("is-active");
    stateToSave = shouldOpen ? "meta" : "none";
  } else if (pane === "comments") {
    shouldOpen = comm.style.display !== "block";
    comm.style.display = shouldOpen ? "block" : "none";
    if (commentsList)
      commentsList.style.display = shouldOpen ? "block" : "none";
    meta.style.display = "none";
    cBtn.classList.toggle("is-active", shouldOpen);
    mBtn.classList.remove("is-active");
    stateToSave = shouldOpen ? "comments" : "none";
  }

  // Scroll to top only if opening a drawer so the user sees the content
  if (shouldOpen) {
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  if (shouldSave) {
    localStorage.setItem("zd_player_drawer_state", stateToSave);
  }
}

// --- GLOBAL REPLY FUNCTIONS ---

function setupMobileReply(uuid, name) {
  const parentInput = document.getElementById("mParentUuid");
  const indicator = document.getElementById("mReplyIndicator");
  const nameSpan = document.getElementById("mReplyName");
  const textarea = document.getElementById("mCommentText");
  const submitBtn = document.querySelector(
    "#mCommentForm button[type='submit']",
  );

  if (parentInput && indicator && nameSpan && textarea) {
    parentInput.value = uuid;
    nameSpan.textContent = name;
    indicator.style.display = "flex";

    textarea.focus();
    textarea.placeholder = "Write your reply...";

    // Change submit button text to "POST REPLY"
    if (submitBtn) {
      submitBtn.textContent = "POST REPLY";
    }

    document.getElementById("drawerComments").scrollTop = 0;
  }
}

function cancelMobileReply() {
  const parentInput = document.getElementById("mParentUuid");
  const indicator = document.getElementById("mReplyIndicator");
  const textarea = document.getElementById("mCommentText");
  const submitBtn = document.querySelector(
    "#mCommentForm button[type='submit']",
  );

  if (parentInput && indicator && textarea) {
    parentInput.value = "";
    indicator.style.display = "none";
    textarea.placeholder = "Add a note...";

    // Change submit button text back to "POST COMMENT"
    if (submitBtn) {
      submitBtn.textContent = "POST COMMENT";
    }
  }
}

// Auto-reset reply mode after form submission
document.addEventListener("DOMContentLoaded", () => {
  const commentForm = document.getElementById("mCommentForm");
  if (commentForm) {
    commentForm.addEventListener("submit", () => {
      // Delay the reset to allow form submission to complete
      setTimeout(cancelMobileReply, 100);
    });
  }
});

// Helper to update the star icon in the clip list without a page reload
// player-mobile-ui.js (at the very bottom)

function updateStarUI(linkEl, isSelect) {
  if (!linkEl) return;

  // Target the specific icon container (the second div inside m-clip-meta)
  const iconsWrap = linkEl.querySelector(".m-clip-meta > div:last-child");
  if (!iconsWrap) return;

  let existingStar = iconsWrap.querySelector('span[title="Select"]');

  if (isSelect) {
    if (!existingStar) {
      const star = document.createElement("span");
      // Matching your PHP style exactly
      star.style.cssText = "color: #fbbf24; font-size: 14px;";
      star.title = "Select";
      star.textContent = "★";

      // Use prepend here so it appears BEFORE the comment count
      // but WITHIN the icon container
      iconsWrap.prepend(star);
    }
  } else {
    if (existingStar) {
      existingStar.remove();
    }
  }
}

// Add this SEPARATE DOMContentLoaded listener at the bottom of player-mobile-ui.js
// This handles cleanup for ALL navigation (clip links AND back button)

document.addEventListener("DOMContentLoaded", () => {
  // Helper function to cleanup before any navigation
  const cleanupBeforeNavigate = () => {
    // Stop the render loop
    if (window.stopPlayerLoop) {
      window.stopPlayerLoop();
    }

    // Clean up video
    const video = document.getElementById("zdVideo");
    if (video) {
      video.pause();
      video.removeAttribute("src");
      video.load();
    }
  };

  // 1. Handle Back Button
  const backLink = document.querySelector(".m-back-link");
  if (backLink) {
    backLink.removeAttribute("onclick");

    backLink.addEventListener("click", (e) => {
      e.preventDefault();
      e.stopPropagation();

      cleanupBeforeNavigate();
      window.location.href = backLink.getAttribute("href");
    });
  }

  // 3. Global cleanup on page unload (safety net)
  window.addEventListener("beforeunload", cleanupBeforeNavigate);
  window.addEventListener("pagehide", cleanupBeforeNavigate);
});
