<?php
require_once __DIR__ . '/config.php';

function e($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sseSend($data) {
    if (connection_aborted()) return;
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

function formatSize($bytes) {
    if ($bytes < 1024) return "$bytes B";
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
}

function deleteDir($dir) {
    if (!is_dir($dir)) return;
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $f) {
        $p = $dir . '/' . $f;
        is_dir($p) ? deleteDir($p) : @unlink($p);
    }
    @rmdir($dir);
}

function createZip($srcDir, $zipPath) {
    @unlink($zipPath);
    $srcDir = rtrim($srcDir, '/');
    set_time_limit(300);
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            logMsg("ZipArchive failed to open $zipPath", 'ERROR');
            return false;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($files as $f) {
            if (connection_aborted()) break;
            if (!$f->isDir()) {
                $fp = $f->getRealPath();
                $rp = substr($fp, strlen($srcDir) + 1);
                $zip->addFile($fp, $rp);
            }
        }
        $ok = $zip->close();
        if (!$ok) logMsg("ZipArchive close failed", 'ERROR');
        return $ok;
    }
    // Fallback: use system zip command
    $escDir = escapeshellarg($srcDir);
    $escZip = escapeshellarg($zipPath);
    $cmd = "cd $escDir && zip -r -q $escZip . 2>/dev/null";
    exec($cmd, $output, $code);
    $ok = $code === 0 && file_exists($zipPath);
    if (!$ok) logMsg("System zip failed (code=$code)", 'ERROR');
    return $ok;
}

function logMsg($msg, $level = 'INFO') {
    $ts = date('Y-m-d H:i:s');
    $line = "[$ts] [$level] $msg" . PHP_EOL;
    @file_put_contents(LOGS_DIR . 'scraper.log', $line, FILE_APPEND);
}

function embedYoutubeUrl($url) {
    if (preg_match('/(?:youtube\.com\/(?:shorts\/|embed\/|watch\?v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $m)) {
        return 'https://www.youtube.com/embed/' . $m[1];
    }
    return $url;
}

function isCloudflareBlock($body) {
    if (!$body) return false;
    if (str_contains($body, '__CF$cv$params')) return true;
    if (str_contains($body, 'cf-ray') && (str_contains($body, 'blocked') || str_contains($body, 'Blocked'))) return true;
    return false;
}

function cloudScrape($url, $timeout = 15) {
    $script = escapeshellarg(__DIR__ . '/cloudscraper_helper.py');
    $urlEsc = escapeshellarg($url);
    $timeoutEsc = escapeshellarg($timeout);
    $cmd = "python3 $script $urlEsc $timeoutEsc 2>/dev/null";
    $output = @shell_exec($cmd);
    if (!$output) return null;
    $result = @json_decode($output, true);
    return $result;
}
