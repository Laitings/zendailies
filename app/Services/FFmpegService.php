<?php

namespace App\Services;

final class FFmpegService
{
    /**
     * Path to the ffmpeg binary
     * @var string
     */
    private string $ffmpeg;

    public function __construct(string $binary = 'ffmpeg')
    {
        $this->ffmpeg = $binary;
    }

    /**
     * Generate a poster JPG at $dest. Returns ['ok'=>bool, 'err'=>string|null]
     */
    public function generatePoster(string $input, string $output, int $seekSeconds, int $targetWidth = 640): array
    {
        // Ensure dir exists (controller already does this, but safe)
        $dir = dirname($output);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
            return ['ok' => false, 'err' => 'Failed to create output dir'];
        }

        // Build a safe scale expression that keeps aspect ratio
        // (use even heights for yuv420p)
        $scale = "scale='min(" . (int)$targetWidth . ",iw)':'-2'";

        // IMPORTANT: place -ss **after** -i for accurate seek on MP4/H.264,
        // otherwise ffmpeg may snap to keyframe 0 (the clapper).
        // We also add -noaccurate_seek OFF behavior implicitly by using -ss after -i.
        // Use -frames:v 1 to grab exactly one frame.
        $cmd = [
            $this->ffmpeg,
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            '-nostdin',

            // input
            '-i',
            $input,

            // accurate seek
            '-ss',
            (string)max(0, $seekSeconds),

            // filters + single frame
            '-vf',
            $scale,
            '-frames:v',
            '1',

            // quality for JPEG
            '-q:v',
            '1',

            // output
            $output,
        ];

        // Escape arguments safely
        $esc = array_map(static function ($a) {
            // Avoid adding quotes twice
            return preg_match('/^[a-zA-Z0-9_\-\.\/:]+$/', $a) ? $a : escapeshellarg($a);
        }, $cmd);

        $command = implode(' ', $esc);

        $descriptorSpec = [
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $proc = proc_open($command, $descriptorSpec, $pipes);
        if (!\is_resource($proc)) {
            return ['ok' => false, 'err' => 'Failed to start ffmpeg'];
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $p) {
            if (\is_resource($p)) fclose($p);
        }
        $exit = proc_close($proc);

        if ($exit !== 0) {
            return ['ok' => false, 'err' => trim($stderr ?: $stdout) ?: ("ffmpeg exited " . $exit)];
        }

        // Double-check that an image was produced
        if (!@is_file($output) || @filesize($output) <= 0) {
            return ['ok' => false, 'err' => 'No output written'];
        }

        return ['ok' => true];
    }

    /**
     * Run ffprobe and extract only what we care about for ingest:
     * - duration_ms
     * - fps
     * - tc_start
     */
    public static function probeCoreMetadata(string $absPath): array
    {
        $cmd = sprintf(
            'ffprobe -v quiet -print_format json -show_format -show_streams %s',
            escapeshellarg($absPath)
        );
        $raw = shell_exec($cmd);
        if (!$raw) {
            return [
                'duration_ms' => null,
                'fps'         => null,
                'tc_start'    => null,
            ];
        }

        $probe = json_decode($raw, true);
        if (!is_array($probe)) {
            return [
                'duration_ms' => null,
                'fps'         => null,
                'tc_start'    => null,
            ];
        }

        $out = [
            'duration_ms' => null,
            'fps'         => null,
            'tc_start'    => null,
        ];

        // duration -> ms (use container duration)
        if (!empty($probe['format']['duration'])) {
            $sec = floatval($probe['format']['duration']);
            $out['duration_ms'] = (int) round($sec * 1000);
        }

        // first video stream
        $videoStream = null;
        foreach ($probe['streams'] ?? [] as $s) {
            if (($s['codec_type'] ?? '') === 'video') {
                $videoStream = $s;
                break;
            }
        }

        // fps from r_frame_rate "24/1"
        if ($videoStream && !empty($videoStream['r_frame_rate'])) {
            $rate = $videoStream['r_frame_rate']; // "24/1"
            if (strpos($rate, '/') !== false) {
                [$n, $d] = explode('/', $rate, 2);
                if ((int)$d !== 0) {
                    $out['fps'] = floatval($n) / floatval($d);
                }
            }
        }

        // timecode can appear on the video stream tags...
        if ($videoStream && !empty($videoStream['tags']['timecode'])) {
            $out['tc_start'] = $videoStream['tags']['timecode'];
        }

        // ...or in a dedicated tmcd data stream
        if ($out['tc_start'] === null) {
            foreach ($probe['streams'] ?? [] as $s) {
                if (
                    ($s['codec_type'] ?? '') === 'data' &&
                    (($s['codec_tag_string'] ?? '') === 'tmcd') &&
                    !empty($s['tags']['timecode'])
                ) {
                    $out['tc_start'] = $s['tags']['timecode'];
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * getFpsInfo — returns precise FPS info with rational form.
     * Uses ffprobe json and prefers the video stream's r_frame_rate.
     * Returns: ['fps' => float, 'fps_num' => int, 'fps_den' => int] or null on failure.
     */
    public static function getFpsInfo(string $absPath): ?array
    {
        if (!is_file($absPath)) {
            return null;
        }

        $ffprobe = getenv('FFPROBE_BIN') ?: 'ffprobe';
        $cmd = sprintf(
            '%s -v quiet -print_format json -show_streams %s',
            escapeshellarg($ffprobe),
            escapeshellarg($absPath)
        );

        $raw = @shell_exec($cmd);
        if (!$raw) return null;

        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['streams'])) return null;

        $video = null;
        foreach ($json['streams'] as $s) {
            if (($s['codec_type'] ?? '') === 'video') {
                $video = $s;
                break;
            }
        }
        if (!$video) return null;

        // Prefer r_frame_rate, fallback to avg_frame_rate
        $rate = $video['r_frame_rate'] ?? ($video['avg_frame_rate'] ?? null); // e.g. "24000/1001"
        if (!$rate || strpos($rate, '/') === false) return null;

        [$n, $d] = explode('/', $rate, 2);
        $num = (int)$n;
        $den = (int)$d;
        if ($num <= 0 || $den <= 0) return null;

        // Compute float with 3 decimals like backfill did
        $fps = round($num / $den, 3);

        // Normalize common NTSC fractions to canonical ints (already ints above)
        // (No extra mapping needed; r_frame_rate gives exact 24000/1001 etc.)

        return [
            'fps'     => $fps,
            'fps_num' => $num,
            'fps_den' => $den,
        ];
    }
}
