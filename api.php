<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/scraper.php';
require_once __DIR__ . '/telegram_scraper.php';
require_once __DIR__ . '/apk_analyzer.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'scrape':
        handleScrape();
        break;
    case 'scrape_sse':
        handleScrapeSSE();
        break;
    case 'cancel_scrape':
        handleCancelScrape();
        break;
    case 'list':
        handleList();
        break;
    case 'view':
        handleView();
        break;
    case 'delete':
        handleDelete();
        break;
    case 'download_zip':
        handleDownloadZip();
        break;
    case 'view_file':
        handleViewFile();
        break;
    case 'search_files':
        handleSearchFiles();
        break;
    case 'bot_scrape':
        handleBotScrape();
        break;
    case 'bot_search':
        handleBotSearch();
        break;
    case 'bot_sites':
        handleBotSites();
        break;
    case 'bot_stats':
        handleBotStats();
        break;
    case 'telegram_sse':
        handleTelegramSSE();
        break;
    case 'telegram_public_sse':
        handleTelegramPublicSSE();
        break;
    case 'preview':
        handlePreview();
        break;
    case 'stats':
        handleStats();
        break;
    case 'payment_request':
        handlePaymentRequest();
        break;
    case 'apk_analyze':
        handleApkAnalyze();
        break;
    case 'apk_sse':
        handleApkSSE();
        break;
    case 'welcome_skip':
        handleWelcomeSkip();
        break;
    case 'logout':
        session_destroy();
        jsonResponse(['success' => true]);
        break;
    default:
        jsonResponse(['error' => 'Unknown action: ' . $action], 400);
}

function handleScrape() {
    $url = $_POST['url'] ?? $_GET['url'] ?? '';
    if (!$url) jsonResponse(['error' => 'URL is required'], 400);
    $saveDir = SAVE_DIR . md5($url) . '/';
    if (is_dir($saveDir) && !isset($_GET['force'])) {
        jsonResponse(['error' => 'Already exists', 'dir' => md5($url)], 409);
    }
    deleteDir($saveDir);
    $settings = getSettings();
    $scraper = new SiteScraper($url, $saveDir, [
        'max_depth' => intval($_GET['depth'] ?? 20),
        'max_files' => intval($_GET['max_files'] ?? 100000),
        'concurrent' => intval($_GET['threads'] ?? 30),
        'timeout' => intval($_GET['timeout'] ?? 30),
        'scan_hidden' => ($_GET['scan_hidden'] ?? '1') === '1',
        'scan_admin' => ($_GET['scan_admin'] ?? '1') === '1',
        'scan_db' => ($_GET['scan_db'] ?? '1') === '1',
        'secret_keywords' => $settings['secret_keywords'] ?? '',
    ]);
    $result = $scraper->scrape();
    $result['dir'] = md5($url);
    $result['url'] = $url;
    $info = ['dir' => $result['dir'], 'url' => $url, 'files' => $result['total_files'], 'size' => $result['total_size'], 'created_at' => time()];
    @file_put_contents($saveDir . '.info.json', json_encode($info, JSON_UNESCAPED_UNICODE));
    jsonResponse($result);
}

