/**
 * AJAX NAVIGATION FOR MOBILE PLAYER
 *
 * Intercepts clip navigation and loads content via Ajax
 * instead of full page reloads.
 */
document.addEventListener("DOMContentLoaded", function () {
  const vid = document.getElementById("zdVideo");
  if (!vid) return;

  let isLoading = false;
  let currentRequest = null;

  /**
   * Load a clip via Ajax
   */
  async function loadClipAjax(url, clipElement, savedScrollTop = null) {
    if (isLoading) {
      console.log("Already loading, ignoring click");
      return;
    }
    isLoading = true;

    // Show loading overlay
    const loadingOverlay = createMobileLoadingOverlay();
    document.body.appendChild(loadingOverlay);

    // Pause video immediately
    vid.pause();

    try {
      // Abort any pending request
      if (currentRequest) {
        currentRequest.abort();
      }

      // Create new AbortController
      const controller = new AbortController();
      currentRequest = controller;

      // Fetch clip data via Ajax
      const response = await fetch(url, {
        headers: {
          "X-Requested-With": "XMLHttpRequest",
        },
        signal: controller.signal,
      });

      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      const data = await response.json();

      if (!data.success) {
        throw new Error("Server returned unsuccessful response");
      }

      // Update the page
      updateMobileVideoPlayer(data);
      updateMobileMetadata(data);
      updateMobileComments(data);
      updateMobileActiveClip(clipElement);
      updateBrowserHistory(url, data);

      // Re-initialize player UI
      reinitializeMobilePlayerUI(data);

      // Restore scroll position OR scroll to active clip
      requestAnimationFrame(() => {
        const clipNav = document.querySelector(".mobile-clip-nav");
        if (!clipNav) return;

        if (savedScrollTop !== null) {
          // Restore the exact scroll position (no jump)
          clipNav.scrollTop = savedScrollTop;
        } else {
          // Back button or initial load - find and scroll to active
          const activeClip = document.querySelector(
            ".mobile-clip-link.is-active",
          );
          if (activeClip) {
            activeClip.scrollIntoView({
              behavior: "smooth",
              block: "center",
            });
          }
        }
      });
    } catch (error) {
      if (error.name === "AbortError") {
        console.log("Request aborted");
        return;
      }

      console.error("Ajax navigation failed:", error);
      window.location.href = url;
      return;
    } finally {
      isLoading = false;
      currentRequest = null;
      loadingOverlay.remove();
    }
  }

  /**
   * Create mobile loading overlay
   */
  function createMobileLoadingOverlay() {
    const overlay = document.createElement("div");
    // Transparent full-screen container (No background color = No flash)
    overlay.style.cssText = `
        position: fixed;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10000;
        background: transparent; 
        pointer-events: none; /* Allows UI to stay visible behind */
    `;

    // The Card: Floating, dark, and rounded
    const card = document.createElement("div");
    card.style.cssText = `
        display: inline-flex;
        align-items: center;
        gap: 12px;
        padding: 16px 20px;
        border-radius: 12px;
        background: rgba(17, 19, 24, 0.96); /* Zentropa Dark */
        border: 1px solid rgba(255, 255, 255, 0.10);
        box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        color: rgba(255,255,255,0.90);
        font: 500 15px/1.2 system-ui, -apple-system, sans-serif;
    `;

    // The Spinner
    const spinner = document.createElement("div");
    spinner.style.cssText = `
        width: 20px;
        height: 20px;
        border: 2px solid rgba(255, 255, 255, 0.25);
        border-top-color: var(--m-accent, #3aa0ff);
        border-radius: 50%;
        animation: mSpin 0.8s linear infinite;
    `;

    const label = document.createElement("div");
    label.textContent = "Loading…";

    // Add Keyframes (Safe check to ensure we only add them once)
    if (!document.getElementById("mobile-ajax-spinner-kf")) {
      const style = document.createElement("style");
      style.id = "mobile-ajax-spinner-kf";
      style.textContent = `
            @keyframes mSpin { to { transform: rotate(360deg); } }
        `;
      document.head.appendChild(style);
    }

    card.appendChild(spinner);
    card.appendChild(label);
    overlay.appendChild(card);
    return overlay;
  }

  /**
   * Update mobile video player
   */
  function updateMobileVideoPlayer(data) {
    const { clip, urls } = data;

    // Update video data attributes
    vid.dataset.fps = clip.fps || "25";
    vid.dataset.fpsnum = clip.fps_num || "";
    vid.dataset.fpsden = clip.fps_den || "";
    vid.dataset.tcStart = clip.tc_start || "00:00:00:00";

    // [FIX] Set src directly on the video element.
    // This overrides any <source> tags and ensures the browser loads the new file.
    vid.src = urls.video;

    // Reload video
    vid.load();

    // Update clip info overlay
    const clipInfoOverlayH3 = document.querySelector(".m-clip-info-overlay h3");
    if (clipInfoOverlayH3) {
      const scene = clip.scene || "";
      const slate = clip.slate || "";
      const take = clip.take || "";
      const fileName = clip.file_name || "";

      const hasScene = scene.trim().length > 0;
      const hasSlate = slate.trim().length > 0;
      const hasTake = take.trim().length > 0;

      if (hasScene || hasSlate || hasTake) {
        clipInfoOverlayH3.innerHTML = `${escapeHtml(scene || "Sc")} / ${escapeHtml(slate)} - ${escapeHtml(take || "Tk")}`;
      } else {
        clipInfoOverlayH3.textContent = "No scene info";
      }
    }

    // Update filename below title
    const filenameEl = document.querySelector(".m-clip-info-overlay .filename");
    if (filenameEl) {
      filenameEl.textContent = clip.file_name
        ? clip.file_name.replace(/\.[^.]+$/, "")
        : "";
    }

    // Update sensitive indicator
    const clipInfoOverlay = document.querySelector(".m-clip-info-overlay");
    if (clipInfoOverlay) {
      // Always remove existing indicator first
      const existingSensitiveEl = clipInfoOverlay.querySelector(
        ".sensitive-indicator",
      );
      if (existingSensitiveEl) {
        existingSensitiveEl.remove();
      }

      // [FIXED] Use 'clip.is_sensitive' instead of 'data.is_sensitive'
      if (clip.is_sensitive) {
        const sensitiveEl = document.createElement("div");
        sensitiveEl.className = "sensitive-indicator";
        sensitiveEl.style.cssText =
          "font-size: 9px; color: #ef4444; margin-top: 2px; font-weight: 700;";
        sensitiveEl.textContent = "Sensitive";
        clipInfoOverlay.appendChild(sensitiveEl);
      }
    }

    // Update layout data attributes
    const layout = document.querySelector(".mobile-player-page");
    if (layout) {
      layout.dataset.projectUuid = data.project.uuid;
      layout.dataset.dayUuid = data.day.uuid;
    }

    // Trigger FPS/TC recalculation if needed
    if (window.updateMobileTcDisplay) {
      window.updateMobileTcDisplay();
    }
  }

  /**
   * Update mobile metadata
   */
  function updateMobileMetadata(data) {
    const metaGrid = document.querySelector(".mobile-meta-grid");
    if (!metaGrid) return;

    const { clip } = data;
    metaGrid.innerHTML = `
            <div class="mobile-meta-cell">
                <div class="k">TC In</div>
                <div class="v">${escapeHtml(clip.tc_start || "--")}</div>
            </div>
            <div class="mobile-meta-cell">
                <div class="k">TC Out</div>
                <div class="v">${escapeHtml(clip.tc_end || "--")}</div>
            </div>
            <div class="mobile-meta-cell">
                <div class="k">Camera</div>
                <div class="v">${escapeHtml(clip.camera || "--")}</div>
            </div>
            <div class="mobile-meta-cell">
                <div class="k">Reel</div>
                <div class="v">${escapeHtml(clip.reel || "--")}</div>
            </div>
        `;
  }

  /**
   * Update mobile comments
   */
  function updateMobileComments(data) {
    // Update comment count in tab
    const commentsTab = document.getElementById("tabBtnComments");
    if (commentsTab) {
      const count = data.comments ? data.comments.length : 0;
      commentsTab.textContent = `Comments (${count})`;
    }

    // Update comments list
    const commentsList = document.getElementById("mCommentsList");
    if (!commentsList) return;

    if (!data.comments || data.comments.length === 0) {
      commentsList.style.display = "none";
      commentsList.innerHTML = "";
      return;
    }

    commentsList.style.display = "block";
    commentsList.innerHTML = data.comments
      .map((c) => renderMobileComment(c))
      .join("");

    // Re-attach comment listeners
    attachMobileCommentListeners();
  }

  /**
   * Render a single mobile comment
   */
  function renderMobileComment(c) {
    const indent = Math.min(c.depth || 0, 2) * 20;
    const tc = c.start_tc || "";
    const author = c.author_name || "Unknown";
    const body = c.body || "";
    const uuid = c.comment_uuid || c.id || "";

    return `
            <div class="m-comment-item" style="margin-left: ${indent}px;">
                <div class="m-comment-header">
                    <span class="m-comment-author">${escapeHtml(author)}</span>
                    <div class="m-comment-meta">
                        <span class="m-comment-tc">${escapeHtml(tc)}</span>
                        <button type="button" class="m-comment-reply-btn"
                            onclick="setupMobileReply('${escapeHtml(uuid)}', '${escapeHtml(author).replace(/'/g, "\\'")}')">REPLY</button>
                    </div>
                </div>
                <div class="m-comment-body">${escapeHtml(body).replace(/\n/g, "<br>")}</div>
            </div>
        `;
  }

  /**
   * Re-attach comment listeners
   */
  function attachMobileCommentListeners() {
    // TC jump buttons (if you have them)
    document.querySelectorAll(".m-comment-tc").forEach((el) => {
      if (el.textContent.trim() && window.commentTcToSeconds) {
        el.style.cursor = "pointer";
        el.addEventListener("click", () => {
          const seconds = window.commentTcToSeconds(el.textContent.trim());
          vid.currentTime = seconds;
          vid.pause();
        });
      }
    });
  }

  /**
   * Update which clip is active
   */
  function updateMobileActiveClip(newActiveElement) {
    document.querySelectorAll(".mobile-clip-link.is-active").forEach((el) => {
      el.classList.remove("is-active");
    });

    if (newActiveElement) {
      newActiveElement.classList.add("is-active");
    }
  }

  /**
   * Update browser history
   */
  function updateBrowserHistory(url, data) {
    const state = {
      clipUuid: data.clip.clip_uuid,
      dayUuid: data.day.uuid,
      projectUuid: data.project.uuid,
    };

    window.history.pushState(state, "", url);
  }

  /**
   * Re-initialize mobile player UI
   */
  function reinitializeMobilePlayerUI(data) {
    const { clip } = data;

    // Reset timecode display
    const tcDisplay = document.getElementById("mTcDisplay");
    if (tcDisplay) {
      tcDisplay.textContent = clip.tc_start || "00:00:00:00";
    }

    // Reset scrubber
    const scrubber = document.getElementById("mScrub");
    if (scrubber) {
      scrubber.value = "0";
    }

    // Clear comment form
    const commentText = document.getElementById("mCommentText");
    const commentTc = document.getElementById("mCommentTc");
    const parentUuid = document.getElementById("mParentUuid");

    if (commentText) commentText.value = "";
    if (commentTc) commentTc.value = "";
    if (parentUuid) parentUuid.value = "";

    // Cancel reply mode if active
    if (window.cancelMobileReply) {
      window.cancelMobileReply();
    }

    // Reset play/pause icon
    const iconWrap = document.getElementById("mPlayIconContainer");
    if (iconWrap && window.updatePlayPauseUI) {
      window.updatePlayPauseUI();
    }
  }

  /**
   * HTML escape utility
   */
  function escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = text;
    return div.innerHTML;
  }

  // ============================================================
  // INTERCEPT CLIP CLICKS
  // ============================================================

  document.addEventListener("click", function (e) {
    const clipLink = e.target.closest(".mobile-clip-link");
    if (!clipLink) return;

    // Don't intercept if already active
    if (clipLink.classList.contains("is-active")) {
      e.preventDefault();
      return;
    }

    // Intercept the click
    e.preventDefault();

    const url = clipLink.getAttribute("href");
    if (!url) return;

    // IMPORTANT: Save scroll position before Ajax
    const clipNav = document.querySelector(".mobile-clip-nav");
    const savedScrollTop = clipNav ? clipNav.scrollTop : 0;

    // Load via Ajax and restore scroll position
    loadClipAjax(url, clipLink, savedScrollTop);
  });

  // ============================================================
  // HANDLE BROWSER BACK/FORWARD
  // ============================================================

  window.addEventListener("popstate", function (event) {
    if (event.state && event.state.clipUuid) {
      const url = `/admin/projects/${event.state.projectUuid}/days/${event.state.dayUuid}/player/${event.state.clipUuid}`;
      const clipElement = document.querySelector(
        `.mobile-clip-link[href="${url}"]`,
      );
      loadClipAjax(url, clipElement);
    } else {
      window.location.reload();
    }
  });
});
