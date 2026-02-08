// player-ui.js — wires custom controls without touching your existing tc/framestep logic
document.addEventListener("DOMContentLoaded", () => {
  const vid = document.getElementById("zdVideo");
  if (!vid) return;

  const btnPlayPause = document.getElementById("btnPlayPause");
  const btnStepBack = document.getElementById("btnStepBack");
  const btnStepFwd = document.getElementById("btnStepFwd");

  // === FPS (single source of truth) ===
  const fpsNum = Number(vid.dataset.fpsnum) || 0;
  const fpsDen = Number(vid.dataset.fpsden) || 0;
  const fpsExact =
    fpsNum > 0 && fpsDen > 0
      ? fpsNum / fpsDen
      : Number((vid.dataset.fps || "").replace(",", ".")) || 24; // hard fallback for safety
  const fpsInt = Math.round(fpsExact);
  console.debug("FPS dataset ?", {
    fps: vid.dataset.fps,
    fpsNum: vid.dataset.fpsnum,
    fpsDen: vid.dataset.fpsden,
    fpsExact,
  });

  const frameDur = 1 / fpsExact; // for time stepping
  const isDrop =
    Math.abs(fpsExact - 29.97) < 0.02 || Math.abs(fpsExact - 59.94) < 0.02;
  const SEP = isDrop ? ";" : ":";
  const EPS = 1e-6; // rounding guard
  const timeToFrame = (t) => Math.floor((t || 0) * fpsExact + EPS);
  const frameToTime = (n) => n / fpsExact;

  // === Timecode inline editor ===
  {
    const tcChip = document.getElementById("tcChip");
    if (!tcChip) {
      // nothing to wire
    } else {
      // ---- TC helpers (same math as your overlay) ----
      const pad2 = (n) => String(n).padStart(2, "0");

      function tcToFramesND(tc) {
        const m = /^(\d{2}):(\d{2}):(\d{2})[:;](\d{2})$/.exec(tc || "");
        if (!m) return 0;
        const [_, hh, mm, ss, ff] = m.map(Number);
        return ((hh * 60 + mm) * 60 + ss) * fpsInt + Math.min(fpsInt - 1, ff);
      }

      function framesToTcND(fr) {
        let f = Math.max(0, Math.round(fr));
        const ff = f % fpsInt;
        f = (f - ff) / fpsInt;
        const ss = f % 60;
        f = (f - ss) / 60;
        const mm = f % 60;
        const hh = (f - mm) / 60;
        return `${pad2(hh)}:${pad2(mm)}:${pad2(ss)}:${pad2(ff)}`;
      }

      function tcToFramesDF(tc) {
        const m = /^(\d{2}):(\d{2}):(\d{2})[:;](\d{2})$/.exec(tc || "");
        if (!m) return 0;
        const [_, hh, mm, ss, ff] = m.map(Number);
        const df = Math.round(fpsInt / 15); // 2 for 29.97, 4 for 59.94
        const totalMin = hh * 60 + mm;
        const dropped = df * (totalMin - Math.floor(totalMin / 10));
        return (
          ((hh * 60 + mm) * 60 + ss) * fpsInt +
          Math.min(fpsInt - 1, ff) -
          dropped
        );
      }

      function framesToTcDF(fr) {
        const df = Math.round(fpsInt / 15);
        const FPH = fpsInt * 3600,
          FP10 = fpsInt * 600,
          FPM = fpsInt * 60;
        let f = Math.max(0, Math.round(fr));
        const d =
          Math.floor(f / (FP10 - df * 9)) * 9 * df +
          Math.max(0, Math.floor((f % (FP10 - df * 9)) / (FPM - df))) * df;
        let a = f + d;
        const hh = Math.floor(a / FPH);
        a -= hh * FPH;
        const mm = Math.floor(a / FPM);
        a -= mm * FPM;
        const ss = Math.floor(a / fpsInt);
        const ff = a % fpsInt;
        return `${pad2(hh)}:${pad2(mm)}:${pad2(ss)}${SEP}${pad2(ff)}`;
      }

      const parseTC = (tc) => (isDrop ? tcToFramesDF(tc) : tcToFramesND(tc));
      const fmtTC = (fr) => (isDrop ? framesToTcDF(fr) : framesToTcND(fr));

      let startTC = (vid.dataset.tcStart || "00:00:00:00").trim();
      let startFrames = parseTC(startTC);

      // [ADD] Global function to refresh these values when Ajax loads a new clip
      window.refreshPlayerTcGlobals = () => {
        startTC = (vid.dataset.tcStart || "00:00:00:00").trim();
        startFrames = parseTC(startTC);
        paint(); // force immediate redraw of the chip
      };

      function currentChipTC() {
        const curAbs = startFrames + timeToFrame(vid.currentTime || 0);
        return fmtTC(curAbs);
      }

      // keep chip in sync while playing
      const paint = () => {
        tcChip.textContent = currentChipTC();
      };
      vid.addEventListener("timeupdate", paint);
      vid.addEventListener("loadedmetadata", paint);
      paint();

      // === Comment "Use current" + click-to-jump (same TC as chip) ===
      const commentTcInput = document.getElementById("comment_start_tc");
      const btnCommentUseTc = document.getElementById("btnCommentUseTc");

      // Use the same FPS / drop-frame settings as the chip
      const commentFps = fpsExact || 24;

      function commentTcToSeconds(tc) {
        if (!tc) return 0;

        // Absolute frames of comment TC
        const absFrames = parseTC(tc);
        // Offset from clip start
        let offsetFrames = absFrames - startFrames;
        if (!Number.isFinite(offsetFrames) || offsetFrames < 0) {
          offsetFrames = 0;
        }

        return offsetFrames / commentFps;
      }

      // "Use current" fills the field with the chip's current TC
      if (commentTcInput && btnCommentUseTc) {
        btnCommentUseTc.addEventListener("click", () => {
          // [FIX] Just grab the text from the chip. It is the source of truth.
          const tc = tcChip.textContent.trim();
          commentTcInput.value = tc;
          commentTcInput.focus();
          commentTcInput.select();
        });
      }

      // Clicking a comment TC jumps the player
      document.querySelectorAll(".zd-comment-tc").forEach((btn) => {
        btn.addEventListener("click", () => {
          const tc = btn.dataset.tc;
          if (!tc) return;

          const seconds = commentTcToSeconds(tc);
          // Seek to the middle of the frame window to avoid landing on previous frame
          vid.currentTime = seconds + frameDur * 0.5;
          vid.pause();
        });
      });

      // --- build a single input next to the chip (hidden until edit) ---
      const input = document.createElement("input");
      input.type = "text";
      input.className = "zd-tc-input";
      input.autocomplete = "off";
      input.spellcheck = false;
      input.inputMode = "numeric";
      input.maxLength = 11; // "HH:MM:SS:FF"
      input.style.display = "none";
      tcChip.insertAdjacentElement("afterend", input);

      // We use a fixed mask "HH:MM:SS:FF" and overwrite digits.
      // Writable indexes: 0,1  3,4  6,7  9,10  (skip 2,5,8 which are separators)
      const WRITABLE = [0, 1, 3, 4, 6, 7, 9, 10];

      function normalizeMask(s) {
        // keep exactly the mask length and separators
        let raw = (s || "").replace(/\D+/g, "").slice(0, 8).padEnd(8, "0");
        const hh = raw.slice(0, 2),
          mm = raw.slice(2, 4),
          ss = raw.slice(4, 6),
          ff = raw.slice(6, 8);
        const mmC = pad2(Math.min(59, +mm));
        const ssC = pad2(Math.min(59, +ss));
        const ffC = pad2(Math.min(fpsInt - 1, +ff));

        return `${pad2(+hh)}:${mmC}:${ssC}${SEP}${ffC}`;
      }

      function beginEdit() {
        // snapshot live TC, put into input, place caret at HH start
        const val = currentChipTC();
        input.value = val;
        tcChip.style.display = "none";
        input.style.display = "";
        input.focus();
        input.setSelectionRange(0, 2);
      }

      function endEdit(commit) {
        if (commit) {
          // seek to entered TC (absolute) converted to clip-relative
          const entered = input.value;
          const absFrames = parseTC(entered);
          let rel = absFrames - startFrames;
          let sec = rel / fpsExact;

          if (Number.isFinite(vid.duration)) {
            sec = Math.max(0, Math.min(vid.duration - 0.001, sec));
          } else {
            sec = Math.max(0, sec);
          }
          vid.currentTime = sec;
        }
        // On exit (commit or cancel), show live playhead TC again
        input.style.display = "none";
        tcChip.style.display = "";
        tcChip.textContent = currentChipTC();
      }

      // --- Poster Grab Logic ---
      const btnGrabPoster = document.getElementById("btnGrabPoster");
      const flashEl = document.getElementById("zdFlash");

      if (btnGrabPoster && vid && flashEl) {
        btnGrabPoster.addEventListener("click", async () => {
          const layoutRoot = document.querySelector(".player-layout");
          const pUuid = layoutRoot?.dataset.projectUuid;
          const dUuid = layoutRoot?.dataset.dayUuid;
          const cUuid = layoutRoot?.dataset.clipUuid;

          if (!pUuid || !dUuid || !cUuid) return;

          // 1. LOCATE THE THUMBNAIL & ADD SPINNER
          const activeItem =
            document.querySelector(`.clip-item.is-active`) ||
            document.querySelector(`.clip-item[href*="${cUuid}"]`);

          let spinnerEl = null;

          if (activeItem) {
            const thumbWrap = activeItem.querySelector(".thumb-wrap");
            if (thumbWrap) {
              spinnerEl = document.createElement("div");
              spinnerEl.className = "thumb-loading-overlay";
              spinnerEl.innerHTML = '<div class="thumb-spinner"></div>';
              thumbWrap.appendChild(spinnerEl);
            }
          }

          // 2. INSTANT WHITE FRAME (Visual feedback on Player)
          flashEl.style.transition = "none";
          flashEl.style.opacity = "1";

          requestAnimationFrame(() => {
            requestAnimationFrame(() => {
              flashEl.style.transition = "opacity 0.240s ease-out";
              flashEl.style.opacity = "0";
            });
          });

          // Disable button
          btnGrabPoster.disabled = true;
          btnGrabPoster.style.opacity = "0.5";

          try {
            const response = await fetch(
              `/admin/projects/${pUuid}/days/${dUuid}/clips/${cUuid}/poster-from-time`,
              {
                method: "POST",
                headers: {
                  "Content-Type": "application/json",
                  "X-CSRF-TOKEN":
                    document.querySelector('input[name="_csrf"]')?.value || "",
                },
                body: JSON.stringify({
                  seconds: vid.currentTime,
                }),
              },
            );

            const result = await response.json();

            if (response.ok && result.ok) {
              // 3. Update Sidebar Thumbnail Immediately
              if (activeItem) {
                const thumb = activeItem.querySelector(".thumb-wrap img");
                if (thumb) {
                  const freshUrl =
                    result.poster_url +
                    (result.poster_url.includes("?") ? "&" : "?") +
                    "v=" +
                    Date.now();

                  // Preload image so the spinner doesn't disappear before the new image is ready
                  const tempImg = new Image();
                  tempImg.onload = () => {
                    thumb.src = freshUrl;
                    // Remove spinner only after new image is loaded
                    if (spinnerEl) spinnerEl.remove();
                  };
                  tempImg.src = freshUrl;

                  // Safety: If image load fails or takes too long, remove spinner anyway
                  // (The 'finally' block below handles the generic removal, but this specific one is for the smooth transition)
                  return;
                }
              }
            } else {
              alert(
                "Failed to update poster: " + (result.error || "Unknown error"),
              );
            }
          } catch (err) {
            console.error("Poster grab error:", err);
          } finally {
            // Cleanup
            btnGrabPoster.disabled = false;
            btnGrabPoster.style.opacity = "1";

            // If we didn't hit the specific 'onload' path above (e.g. error), remove spinner here
            if (spinnerEl && spinnerEl.parentNode) {
              spinnerEl.remove();
            }
          }
        });
      }

      // --- Consolidated Waveform Logic ---
      const wfCanvas = document.getElementById("zdWaveformCanvas");
      const wfContainer = document.getElementById("zd-waveform-container");

      // Uses the 'vid' defined at the top of the script
      if (wfCanvas && wfContainer && vid) {
        const ctx = wfCanvas.getContext("2d");
        const progressLine = document.getElementById("zd-waveform-progress");

        // [NEW] Global hook for Ajax to call
        window.loadWaveform = function (newUrl) {
          if (!newUrl) {
            // If no URL (e.g. no audio), clear canvas
            ctx.clearRect(0, 0, wfCanvas.width, wfCanvas.height);
            return;
          }
          initWaveform(newUrl);
        };

        async function initWaveform(url) {
          try {
            // Abort previous fetch if needed? (For now, simple fetch is fine)
            const response = await fetch(url);
            if (!response.ok) return;
            const peaks = await response.json();

            // Adjust internal resolution for high-DPI displays
            wfCanvas.width = wfCanvas.offsetWidth * window.devicePixelRatio;
            wfCanvas.height = wfCanvas.offsetHeight * window.devicePixelRatio;

            const w = wfCanvas.width;
            const h = wfCanvas.height;
            const barWidth = w / peaks.length;

            ctx.clearRect(0, 0, w, h);
            ctx.fillStyle = "#3aa0ff"; // Zentropa Blue

            // Draw symmetrical bars centered vertically
            // --- BOOSTED WAVEFORM DRAWING ---
            const BOOST = 2.2;

            peaks.forEach((peak, i) => {
              const barHeight = Math.min(h, peak * h * BOOST);
              const x = i * barWidth;
              const y = (h - barHeight) / 2;

              ctx.globalAlpha = 0.8;
              ctx.fillRect(x, y, Math.max(1, barWidth - 1), barHeight);
            });
            ctx.globalAlpha = 1.0;
          } catch (err) {
            console.error("Waveform draw error:", err);
          }
        }

        // Single source for progress line and video time sync
        vid.addEventListener("timeupdate", () => {
          if (vid.duration) {
            const pct = (vid.currentTime / vid.duration) * 100;
            if (progressLine) progressLine.style.width = pct + "%";
          }
        });

        // --- Waveform Scrubbing (Drag to Seek) ---
        let isScrubbingWaveform = false;

        function seekToPosition(e) {
          const rect = wfContainer.getBoundingClientRect();
          const x = Math.max(0, Math.min(e.clientX - rect.left, rect.width));
          const pct = x / rect.width;
          if (Number.isFinite(vid.duration)) {
            vid.currentTime = pct * vid.duration;
          }
        }

        wfContainer.addEventListener("mousedown", (e) => {
          isScrubbingWaveform = true;
          seekToPosition(e);
        });

        window.addEventListener("mousemove", (e) => {
          if (isScrubbingWaveform) {
            e.preventDefault();
            seekToPosition(e);
          }
        });

        window.addEventListener("mouseup", () => {
          isScrubbingWaveform = false;
        });

        // Debounced redraw to keep the UI snappy during window changes
        let redrawTimer;
        window.addEventListener("resize", () => {
          clearTimeout(redrawTimer);
          // Use current dataset URL for resize redraws
          const currentUrl = wfContainer.dataset.waveformUrl;
          if (currentUrl) {
            redrawTimer = setTimeout(() => initWaveform(currentUrl), 150);
          }
        });

        // Initial Load
        const initialUrl = wfContainer.dataset.waveformUrl;
        if (initialUrl) initWaveform(initialUrl);
      }

      // Overwrite typing: digits only, move caret to next writable slot, skip separators
      input.addEventListener("keydown", (e) => {
        const key = e.key;

        if (key === "Enter") {
          e.preventDefault();
          return endEdit(true);
        }
        if (key === "Escape") {
          e.preventDefault();
          return endEdit(false);
        }

        if (key === "Tab") return; // allow tabbing out

        // Backspace: move left to previous writable slot and zero it
        if (key === "Backspace") {
          e.preventDefault();
          let i = Math.max(0, (input.selectionStart ?? 0) - 1);
          while (i > 0 && !WRITABLE.includes(i)) i--;
          const a = input.value.split("");
          if (WRITABLE.includes(i)) a[i] = "0";
          input.value = a.join("");
          input.setSelectionRange(i, i + 1);
          return;
        }

        // Digits: write at current caret (or next writable), then advance
        if (/^\d$/.test(key)) {
          e.preventDefault();
          let i = input.selectionStart ?? 0;
          // if caret is on a separator, jump to next writable
          while (i < 11 && !WRITABLE.includes(i)) i++;
          if (i >= 11) return; // end
          const a = input.value.split("");
          a[i] = key;
          input.value = a.join("");

          // clamp fields live so it never becomes invalid
          input.value = normalizeMask(input.value);

          // advance to next writable slot
          let j = i + 1;
          while (j < 11 && !WRITABLE.includes(j)) j++;
          input.setSelectionRange(Math.min(j, 11), Math.min(j, 11));
          return;
        }

        // Arrow keys: move between writable slots
        if (key === "ArrowLeft" || key === "ArrowRight") {
          e.preventDefault();
          let i = input.selectionStart ?? 0;
          if (key === "ArrowLeft") {
            i = Math.max(0, i - 1);
            while (i > 0 && !WRITABLE.includes(i)) i--;
          } else {
            i = Math.min(11, i + 1);
            while (i < 11 && !WRITABLE.includes(i)) i++;
          }
          input.setSelectionRange(i, i + 1);
          return;
        }

        // everything else inside the editor is blocked (keeps it “deck-like”)
        e.preventDefault();
      });

      // Ensure format if something pasted
      input.addEventListener("input", () => {
        input.value = normalizeMask(input.value);
      });

      // Start edit on chip click
      tcChip.addEventListener("click", beginEdit);

      // Clicking away should CANCEL and show live TC (no seek)
      input.addEventListener("blur", () => endEdit(false));
    }
  }

  const iconPlay = btnPlayPause?.querySelector('[data-state="play"]');
  const iconPause = btnPlayPause?.querySelector('[data-state="pause"]');

  const scrub = document.getElementById("scrubGlobal");

  const btnMute = document.getElementById("btnMute");
  const vol = document.getElementById("vol");

  const btnFS = document.getElementById("btnFS");

  const btnTheater = document.getElementById("btnTheater");
  const layoutRoot = document.querySelector(".player-layout");
  const playerMain = document.querySelector("section.player-main");
  const theaterEnterIcon = btnTheater?.querySelector('[data-state="enter"]');
  const theaterExitIcon = btnTheater?.querySelector('[data-state="exit"]');

  const frame = document.getElementById("playerFrame"); // for fullscreen target

  // --- Controls idle/show logic ---
  let idleTimer = 0;
  const IDLE_MS = 1800;

  function wakeControls() {
    if (!frame) return;

    // Show controls + cursor immediately on activity
    frame.classList.remove("controls-idle");
    frame.classList.remove("hide-cursor");

    clearTimeout(idleTimer);
    idleTimer = window.setTimeout(() => {
      if (!frame) return;
      // After idle: hide controls and cursor
      frame.classList.add("controls-idle");
      frame.classList.add("hide-cursor");
    }, IDLE_MS);
  }

  // wake on interactions within the frame
  ["mousemove", "pointerdown", "touchstart", "wheel", "keydown"].forEach(
    (ev) => {
      frame.addEventListener(ev, wakeControls, { passive: true });
    },
  );

  // start the timer once video metadata is ready (or immediately if you like)
  wakeControls();

  // --- helpers ---
  function updatePlayIcons() {
    if (!iconPlay || !iconPause) return;
    if (vid.paused) {
      iconPlay.style.display = "";
      iconPause.style.display = "none";
    } else {
      iconPlay.style.display = "none";
      iconPause.style.display = "";
    }
  }

  function syncScrubFromVideo() {
    if (!scrub) return;
    if (!Number.isFinite(vid.duration) || vid.duration <= 0) return;
    const ratio = vid.currentTime / vid.duration;
    scrub.value = String(Math.round(ratio * 1000));
    const pct = Math.round(ratio * 100);
    scrub.style.setProperty("--p", pct + "%");
  }

  // minimal stub (we no longer show MM:SS, but handlers still call it)
  function syncDuration() {
    /* no-op */
  }

  // --- Play/Pause ---
  btnPlayPause?.addEventListener("click", () => {
    if (vid.paused) vid.play();
    else vid.pause();
  });
  vid.addEventListener("play", updatePlayIcons);
  vid.addEventListener("pause", updatePlayIcons);
  updatePlayIcons();

  // Space toggles play/pause when focus is not in an input
  document.addEventListener("keydown", (e) => {
    const active = document.activeElement;
    if (
      active &&
      (active.tagName === "INPUT" ||
        active.tagName === "TEXTAREA" ||
        active.isContentEditable)
    ) {
      return;
    }

    if (e.code === "Space") {
      e.preventDefault();
      if (vid.paused) vid.play();
      else vid.pause();
    }
  });

  // --- Scrubber ---
  // user drags -> set time
  scrub?.addEventListener("input", () => {
    if (!Number.isFinite(vid.duration) || vid.duration <= 0) return;
    const ratio = Math.max(0, Math.min(1, (+scrub.value || 0) / 1000));
    vid.currentTime = ratio * vid.duration;
  });

  // update from video
  vid.addEventListener("timeupdate", syncScrubFromVideo);
  vid.addEventListener("seeked", syncScrubFromVideo);
  vid.addEventListener("loadedmetadata", () => {
    syncDuration();
    syncScrubFromVideo();
  });
  vid.addEventListener("durationchange", syncDuration);

  // --- Frame step buttons (now in the main bar) ---
  function step(delta) {
    vid.pause();
    const t = Math.max(0, (vid.currentTime || 0) + delta);
    vid.currentTime = t;
  }
  btnStepFwd?.addEventListener("click", () => step(frameDur));
  btnStepBack?.addEventListener("click", () => step(-frameDur));

  // Optional: Shift+Arrow keys keep working
  document.addEventListener("keydown", (e) => {
    const active = document.activeElement;
    if (
      active &&
      (active.tagName === "INPUT" ||
        active.tagName === "TEXTAREA" ||
        active.isContentEditable)
    ) {
      return;
    }

    if (!e.shiftKey) return;
    if (e.code === "ArrowRight") {
      e.preventDefault();
      step(frameDur);
    }
    if (e.code === "ArrowLeft") {
      e.preventDefault();
      step(-frameDur);
    }
  });

  // --- Click video to toggle play/pause ---
  vid.addEventListener(
    "click",
    () => {
      if (vid.paused) vid.play();
      else vid.pause();
    },
    true,
  ); // capture phase as well

  // --- Volume / Mute ---
  // --- Volume / Mute ---
  // Remember last non-zero volume so unmute feels natural
  // --- Volume / Mute (Stored in localStorage) ---
  const savedVol = localStorage.getItem("zd_player_vol");
  const initialVol = savedVol !== null ? parseFloat(savedVol) : 0.8;

  // Apply initial volume to video and slider
  vid.volume = initialVol;
  if (vol) vol.value = String(initialVol);

  let lastVol = initialVol > 0 ? initialVol : 0.5;

  // Helper to update UI icons based on state
  function updateVolIcon() {
    if (!btnMute) return;
    const currentVol = vid.muted ? 0 : vid.volume;
    if (currentVol === 0) btnMute.textContent = "🔇";
    else if (currentVol < 0.5) btnMute.textContent = "🔉";
    else btnMute.textContent = "🔊";
  }

  // Handle slider movement
  if (vol) {
    vol.addEventListener("input", () => {
      const v = Math.max(0, Math.min(1, parseFloat(vol.value) || 0));
      vid.volume = v;

      if (v > 0) {
        lastVol = v;
        vid.muted = false;
        localStorage.setItem("zd_player_vol", v);
      } else {
        vid.muted = true;
      }
      updateVolIcon();
    });
  }

  // Handle Mute button click
  btnMute?.addEventListener("click", () => {
    vid.muted = !vid.muted;

    if (!vid.muted) {
      const restored = lastVol > 0 ? lastVol : 0.5;
      vid.volume = restored;
      if (vol) vol.value = String(restored);
      localStorage.setItem("zd_player_vol", restored);
    }
    updateVolIcon();
  });

  // keep the icon accurate on load + when video/source changes
  vid.addEventListener("loadedmetadata", updateVolIcon);
  vid.addEventListener("volumechange", updateVolIcon);
  updateVolIcon();

  // --- Fullscreen ---
  btnFS?.addEventListener("click", () => {
    const el = frame || vid;

    const enter =
      el.requestFullscreen ||
      el.webkitRequestFullscreen ||
      el.msRequestFullscreen;

    const exit =
      document.exitFullscreen ||
      document.webkitExitFullscreen ||
      document.msExitFullscreen;

    if (
      !document.fullscreenElement &&
      !document.webkitFullscreenElement &&
      !document.msFullscreenElement
    ) {
      enter?.call(el);
    } else {
      exit?.call(document);
    }
  });

  function onFSChange() {
    const active =
      document.fullscreenElement ||
      document.webkitFullscreenElement ||
      document.msFullscreenElement;
    if (btnFS) btnFS.title = active ? "Exit Fullscreen" : "Fullscreen";
  }
  document.addEventListener("fullscreenchange", onFSChange);
  document.addEventListener("webkitfullscreenchange", onFSChange);
  document.addEventListener("MSFullscreenChange", onFSChange);

  // === Comment reply wiring ===
  const commentParentInput = document.getElementById("comment_parent_uuid");
  const commentBodyInput = document.getElementById("comment_body");
  const commentForm = commentBodyInput?.closest("form");
  const commentSubmitBtn = commentForm?.querySelector('button[type="submit"]');

  // Track original state
  const originalPlaceholder =
    commentBodyInput?.placeholder || "Add a note for this clip…";
  const originalSubmitText =
    commentSubmitBtn?.textContent?.trim() || "Add comment";

  // Create cancel button (hidden by default)
  let cancelBtn = null;
  if (commentSubmitBtn) {
    cancelBtn = document.createElement("button");
    cancelBtn.type = "button";
    cancelBtn.textContent = "Cancel";
    cancelBtn.style.cssText =
      "font-size:12px;font-weight:500;padding:6px 12px;border-radius:4px;border:1px solid var(--border);background:var(--bg);color:var(--text);cursor:pointer;margin-right:8px;display:none;";
    commentSubmitBtn.parentElement.insertBefore(cancelBtn, commentSubmitBtn);
  }

  function enterReplyMode(uuid, author) {
    if (!commentParentInput) return;

    commentParentInput.value = uuid;

    if (commentBodyInput && !commentBodyInput.value) {
      commentBodyInput.placeholder = author
        ? "Replying to " + author + "…"
        : "Replying…";
    }

    if (commentSubmitBtn) {
      commentSubmitBtn.textContent = "Add reply";
    }

    if (cancelBtn) {
      cancelBtn.style.display = "inline-block";
    }

    if (commentBodyInput) {
      commentBodyInput.focus();
      commentBodyInput.scrollIntoView({
        behavior: "smooth",
        block: "nearest",
      });
    }
  }

  function exitReplyMode() {
    if (commentParentInput) {
      commentParentInput.value = "";
    }

    if (commentBodyInput) {
      commentBodyInput.placeholder = originalPlaceholder;
    }

    if (commentSubmitBtn) {
      commentSubmitBtn.textContent = originalSubmitText;
    }

    if (cancelBtn) {
      cancelBtn.style.display = "none";
    }
  }

  // Cancel button handler
  if (cancelBtn) {
    cancelBtn.addEventListener("click", exitReplyMode);
  }

  // Reply button handlers
  document.querySelectorAll(".zd-comment-reply-btn").forEach((btn) => {
    btn.addEventListener("click", () => {
      const uuid = btn.dataset.commentUuid || "";
      const author = btn.dataset.authorName || "";
      enterReplyMode(uuid, author);
    });
  });

  // Reset reply mode after successful form submission
  if (commentForm) {
    commentForm.addEventListener("submit", () => {
      // Delay the reset to allow form submission to complete
      setTimeout(exitReplyMode, 100);
    });
  }

  // --- Aspect Ratio Blanking ---
  const selBlanking = document.getElementById("selBlanking");
  // No need to redeclare 'frame' since it's defined above as #playerFrame

  if (selBlanking && frame) {
    // 1. Get the Project Default from the data attribute
    const projectDefault = selBlanking.dataset.default || "none";

    // 2. Use the Project UUID to keep overrides project-specific
    const projectUuid = selBlanking.dataset.projectUuid || "global";
    const sessionKey = "playerBlanking_" + projectUuid;
    const sessionOverride = sessionStorage.getItem(sessionKey);

    // 3. Determine the starting ratio: Override wins, then Project Default
    const initialRatio = sessionOverride || projectDefault;

    // Apply the initial state to the UI and the player frame
    selBlanking.value = initialRatio;
    frame.dataset.blanking = initialRatio;

    // Handle manual changes by the user
    selBlanking.addEventListener("change", () => {
      const newValue = selBlanking.value;
      frame.dataset.blanking = newValue;

      // Save this as a manual override so it persists across clips in this project
      sessionStorage.setItem(sessionKey, newValue);
    });
  }

  window.addEventListener("beforeunload", () => {
    const vid = document.getElementById("zdVideo");
    if (vid) {
      vid.pause();
      vid.src = "";
      vid.load();
      vid.removeAttribute("src");
    }
  });

  document.querySelectorAll(".clip-item").forEach((link) => {
    link.addEventListener("click", () => {
      const vid = document.getElementById("zdVideo");
      if (vid) vid.pause();
    });
  });
});