function handleScrapeSSE() {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    if (ob_get_level()) ob_end_clean();

    $url = $_GET['url'] ?? '';
    if (!$url) { sseSend(['error' => 'URL required']); exit; }

    $saveDir = SAVE_DIR . md5($url) . '/';
    $lockFile = SAVE_DIR . md5($url) . '.lock';
    if (file_exists($lockFile)) {
        sseSend(['error' => 'Already in progress']);
        exit;
    }
    file_put_contents($lockFile, getmypid());

    if (is_dir($saveDir)) deleteDir($saveDir);

    $settings = getSettings();
    $scraper = new SiteScraper($url, $saveDir, [
        'max_depth' => intval($_GET['depth'] ?? 20),
        'max_files' => intval($_GET['max_files'] ?? 100000),
        'concurrent' => intval($_GET['threads'] ?? 30),
        'timeout' => intval($_GET['timeout'] ?? 30),
        'scan_hidden' => ($_GET['scan_hidden'] ?? '1') === '1',
        'scan_admin' => ($_GET['scan_admin'] ?? '1') === '1',
        'scan_db' => ($_GET['scan_db'] ?? '1') === '1',
        'secret_keywords' => $settings['secret_keywords'] ?? '',
    ]);

    $scraper->setProgressCallback(function($data) {
        sseSend($data);
    });

    $result = $scraper->scrape();
    $result['dir'] = md5($url);
    $result['url'] = $url;
    $info = ['dir' => $result['dir'], 'url' => $url, 'files' => $result['total_files'], 'size' => $result['total_size'], 'created_at' => time()];
    @file_put_contents($saveDir . '.info.json', json_encode($info, JSON_UNESCAPED_UNICODE));
    sseSend(['type' => 'complete', 'result' => $result]);
    @unlink($lockFile);
    exit;
}

function handleCancelScrape() {
    $dir = $_GET['dir'] ?? '';
    if (!$dir) jsonResponse(['error' => 'dir required'], 400);
    $lockFile = SAVE_DIR . $dir . '.lock';
    if (file_exists($lockFile)) {
        $pid = @file_get_contents($lockFile);
        if ($pid) @exec("kill $pid 2>/dev/null");
        @unlink($lockFile);
    }
    jsonResponse(['success' => true]);
}

function handleList() {
    $dirs = glob(SAVE_DIR . '*', GLOB_ONLYDIR);
    $sites = [];
    foreach ($dirs as $d) {
        $name = basename($d);
        $infoFile = $d . '/.info.json';
        if (file_exists($infoFile)) {
            $info = json_decode(file_get_contents($infoFile), true);
            $sites[] = $info;
        } else {
            $sites[] = ['dir' => $name, 'url' => 'unknown', 'files' => 0, 'size' => 0];
        }
    }
    usort($sites, function($a, $b) {
        return ($b['created_at'] ?? 0) - ($a['created_at'] ?? 0);
    });
    jsonResponse(['sites' => array_slice($sites, 0, 50), 'total' => count($sites)]);
}

function handleView() {
    $dir = $_GET['dir'] ?? '';
    if (!$dir) jsonResponse(['error' => 'dir required'], 400);
    $path = SAVE_DIR . $dir;
    if (!is_dir($path)) jsonResponse(['error' => 'Not found'], 404);
    $sub = $_GET['path'] ?? '';
    $fullPath = $path . '/' . ltrim($sub, '/');
    if (is_dir($fullPath)) {
        $entries = [];
        foreach (array_diff(scandir($fullPath), ['.', '..']) as $entry) {
            $p = $fullPath . '/' . $entry;
            $entries[] = [
                'name' => $entry, 'is_dir' => is_dir($p),
                'size' => is_file($p) ? filesize($p) : 0,
                'size_fmt' => is_file($p) ? formatSize(filesize($p)) : '',
                'modified' => filemtime($p),
            ];
        }
        usort($entries, function($a, $b) {
            if ($a['is_dir'] !== $b['is_dir']) return $a['is_dir'] ? -1 : 1;
            return strnatcasecmp($a['name'], $b['name']);
        });
        jsonResponse(['entries' => $entries, 'path' => $sub ?: '/', 'dir' => $dir]);
    } elseif (is_file($fullPath)) {
        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $type = 'unknown';
        if (in_array($ext, ['html','htm','php','shtml'])) $type = 'html';
        elseif (in_array($ext, ['css'])) $type = 'css';
        elseif (in_array($ext, ['js','mjs','cjs'])) $type = 'js';
        elseif (in_array($ext, ['json'])) $type = 'json';
        elseif (in_array($ext, ['xml','rss','atom'])) $type = 'xml';
        elseif (in_array($ext, ['png','jpg','jpeg','gif','webp','svg','ico','avif'])) $type = 'image';
        elseif (in_array($ext, ['txt','md','csv'])) $type = 'text';
        $content = $type === 'image' ? base64_encode(file_get_contents($fullPath)) : file_get_contents($fullPath);
        jsonResponse([
            'content' => $content, 'type' => $type, 'size' => filesize($fullPath),
            'size_fmt' => formatSize(filesize($fullPath)), 'name' => basename($fullPath),
            'path' => $sub, 'dir' => $dir, 'mime' => mime_content_type($fullPath) ?: 'application/octet-stream',
        ]);
    } else {
        jsonResponse(['error' => 'Not found'], 404);
    }
}

