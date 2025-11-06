// player-ui.js — wires custom controls without touching your existing tc/framestep logic
document.addEventListener("DOMContentLoaded", () => {
  const vid = document.getElementById("zdVideo");
  if (!vid) return;

  const btnPlayPause = document.getElementById("btnPlayPause");
  const iconPlay = btnPlayPause?.querySelector('[data-state="play"]');
  const iconPause = btnPlayPause?.querySelector('[data-state="pause"]');

  const scrub = document.getElementById("scrub");
  const timeCur = document.getElementById("timeCur");
  const timeDur = document.getElementById("timeDur");

  const btnMute = document.getElementById("btnMute");
  const vol = document.getElementById("vol");

  const btnFS = document.getElementById("btnFS");
  const frame = document.getElementById("playerFrame"); // for fullscreen target

  // --- helpers ---
  const fmt = (secs) => {
    if (!Number.isFinite(secs)) return "00:00";
    secs = Math.max(0, Math.floor(secs));
    const m = Math.floor(secs / 60);
    const s = secs % 60;
    return `${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`;
  };

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
    if (timeCur) timeCur.textContent = fmt(vid.currentTime);
  }

  function syncDuration() {
    if (timeDur)
      timeDur.textContent = Number.isFinite(vid.duration)
        ? fmt(vid.duration)
        : "00:00";
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

  // --- Volume / Mute ---
  if (vol) {
    vol.value = String(vid.volume);
    vol.addEventListener("input", () => {
      const v = Math.max(0, Math.min(1, parseFloat(vol.value)));
      vid.volume = Number.isFinite(v) ? v : 1;
      if (vid.volume === 0) vid.muted = true;
      if (vid.volume > 0 && vid.muted) vid.muted = false;
      btnMute &&
        (btnMute.textContent = vid.muted || vid.volume === 0 ? "🔇" : "🔊");
    });
  }
  btnMute?.addEventListener("click", () => {
    vid.muted = !vid.muted;
    if (!vid.muted && vol) {
      if (parseFloat(vol.value) === 0) {
        vol.value = "0.5";
        vid.volume = 0.5;
      }
    }
    btnMute.textContent = vid.muted || vid.volume === 0 ? "🔇" : "🔊";
  });
  // initialize icon
  btnMute &&
    (btnMute.textContent = vid.muted || vid.volume === 0 ? "🔇" : "🔊");

  // --- Fullscreen ---
  btnFS?.addEventListener("click", async () => {
    const el = frame || vid;
    if (!document.fullscreenElement) {
      try {
        await el.requestFullscreen?.();
      } catch {}
    } else {
      try {
        await document.exitFullscreen?.();
      } catch {}
    }
  });
  document.addEventListener("fullscreenchange", () => {
    btnFS &&
      (btnFS.title = document.fullscreenElement
        ? "Exit Fullscreen"
        : "Fullscreen");
  });
});
