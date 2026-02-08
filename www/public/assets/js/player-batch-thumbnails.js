/**
 * Player Batch Thumbnail Loader
 * Loads thumbnails for clips, days, and scenes in the player sidebar
 */

export class PlayerBatchThumbnailLoader {
  constructor() {
    // [CHANGE] Point to your new stylish SVG
    this.placeholder = "/assets/img/poster_placeholder.svg";
  }

  /**
   * Helper: Safely set image source with error handling
   * This fixes the issue where JS overwrites the placeholder with a broken link
   * without re-attaching the error listener.
   */
  setImageSource(img, url) {
    if (!img || !url) return;

    // 1. Re-arm the error listener (in case PHP removed it)
    img.onerror = () => {
      img.onerror = null; // Prevent infinite loops
      img.src = this.placeholder;
    };

    // 2. Set the src (stripping old query params and adding new cache buster)
    const cleanPath = url.split("?")[0];
    img.src = `${cleanPath}?v=${Date.now()}`;
  }

  /**
   * Load all thumbnails on the player page
   */
  async loadAllThumbnails() {
    const playerLayout = document.querySelector(".player-layout");
    if (!playerLayout) {
      console.error("Could not find .player-layout element");
      return;
    }

    const projectUuid = playerLayout.dataset.projectUuid;
    const dayUuid = playerLayout.dataset.dayUuid;

    if (!projectUuid) {
      console.error("Missing project UUID");
      return;
    }

    // Load thumbnails for each section
    await Promise.all([
      this.loadClipThumbnails(projectUuid, dayUuid),
      this.loadDayThumbnails(projectUuid),
      this.loadSceneThumbnails(projectUuid),
    ]);
  }

  /**
   * Load thumbnails for clips in the sidebar
   */
  async loadClipThumbnails(projectUuid, dayUuid) {
    const clipItems = document.querySelectorAll(".clip-item");
    if (clipItems.length === 0) return;

    const clipUuids = [];
    const clipMap = new Map();

    clipItems.forEach((item) => {
      const href = item.getAttribute("href");
      if (!href) return;

      const match = href.match(/\/player\/([a-f0-9-]+)/);
      if (!match) return;

      const clipUuid = match[1];
      clipUuids.push(clipUuid);
      clipMap.set(clipUuid, item);
    });

    if (clipUuids.length === 0) return;

    // Build API URL
    const url = `/admin/projects/${projectUuid}/days/${dayUuid}/clips/thumbnails?clip_uuids=${clipUuids.join(",")}`;

    try {
      const response = await fetch(url);
      if (!response.ok) return;

      const thumbnails = await response.json();

      // Update thumbnails
      for (const [clipUuid, posterPath] of Object.entries(thumbnails)) {
        const item = clipMap.get(clipUuid);
        if (!item) continue;

        const img = item.querySelector(".thumb-wrap img");
        // [CHANGE] Use helper
        this.setImageSource(img, posterPath);
      }
    } catch (error) {
      console.error("Error loading clip thumbnails:", error);
    }
  }

  /**
   * Load thumbnails for days in the navigation
   */
  async loadDayThumbnails(projectUuid) {
    const dayItems = document.querySelectorAll(".day-item:not(.scene-item)");
    if (dayItems.length === 0) return;

    const dayUuids = [];
    const dayMap = new Map();

    dayItems.forEach((item) => {
      const dayUuid = item.dataset.dayUuid;
      if (!dayUuid) return;

      dayUuids.push(dayUuid);
      dayMap.set(dayUuid, item);
    });

    if (dayUuids.length === 0) return;

    const url = `/admin/projects/${projectUuid}/player/days/thumbnails?day_uuids=${dayUuids.join(",")}`;

    try {
      const response = await fetch(url);
      if (!response.ok) return;

      const thumbnails = await response.json();

      for (const [dayUuid, posterPath] of Object.entries(thumbnails)) {
        const item = dayMap.get(dayUuid);
        if (!item) continue;

        const img = item.querySelector(".day-thumb img");
        // [CHANGE] Use helper
        this.setImageSource(img, posterPath);
      }
    } catch (error) {
      console.error("Error loading day thumbnails:", error);
    }
  }

  /**
   * Load thumbnails for scenes in the navigation
   */
  async loadSceneThumbnails(projectUuid) {
    const sceneItems = document.querySelectorAll(".scene-item");
    if (sceneItems.length === 0) return;

    const scenes = [];
    const sceneMap = new Map();

    sceneItems.forEach((item) => {
      const scene = item.dataset.scene;
      if (!scene) return;

      scenes.push(scene);
      sceneMap.set(scene, item);
    });

    if (scenes.length === 0) return;

    const url = `/admin/projects/${projectUuid}/player/scenes/thumbnails?scenes=${scenes.map((s) => encodeURIComponent(s)).join(",")}`;

    try {
      const response = await fetch(url);
      if (!response.ok) return;

      const thumbnails = await response.json();

      for (const [scene, posterPath] of Object.entries(thumbnails)) {
        const item = sceneMap.get(scene);
        if (!item) continue;

        const img = item.querySelector(".day-thumb img");
        // [CHANGE] Use helper
        this.setImageSource(img, posterPath);
      }
    } catch (error) {
      console.error("Error loading scene thumbnails:", error);
    }
  }
}

// Auto-initialize
if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", initPlayerThumbnails);
} else {
  initPlayerThumbnails();
}

function initPlayerThumbnails() {
  const loader = new PlayerBatchThumbnailLoader();
  loader.loadAllThumbnails();
}
