<?php

namespace App\Services;

final class StoragePaths
{
    private string $root;
    private string $publicBase;

    public function __construct(string $root, string $publicBase)
    {
        $this->root = rtrim($root, '/');
        $this->publicBase = rtrim($publicBase, '/');
    }

    public function posterPath(string $projectUuid, string $dayUuid, string $clipUuid): string
    {
        return sprintf('%s/%s/%s/%s/poster/clip.jpg', $this->root, $projectUuid, $dayUuid, $clipUuid);
    }

    public function posterPublicUrl(string $projectUuid, string $dayUuid, string $clipUuid): string
    {
        return sprintf('%s/%s/%s/%s/poster/clip.jpg', $this->publicBase, $projectUuid, $dayUuid, $clipUuid);
    }

    public function relativeFromRoot(string $abs): string
    {
        // Return path relative to the storage root for DB storage_path
        $prefix = $this->root . '/';
        if (strpos($abs, $prefix) === 0) {
            return substr($abs, strlen($prefix));
        }
        return $abs; // fallback
    }
}
