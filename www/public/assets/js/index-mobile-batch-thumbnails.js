/**
 * Mobile Index Batch Thumbnail Loader
 * Loads thumbnails for days and scenes on the mobile index page
 */

class MobileIndexBatchThumbnailLoader {
    constructor() {
        this.placeholder = '/assets/img/empty_day_placeholder.png';
    }
    
    /**
     * Load all thumbnails (days and scenes)
     */
    async loadAllThumbnails() {
        // Get project UUID from page
        const projectUuid = this.getProjectUuid();
        if (!projectUuid) {
            console.error('Mobile: Could not find project UUID');
            return;
        }
        
        // Load both in parallel
        await Promise.all([
            this.loadDayThumbnails(projectUuid),
            this.loadSceneThumbnails(projectUuid)
        ]);
    }
    
    /**
     * Extract project UUID from the page
     */
    getProjectUuid() {
        // Try to extract from day card URLs
        const dayCard = document.querySelector('.mobile-day-card');
        if (dayCard) {
            const href = dayCard.getAttribute('href');
            const match = href.match(/\/projects\/([a-f0-9-]+)\//);
            if (match) return match[1];
        }
        
        // Try to extract from scene card URLs
        const sceneCard = document.querySelector('.scene-card');
        if (sceneCard) {
            const href = sceneCard.getAttribute('href');
            const match = href.match(/\/projects\/([a-f0-9-]+)\//);
            if (match) return match[1];
        }
        
        return null;
    }
    
    /**
     * Load thumbnails for days
     */
    async loadDayThumbnails(projectUuid) {
        const dayCards = document.querySelectorAll('.mobile-day-card');
        if (dayCards.length === 0) return;
        
        const dayUuids = [];
        const dayMap = new Map();
        
        dayCards.forEach(card => {
            const href = card.getAttribute('href');
            if (!href) return;
            
            // Extract day UUID from: /admin/projects/{p}/days/{dayUuid}/player
            const match = href.match(/\/days\/([a-f0-9-]+)\//);
            if (!match) return;
            
            const dayUuid = match[1];
            dayUuids.push(dayUuid);
            dayMap.set(dayUuid, card);
        });
        
        if (dayUuids.length === 0) return;
        
        console.log('Mobile: Loading day thumbnails:', dayUuids.length);
        
        const url = `/admin/projects/${projectUuid}/player/days/thumbnails?day_uuids=${dayUuids.join(',')}`;
        
        try {
            const response = await fetch(url);
            if (!response.ok) {
                console.error('Mobile: Failed to load day thumbnails:', response.status);
                return;
            }
            
            const thumbnails = await response.json();
            console.log('Mobile: Received day thumbnails:', Object.keys(thumbnails).length);
            
            // Days don't have visible thumbnails in mobile index, but we could add them
            // For now, just log success
            
        } catch (error) {
            console.error('Mobile: Error loading day thumbnails:', error);
        }
    }
    
    /**
     * Load thumbnails for scenes
     */
    async loadSceneThumbnails(projectUuid) {
        const sceneCards = document.querySelectorAll('.scene-card');
        if (sceneCards.length === 0) return;
        
        const scenes = [];
        const sceneMap = new Map();
        
        sceneCards.forEach(card => {
            const img = card.querySelector('.scene-thumb img');
            if (!img) return;
            
            // Extract scene from URL: /overview?scene=1
            const href = card.getAttribute('href');
            const match = href.match(/scene=([^&]+)/);
            if (!match) return;
            
            const scene = decodeURIComponent(match[1]);
            scenes.push(scene);
            sceneMap.set(scene, img);
        });
        
        if (scenes.length === 0) return;
        
        console.log('Mobile: Loading scene thumbnails:', scenes.length);
        
        const url = `/admin/projects/${projectUuid}/player/scenes/thumbnails?scenes=${scenes.map(s => encodeURIComponent(s)).join(',')}`;
        
        try {
            const response = await fetch(url);
            if (!response.ok) {
                console.error('Mobile: Failed to load scene thumbnails:', response.status);
                return;
            }
            
            const thumbnails = await response.json();
            console.log('Mobile: Received scene thumbnails:', Object.keys(thumbnails).length);
            
            // Update scene thumbnails
            for (const [scene, posterPath] of Object.entries(thumbnails)) {
                const img = sceneMap.get(scene);
                if (!img || !posterPath) continue;
                
                img.src = posterPath;
                img.style.opacity = '0';
                img.onload = () => {
                    img.style.transition = 'opacity 0.2s ease-in';
                    img.style.opacity = '1';
                };
            }
            
        } catch (error) {
            console.error('Mobile: Error loading scene thumbnails:', error);
        }
    }
}

// Auto-initialize
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMobileIndexThumbnails);
} else {
    initMobileIndexThumbnails();
}

function initMobileIndexThumbnails() {
    console.log('Mobile: Initializing index batch thumbnail loader...');
    const loader = new MobileIndexBatchThumbnailLoader();
    loader.loadAllThumbnails();
}
