<?php
$PIDFILE = __DIR__ . "/.scraper.pid";
if (file_exists($PIDFILE)) {
    $pid = (int)file_get_contents($PIDFILE);
    if ($pid > 0 && @file_exists("/proc/$pid")) {
        exec("kill $pid 2>/dev/null");
        echo "⏹ تم إيقاف السيرفر (PID: $pid)\n";
    }
    @unlink($PIDFILE);
}
exec("pkill -f 'php -S 0.0.0.0:8080' 2>/dev/null");
echo "✅ تم الإيقاف\n";
