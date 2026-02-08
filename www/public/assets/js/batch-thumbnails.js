/**
 * Batch Thumbnail Loader
 * Loads all thumbnails in a single API request instead of N requests
 */

export class BatchThumbnailLoader {
  constructor() {
    this.placeholder = "/assets/img/placeholder.png"; // Update with your actual placeholder
  }

  /**
   * Load all thumbnails for clips visible on the page
   */
  async loadThumbnails() {
    // Find all thumbnail cells that need loading
    const thumbCells = document.querySelectorAll(".thumb-cell");

    if (thumbCells.length === 0) {
      return;
    }

    // Collect clip UUIDs
    const clipUuids = [];
    const cellMap = new Map(); // Map UUID to DOM elements

    thumbCells.forEach((cell) => {
      const row = cell.closest("tr");
      if (!row) return;

      const clipUuid = row.dataset.clipUuid;
      if (!clipUuid) return;

      clipUuids.push(clipUuid);

      if (!cellMap.has(clipUuid)) {
        cellMap.set(clipUuid, []);
      }
      cellMap.get(clipUuid).push(cell);
    });

    if (clipUuids.length === 0) {
      return;
    }

    // Get project and day UUIDs from page
    const pageEl = document.querySelector(".zd-clips-page");
    if (!pageEl) {
      console.error("Could not find .zd-clips-page element");
      return;
    }

    const projectUuid = pageEl.dataset.project;
    const dayUuid = pageEl.dataset.day;

    // Build API URL
    const url = `/admin/projects/${projectUuid}/days/${dayUuid}/clips/thumbnails?clip_uuids=${clipUuids.join(",")}`;

    console.log("Batch thumbnail loader - fetching:", url);
    console.log("Clip UUIDs:", clipUuids);

    try {
      const response = await fetch(url);

      if (!response.ok) {
        console.error(
          "Failed to load thumbnails:",
          response.status,
          response.statusText,
        );
        const text = await response.text();
        console.error("Response:", text);
        return;
      }

      const thumbnails = await response.json();
      console.log("Received thumbnails:", thumbnails);

      // Update DOM with loaded thumbnails
      for (const [clipUuid, posterPath] of Object.entries(thumbnails)) {
        const cells = cellMap.get(clipUuid);
        if (!cells) continue;

        cells.forEach((cell) => {
          this.updateThumbnail(cell, posterPath);
        });
      }
    } catch (error) {
      console.error("Error loading batch thumbnails:", error);
    }
  }

  /**
   * Update a thumbnail cell with the loaded image
   */
  updateThumbnail(cell, posterPath) {
    const img = cell.querySelector(".zd-thumb");

    if (!img) {
      // No img tag exists, create one
      if (posterPath) {
        const newImg = document.createElement("img");
        newImg.className = "zd-thumb";
        newImg.src = posterPath;
        newImg.alt = "";

        // Remove skeleton if it exists
        const skeleton = cell.querySelector(".zd-thumb-skeleton");
        if (skeleton) {
          skeleton.remove();
        }

        cell.appendChild(newImg);
        console.log("Created new img for", posterPath);
      }
      return;
    }

    // Update existing img
    if (posterPath) {
      img.src = posterPath;
      img.style.opacity = "0";

      // Fade in when loaded
      img.onload = () => {
        img.style.transition = "opacity 0.2s ease-in";
        img.style.opacity = "1";
      };

      console.log("Updated img src to", posterPath);
    } else {
      // No thumbnail available, use placeholder
      img.src = this.placeholder;
      console.log("No thumbnail found, using placeholder");
    }
  }
}

// Auto-initialize when DOM is ready
if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", initBatchThumbnails);
} else {
  initBatchThumbnails();
}

function initBatchThumbnails() {
  console.log("Initializing batch thumbnail loader...");
  const loader = new BatchThumbnailLoader();
  loader.loadThumbnails();
}
