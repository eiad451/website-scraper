<?php
error_reporting(0);
$DIR = __DIR__;
$PORT = 8080;
$PIDFILE = $DIR . "/.scraper.pid";

// Stop old server if any
if (file_exists($PIDFILE)) {
    $p = (int)file_get_contents($PIDFILE);
    if ($p > 0) @exec("kill $p 2>/dev/null");
    @unlink($PIDFILE);
}
@exec("pkill -f 'php -S 0.0.0.0:$PORT' 2>/dev/null");
sleep(1);

// Start server using shell
$out = [];
$rv = 0;
exec("nohup php -S 0.0.0.0:$PORT -t " . escapeshellarg($DIR) . " > /dev/null 2>&1 & echo $!", $out, $rv);
$pid = (int)trim($out[0] ?? '0');

if ($pid <= 0) {
    // Try direct
    $pid = (int)shell_exec("php -S 0.0.0.0:$PORT -t " . escapeshellarg($DIR) . " > /dev/null 2>&1 & echo $!");
}

sleep(1);

$running = $pid > 0;
if ($running) {
    // Also check via /proc if available
    $running = @file_exists("/proc/$pid");
}
if (!$running) {
    // Last resort: nohup + setsid
    @exec("setsid nohup php -S 0.0.0.0:$PORT -t " . escapeshellarg($DIR) . " > /dev/null 2>&1 &");
    sleep(1);
    $out = [];
    exec("pgrep -n -f 'php.*$PORT'", $out);
    $pid = (int)($out[0] ?? '0');
    $running = $pid > 0;
}

// Final check: try HTTP
if ($running) {
    $ch = @fsockopen("127.0.0.1", $PORT, $eno, $err, 1);
    if ($ch) { fclose($ch); $running = true; }
}

if ($running) {
    file_put_contents($PIDFILE, (string)$pid);
    echo "✅ السيرفر شغال! http://localhost:$PORT\n";
    $am = @exec("command -v am");
    if ($am) @exec("{$am} start -a android.intent.action.VIEW -d 'http://localhost:$PORT' >/dev/null 2>&1 &");
} else {
    echo "❌ فشل تشغيل السيرفر\n";
    exit(1);
}
