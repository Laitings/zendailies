<?php
header('Content-Type: text/plain');

// Basic environment info
echo "Environment checks\n";
echo "------------------\n";

// Debian version
$debVer = @file_get_contents('/etc/debian_version');
echo "Debian: " . trim($debVer !== false ? $debVer : 'unknown') . "\n";

// PHP version
echo "PHP: " . PHP_VERSION . "\n";

// ImageMagick (CLI): try IM7 `magick`, else IM6 `convert`
$imOutput = $ffOutput = [];
$imRet = $ffRet = 1;

exec("magick -version 2>&1", $imOutput, $imRet);
if ($imRet !== 0) {
    $imOutput = [];
    exec("convert -version 2>&1", $imOutput, $imRet);
}
echo "ImageMagick: " . ($imRet === 0 ? (isset($imOutput[0]) ? $imOutput[0] : 'detected') : "not found") . "\n";

// php-imagick extension
if (extension_loaded('imagick')) {
    try {
        $imagick = new Imagick();
        $verInfo = $imagick->getVersion();
        echo "php-imagick: " . phpversion('imagick') . " (" . ($verInfo['versionString'] ?? 'unknown') . ")\n";

        // Optional: HEIC/AVIF support quick probe
        $heic = in_array('HEIC', Imagick::queryFormats('HEIC'), true);
        $avif = in_array('AVIF', Imagick::queryFormats('AVIF'), true);
        echo "Imagick formats: HEIC=" . ($heic ? "yes" : "no") . ", AVIF=" . ($avif ? "yes" : "no") . "\n";
    } catch (Throwable $e) {
        echo "php-imagick: loaded, but query failed (" . $e->getMessage() . ")\n";
    }
} else {
    echo "php-imagick: not loaded\n";
}

// PDO drivers
if (extension_loaded('pdo')) {
    $drivers = PDO::getAvailableDrivers();
    echo "PDO drivers: " . (empty($drivers) ? "none" : implode(', ', $drivers)) . "\n";
} else {
    echo "PDO: not available\n";
}

// FFmpeg (CLI check only)
exec("ffmpeg -version 2>&1", $ffOutput, $ffRet);
echo "ffmpeg: " . ($ffRet === 0 ? (isset($ffOutput[0]) ? $ffOutput[0] : 'detected') : "not found") . "\n";

// PHPMailer (class check)
echo "PHPMailer: " . (class_exists('PHPMailer\\PHPMailer\\PHPMailer') ? "available" : "not found") . "\n";

// ZIP extension
echo "ZIP extension: " . (extension_loaded('zip') ? "enabled" : "not enabled") . "\n";

// OPcache
$opcacheEnabled = filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN);
echo "OPcache: " . ($opcacheEnabled ? "enabled" : "disabled") . "\n";

// User
if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
    $uid = posix_geteuid();
    $info = posix_getpwuid($uid);
    echo "Running as: uid=$uid (" . ($info['name'] ?? 'unknown') . ")\n";
} else {
    echo "Running as: unknown (POSIX not available)\n";
}

// Root FS check (heuristic)
$testFile = '/test_write.lock';
if (@file_put_contents($testFile, 'test') === false) {
    echo "Root filesystem: read-only\n";
} else {
    echo "Root filesystem: writable\n";
    @unlink($testFile);
}
