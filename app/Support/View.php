<?php

namespace App\Support;

final class View
{
    private array $sections = [];
    private ?string $activeSection = null;
    private ?string $layoutRel = null;

    public static function render(string $view, array $data = []): void
    {
        $engine = new self();
        $engine->run($view, $data);
    }

    // === APIs available inside views via $this ===
    public function extend(string $layoutRelPath): void
    {
        $this->layoutRel = ltrim(str_replace(['\\', '.'], ['/', '/'], $layoutRelPath), '/');
    }

    public function start(string $name): void
    {
        if ($this->activeSection !== null) {
            throw new \RuntimeException("A section buffer is already active: {$this->activeSection}");
        }
        $this->activeSection = $name;
        ob_start();
    }

    public function end(): void
    {
        if ($this->activeSection === null) {
            throw new \RuntimeException("No active section buffer to end(). Did you call start()?");
        }
        $this->sections[$this->activeSection] = ob_get_clean();
        $this->activeSection = null;
    }

    public function section(string $name): void
    {
        echo $this->sections[$name] ?? '';
    }

    // === Internals ===
    private function run(string $view, array $data): void
    {
        // Make controller data visible in the view
        extract($data, EXTR_SKIP);

        // Resolve the view file
        $rel  = ltrim(str_replace(['\\', '.'], ['/', '/'], $view), '/');
        $file = dirname(__DIR__) . '/Views/' . $rel . '.php';
        if (!is_file($file)) {
            http_response_code(500);
            throw new \RuntimeException("View not found: {$file}");
        }

        // Render the view in this object’s scope so $this works
        $viewHtml = $this->includeWithin($file, get_defined_vars());

        // If no layout chosen in the view, default to layout/main.php
        if ($this->layoutRel === null) {
            $this->layoutRel = 'layout/main.php';
        }
        // If the view didn’t write a 'content' section, use its raw output
        if (!isset($this->sections['content'])) {
            $this->sections['content'] = $viewHtml;
        }

        // Include the layout with $sections available
        $layoutFile = dirname(__DIR__) . '/Views/' . (str_ends_with($this->layoutRel, '.php') ? $this->layoutRel : $this->layoutRel . '.php');
        if (!is_file($layoutFile)) {
            http_response_code(500);
            throw new \RuntimeException("Layout not found: {$layoutFile}");
        }

        $sections = $this->sections; // what layout/main.php expects
        include $layoutFile;
    }

    private function includeWithin(string $file, array $vars): string
    {
        // Allow logical view IDs like "partials/import_csv_modal"
        // Resolve to /app/Views/partials/import_csv_modal.php
        if (pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
            $base = dirname(__DIR__, 1) . '/Views/';
            $candidate = $base . ltrim($file, '/') . '.php';
            if (is_file($candidate)) {
                $file = $candidate;
            }
        }

        if (!is_file($file)) {
            throw new \RuntimeException("Partial not found: {$file}");
        }

        extract($vars, EXTR_SKIP);
        ob_start();
        include $file; // $this inside the included file refers to this View engine
        return ob_get_clean();
    }
}