function handleDelete() {
    $dir = $_GET['dir'] ?? '';
    if (!$dir) jsonResponse(['error' => 'dir required'], 400);
    $path = SAVE_DIR . $dir;
    if (!is_dir($path)) jsonResponse(['error' => 'Not found'], 404);
    deleteDir($path);
    jsonResponse(['success' => true]);
}

function handleDownloadZip() {
    $dir = $_GET['dir'] ?? '';
    if (!$dir) jsonResponse(['error' => 'dir required'], 400);
    $path = SAVE_DIR . $dir;
    if (!is_dir($path)) jsonResponse(['error' => 'Not found'], 404);
    $zipPath = SAVE_DIR . $dir . '.zip';
    set_time_limit(300);
    if (!createZip($path, $zipPath)) {
        if (!file_exists($zipPath)) jsonResponse(['error' => 'فشل إنشاء الملف المضغوط'], 500);
    }
    if (!file_exists($zipPath)) jsonResponse(['error' => 'Zip failed'], 500);
    header('Content-Description: File Transfer');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $dir . '.zip"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    ob_clean();
    flush();
    $chunkSize = 1024 * 1024;
    $handle = fopen($zipPath, 'rb');
    if ($handle) {
        while (!feof($handle)) {
            print fread($handle, $chunkSize);
            ob_flush();
            flush();
        }
        fclose($handle);
    }
    @unlink($zipPath);
    exit;
}

function handleViewFile() {
    $dir = $_GET['dir'] ?? '';
    $file = $_GET['file'] ?? '';
    if (!$dir || !$file) jsonResponse(['error' => 'dir and file required'], 400);
    $fp = SAVE_DIR . $dir . '/' . ltrim($file, '/');
    if (!file_exists($fp)) jsonResponse(['error' => 'File not found'], 404);
    $ext = strtolower(pathinfo($fp, PATHINFO_EXTENSION));
    header('Content-Type: ' . mime_content_type($fp));
    if (in_array($ext, ['png','jpg','jpeg','gif','webp','svg','ico','avif','woff','woff2','ttf','otf','eot','pdf','zip','gz'])) {
        header('Content-Disposition: inline');
        readfile($fp);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo file_get_contents($fp);
    }
    exit;
}

function handleSearchFiles() {
    $dir = $_GET['dir'] ?? '';
    $q = $_GET['q'] ?? '';
    if (!$dir || !$q) jsonResponse(['error' => 'dir and q required'], 400);
    $path = SAVE_DIR . $dir;
    if (!is_dir($path)) jsonResponse(['error' => 'Not found'], 404);
    $results = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($files as $f) {
        if ($f->isFile()) {
            $fp = $f->getPathname();
            $rel = substr($fp, strlen($path) + 1);
            $nameLower = strtolower(basename($fp));
            $qLower = strtolower($q);
            if (str_contains($nameLower, $qLower) || str_contains(strtolower($rel), $qLower)) {
                $results[] = [
                    'name' => basename($fp), 'path' => $rel,
                    'size' => $f->getSize(), 'size_fmt' => formatSize($f->getSize()),
                ];
            }
        }
        if (count($results) >= 200) break;
    }
    jsonResponse(['results' => $results, 'total' => count($results)]);
}

function handleBotScrape() {
    require_once __DIR__ . '/bot_scraper.php';
    $bot = new BotScraper();
    $result = $bot->startBotScrape();
    jsonResponse($result);
}

