/**
 * Mobile Player Batch Thumbnail Loader
 * Loads clip thumbnails for the mobile player sidebar
 */

class MobilePlayerBatchThumbnailLoader {
    constructor() {
        this.placeholder = '/assets/img/placeholder.png';
    }
    
    /**
     * Load all clip thumbnails in the mobile sidebar
     */
    async loadClipThumbnails() {
        const clipLinks = document.querySelectorAll('.mobile-clip-link');
        if (clipLinks.length === 0) {
            console.log('No clip links found');
            return;
        }
        
        const clipUuids = [];
        const clipMap = new Map();
        
        clipLinks.forEach(link => {
            // Extract clip UUID from href: /admin/projects/{p}/days/{d}/player/{clipUuid}
            const href = link.getAttribute('href');
            if (!href) return;
            
            const match = href.match(/\/player\/([a-f0-9-]+)/);
            if (!match) return;
            
            const clipUuid = match[1];
            clipUuids.push(clipUuid);
            clipMap.set(clipUuid, link);
        });
        
        if (clipUuids.length === 0) {
            console.log('No clip UUIDs extracted');
            return;
        }
        
        const playerLayout = document.querySelector('.player-layout');
        if (!playerLayout) {
            console.error('Could not find .player-layout element');
            return;
        }
        
        const projectUuid = playerLayout.dataset.projectUuid;
        const dayUuid = playerLayout.dataset.dayUuid;
        
        if (!projectUuid || !dayUuid) {
            console.error('Missing project or day UUID');
            return;
        }
        
        console.log('Mobile: Loading clip thumbnails:', clipUuids.length);
        
        const url = `/admin/projects/${projectUuid}/days/${dayUuid}/clips/thumbnails?clip_uuids=${clipUuids.join(',')}`;
        
        try {
            const response = await fetch(url);
            
            if (!response.ok) {
                console.error('Failed to load thumbnails:', response.status);
                return;
            }
            
            const thumbnails = await response.json();
            console.log('Mobile: Received thumbnails:', Object.keys(thumbnails).length);
            
            // Update thumbnails
            for (const [clipUuid, posterPath] of Object.entries(thumbnails)) {
                const link = clipMap.get(clipUuid);
                if (!link) continue;
                
                const img = link.querySelector('.m-thumb');
                if (img && posterPath) {
                    img.src = posterPath;
                    img.style.opacity = '0';
                    img.onload = () => {
                        img.style.transition = 'opacity 0.2s ease-in';
                        img.style.opacity = '1';
                    };
                }
            }
            
        } catch (error) {
            console.error('Mobile: Error loading thumbnails:', error);
        }
    }
}

// Auto-initialize
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMobilePlayerThumbnails);
} else {
    initMobilePlayerThumbnails();
}

function initMobilePlayerThumbnails() {
    console.log('Mobile: Initializing batch thumbnail loader...');
    const loader = new MobilePlayerBatchThumbnailLoader();
    loader.loadClipThumbnails();
}
