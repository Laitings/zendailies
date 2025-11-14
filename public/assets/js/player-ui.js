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

      const startTC = (vid.dataset.tcStart || "00:00:00:00").trim();
      const startFrames = parseTC(startTC);

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

  const THEATER_KEY = "playerTheaterMode";

  function setTheater(on) {
    if (!layoutRoot) return;
    layoutRoot.classList.toggle("is-theater", !!on);

    // swap button glyphs
    if (theaterEnterIcon && theaterExitIcon) {
      theaterEnterIcon.style.display = on ? "none" : "";
      theaterExitIcon.style.display = on ? "" : "none";
    }
  }

  // restore last state
  setTheater(sessionStorage.getItem(THEATER_KEY) === "1");

  // wire button
  btnTheater?.addEventListener("click", () => {
    const now = !layoutRoot.classList.contains("is-theater");
    setTheater(now);
    sessionStorage.setItem(THEATER_KEY, now ? "1" : "0");
  });

  const frame = document.getElementById("playerFrame"); // for fullscreen target

  // --- Controls idle/show logic ---
  let idleTimer = 0;
  const IDLE_MS = 1800;

  function wakeControls() {
    if (!frame) return;
    frame.classList.remove("controls-idle");
    clearTimeout(idleTimer);
    idleTimer = window.setTimeout(() => {
      frame.classList.add("controls-idle");
    }, IDLE_MS);
  }

  // wake on interactions within the frame
  ["mousemove", "pointerdown", "touchstart", "wheel", "keydown"].forEach(
    (ev) => {
      frame.addEventListener(ev, wakeControls, { passive: true });
    }
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
    if (
      e.code === "Space" &&
      !["INPUT", "TEXTAREA"].includes(document.activeElement?.tagName || "")
    ) {
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
    true
  ); // capture phase as well

  // --- Volume / Mute ---
  // --- Volume / Mute ---
  // Remember last non-zero volume so unmute feels natural
  let lastVol = vid.volume && vid.volume > 0 ? vid.volume : 0.5;

  if (vol) {
    vol.value = String(vid.volume);

    vol.addEventListener("input", () => {
      const raw = parseFloat(vol.value);
      const v = Math.max(0, Math.min(1, Number.isFinite(raw) ? raw : 1));

      vid.volume = v;
      if (v > 0) {
        lastVol = v;
        vid.muted = false;
      } else {
        vid.muted = true;
      }

      if (btnMute) {
        btnMute.textContent = vid.muted || vid.volume === 0 ? "🔇" : "🔊";
      }
    });
  }

  btnMute?.addEventListener("click", () => {
    // Toggle muted state
    const nowMuted = !vid.muted;
    vid.muted = nowMuted;

    if (!nowMuted) {
      // Unmuting: restore last non-zero volume (or fallback 0.5)
      const restored = lastVol > 0 ? lastVol : 0.5;
      vid.volume = restored;
      if (vol) vol.value = String(restored);
    } else {
      // Muting: don't force slider to 0, but icon reflects muted state
    }

    if (btnMute) {
      btnMute.textContent = vid.muted || vid.volume === 0 ? "🔇" : "🔊";
    }
  });

  // initialize mute icon correctly on load
  if (btnMute) {
    btnMute.textContent = vid.muted || vid.volume === 0 ? "🔇" : "🔊";
  }

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
});