function handleBotSearch() {
    require_once __DIR__ . '/bot_scraper.php';
    $bot = new BotScraper();
    $q = $_GET['q'] ?? '';
    if (!$q) jsonResponse(['error' => 'q required'], 400);
    jsonResponse(['results' => $bot->search($q)]);
}

function handleBotSites() {
    require_once __DIR__ . '/bot_scraper.php';
    $bot = new BotScraper();
    jsonResponse(['sites' => $bot->getSites()]);
}

function handleBotStats() {
    require_once __DIR__ . '/bot_scraper.php';
    $bot = new BotScraper();
    jsonResponse($bot->getStats());
}

function handlePreview() {
    $url = $_GET['url'] ?? '';
    if (!$url) jsonResponse(['error' => 'URL required'], 400);
    $html = file_get_contents($url);
    if (!$html) jsonResponse(['error' => 'Failed to fetch'], 500);
    jsonResponse(['html' => $html]);
}

function handleStats() {
    $dirs = glob(SAVE_DIR . '*', GLOB_ONLYDIR);
    $totalFiles = 0; $totalSize = 0; $totalSites = count($dirs);
    foreach ($dirs as $d) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $f) {
            if ($f->isFile()) { $totalFiles++; $totalSize += $f->getSize(); }
        }
    }
    jsonResponse([
        'total_sites' => $totalSites, 'total_files' => $totalFiles,
        'total_size' => formatSize($totalSize), 'total_size_bytes' => $totalSize,
    ]);
}

function handlePaymentRequest() {
    if (!isLoggedIn()) jsonResponse(['error' => 'يرجى تسجيل الدخول أولاً'], 401);
    $user = getCurrentUser();
    $amount = floatval($_POST['amount'] ?? 0);
    $phone = trim($_POST['phone'] ?? '');
    $phonePaidTo = trim($_POST['phone_paid_to'] ?? '');
    $txId = trim($_POST['transaction_id'] ?? '');
    if ($amount < 10) jsonResponse(['error' => 'الحد الأدنى 10 جنيه'], 400);
    if (!$phone || !$txId) jsonResponse(['error' => 'جميع الحقول مطلوبة'], 400);
    $coins = intval($amount * 10);
    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO payment_requests (user_id, amount, coins, phone, phone_paid_to, transaction_id) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user['id'], $amount, $coins, $phone, $phonePaidTo, $txId]);
    jsonResponse(['success' => true, 'message' => 'تم إرسال طلب الشحن بنجاح. سيتم مراجعته من المسؤول']);
}

function handleTelegramSSE() {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    if (ob_get_level()) ob_end_clean();

    $token = $_GET['token'] ?? '';
    if (!$token) { sseSend(['error' => 'Bot Token مطلوب']); exit; }

    $saveDir = SAVE_DIR . 'telegram_' . md5($token) . '/';
    $lockFile = SAVE_DIR . 'telegram_' . md5($token) . '.lock';
    if (file_exists($lockFile)) { sseSend(['error' => 'عملية سحب جارية بالفعل']); exit; }
    file_put_contents($lockFile, getmypid());
    if (is_dir($saveDir)) deleteDir($saveDir);

    $scraper = new TelegramScraper($token, $saveDir);
    $scraper->setProgressCallback(function($data) { sseSend($data); });

    $result = $scraper->scrape(['max_updates' => intval($_GET['max'] ?? 1000)]);
    $result['dir'] = basename($saveDir);
    $info = ['dir' => $result['dir'], 'url' => 'telegram://' . explode(':', $token)[0], 'files' => $result['total_files'], 'size' => $result['total_size'], 'created_at' => time()];
    @file_put_contents($saveDir . '.info.json', json_encode($info, JSON_UNESCAPED_UNICODE));
    sseSend(['type' => 'complete', 'result' => $result]);
    @unlink($lockFile);
    exit;
}

function handleTelegramPublicSSE() {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    if (ob_get_level()) ob_end_clean();

    $username = trim($_GET['username'] ?? '');
    if (!$username) { sseSend(['error' => 'يوزر القناة مطلوب']); exit; }
    $username = ltrim($username, '@');

    $saveDir = SAVE_DIR . 'tg_public_' . md5($username) . '/';
    if (is_dir($saveDir)) deleteDir($saveDir);

    $cb = function($data) { sseSend($data); };
    $result = TelegramScraper::scrapePublicChannel($username, $saveDir, $cb, intval($_GET['pages'] ?? 5));
    $result['dir'] = basename($saveDir);
    $info = ['dir' => $result['dir'], 'url' => "https://t.me/$username", 'files' => $result['total_files'], 'size' => $result['total_size'], 'created_at' => time()];
    @file_put_contents($saveDir . '.info.json', json_encode($info, JSON_UNESCAPED_UNICODE));
    sseSend(['type' => 'complete', 'result' => $result]);
    exit;
}

function handleApkAnalyze() {
    if (!isLoggedIn()) jsonResponse(['error' => 'يرجى تسجيل الدخول أولاً'], 401);
    $user = getCurrentUser();

    if (!isset($_FILES['apk_file']) || $_FILES['apk_file']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['error' => 'الرجاء رفع ملف APK'], 400);
    }

    $apkPath = ApkAnalyzer::handleUpload($_FILES['apk_file']);
    if (!$apkPath) {
        jsonResponse(['error' => 'فشل رفع الملف أو الامتداد غير مدعوم'], 400);
    }

    $settings = getSettings();
    $apiKey = $settings['ai_api_key'] ?? '';

    $saveDir = SAVE_DIR . 'apk_' . md5($apkPath) . '/';
    if (is_dir($saveDir)) deleteDir($saveDir);
    @mkdir($saveDir, 0777, true);

    $analyzer = new ApkAnalyzer($apkPath, $saveDir, ['ai_api_key' => $apiKey]);
    $result = $analyzer->analyze();

    @unlink($apkPath);
    $info = ['dir' => $result['dir'], 'url' => basename($apkPath), 'files' => $result['total_files'], 'size' => $result['total_size'], 'created_at' => time()];
    @file_put_contents($saveDir . '.info.json', json_encode($info, JSON_UNESCAPED_UNICODE));
    jsonResponse($result);
}

function handleApkSSE() {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    if (ob_get_level()) ob_end_clean();

    $user = getCurrentUser();
    if (!$user) { sseSend(['error' => 'يرجى تسجيل الدخول أولاً']); exit; }

    if (!isset($_FILES['apk_file']) || $_FILES['apk_file']['error'] !== UPLOAD_ERR_OK) {
        sseSend(['error' => 'الرجاء رفع ملف APK']); exit;
    }

    $apkPath = ApkAnalyzer::handleUpload($_FILES['apk_file']);
    if (!$apkPath) { sseSend(['error' => 'فشل رفع الملف']); exit; }

    $settings = getSettings();
    $apiKey = $settings['ai_api_key'] ?? '';

    $saveDir = SAVE_DIR . 'apk_' . md5($apkPath . time()) . '/';
    if (is_dir($saveDir)) deleteDir($saveDir);
    @mkdir($saveDir, 0777, true);

    $analyzer = new ApkAnalyzer($apkPath, $saveDir, ['ai_api_key' => $apiKey]);
    $analyzer->setProgressCallback(function($data) { sseSend($data); });
    $result = $analyzer->analyze();

    @unlink($apkPath);
    $info = ['dir' => $result['dir'], 'url' => basename($apkPath), 'files' => $result['total_files'], 'size' => $result['total_size'], 'created_at' => time()];
    @file_put_contents($saveDir . '.info.json', json_encode($info, JSON_UNESCAPED_UNICODE));
    sseSend(['type' => 'complete', 'result' => $result]);
    exit;
}

function handleWelcomeSkip() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE users SET welcomed = 1 WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    setcookie('welcomed', '1', time() + 86400*365, '/');
    header('Location: index.php');
    exit;
}
