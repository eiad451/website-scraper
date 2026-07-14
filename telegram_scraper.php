<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

class TelegramScraper {
    private $token, $saveDir, $apiBase;
    private $totalFiles = 0, $totalSize = 0;
    private $chats = [], $errors = [];
    private $progressCb = null, $startTime;

    public static function scrapePublicChannel($username, $saveDir, $progressCb = null, $maxPages = 5) {
        $username = ltrim($username, '@');
        $saveDir = rtrim($saveDir, '/') . '/';
        if (!is_dir($saveDir)) @mkdir($saveDir, 0777, true);

        $totalFiles = 0; $totalSize = 0; $errors = []; $msgs = []; $startTime = time();

        $emit = function($type, $data) use ($progressCb) {
            if (!$progressCb) return;
            $data['type'] = $type;
            call_user_func($progressCb, $data);
        };

        $emit('start', ['message' => "جاري سحب القناة @$username..."]);

        // Scrape messages from t.me/s/USERNAME
        $baseUrl = "https://t.me/s/$username";
        $before = null;

        for ($page = 0; $page < $maxPages; $page++) {
            $url = $baseUrl;
            if ($before) $url .= "?before=$before";
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/125.0.0.0 Safari/537.36',
            ]);
            $html = curl_exec($ch);
            curl_close($ch);
            if (!$html) break;

            // Check if it's a valid channel page
            if (str_contains($html, 'tgme_channel_history') === false && str_contains($html, 'tgme_widget_message') === false) {
                if ($page === 0) $errors[] = "القناة @$username غير موجودة أو خاصة";
                break;
            }

            // Extract messages per-wrap: split by widget_message_wrap
            preg_match_all('/<div class="tgme_widget_message_wrap[^>]*>(.*?)<\/div>\s*<\/div>\s*<\/div>\s*<\/div>/s', $html, $wraps);
            foreach ($wraps[1] as $wrapHtml) {
                // Extract post ID
                if (!preg_match('/data-post="([^"]+)"/', $wrapHtml, $idM)) continue;
                $postId = $idM[1];
                // Extract text from tgme_widget_message_text
                $text = '';
                $msgHtml = '';
                if (preg_match('/<div class="tgme_widget_message_text[^>]*>((?:(?!<\/div>).)*)<\/div>/s', $wrapHtml, $txtM)) {
                    $rawText = $txtM[1];
                    $text = html_entity_decode(trim(strip_tags($rawText)), ENT_QUOTES, 'UTF-8');
                    $msgHtml = preg_replace('/<a[^>]+href="([^"]+)"[^>]*>.*?<\/a>/i', '<a href="$1">$1</a>', $rawText);
                }
                $msgs[] = ['id' => $postId, 'text' => $text, 'html' => $msgHtml];
                $before = explode('/', $postId)[1] ?? null;
            }

            // Extract images directly from page
            preg_match_all('/<img[^>]+src="([^"]+)"[^>]*>/i', $html, $allImgs);
            foreach ($allImgs[1] as $imgUrl) {
                if (!str_contains($imgUrl, 'blob:') && preg_match('/\.(jpg|jpeg|png|gif|webp|mp4)/i', $imgUrl)) {
                    $ext = pathinfo(parse_url($imgUrl, PHP_URL_PATH), PATHINFO_EXTENSION);
                    $ext = $ext ?: 'jpg';
                    $fname = md5($imgUrl) . '.' . $ext;
                    $fpath = $saveDir . 'media/' . $fname;
                    if (!is_dir($saveDir . 'media/')) @mkdir($saveDir . 'media/', 0777, true);
                    if (!file_exists($fpath)) {
                        $imgCh = curl_init();
                        curl_setopt_array($imgCh, [CURLOPT_URL => $imgUrl, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => 'Mozilla/5.0']);
                        $imgData = curl_exec($imgCh);
                        curl_close($imgCh);
                        if ($imgData && strlen($imgData) > 100) {
                            file_put_contents($fpath, $imgData);
                            $totalFiles++; $totalSize += strlen($imgData);
                        }
                    }
                }
            }
            // Download video thumbnails too
            preg_match_all('/style="background-image:url\(\'([^"]+)\'\)"/i', $html, $thumbs);
            foreach ($thumbs[1] as $thumbUrl) {
                if (preg_match('/\.(jpg|jpeg|png)/i', $thumbUrl)) {
                    $fname = md5($thumbUrl) . '.jpg';
                    $fpath = $saveDir . 'media/' . $fname;
                    if (!is_dir($saveDir . 'media/')) @mkdir($saveDir . 'media/', 0777, true);
                    if (!file_exists($fpath)) {
                        $tc = curl_init();
                        curl_setopt_array($tc, [CURLOPT_URL => $thumbUrl, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => 'Mozilla/5.0']);
                        $td = curl_exec($tc);
                        curl_close($tc);
                        if ($td && strlen($td) > 100) { file_put_contents($fpath, $td); $totalFiles++; $totalSize += strlen($td); }
                    }
                }
            }

            $pct = min(round(($page + 1) / $maxPages * 85), 85);
            $emit('progress', [
                'percent' => $pct, 'files' => $totalFiles, 'size' => formatSize($totalSize),
                'size_bytes' => $totalSize, 'errors' => count($errors),
                'current' => "📄 $username - صفحة " . ($page + 1) . " (".count($msgs)." رسالة)",
                'hidden_found' => 0, 'admin_found' => 0, 'db_found' => 0,
            ]);

            if (count($msgs) >= 500) break;
        }

        // Save messages file
        $msgContent = "=== Telegram Channel @$username ===" . PHP_EOL;
        $msgContent .= "Messages: " . count($msgs) . PHP_EOL . PHP_EOL;
        foreach ($msgs as $m) {
            $msgContent .= "--- {$m['id']} ---" . PHP_EOL;
            $msgContent .= $m['text'] . PHP_EOL . PHP_EOL;
        }
        file_put_contents($saveDir . 'messages.txt', $msgContent);

        // Save as HTML too
        $htmlOut = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>@' . htmlspecialchars($username) . '</title><style>body{font-family:sans-serif;max-width:600px;margin:auto;padding:16px}.msg{border-bottom:1px solid #eee;padding:12px 0}.id{color:#999;font-size:0.8rem}</style></head><body>';
        $htmlOut .= '<h1>@' . htmlspecialchars($username) . '</h1>';
        $htmlOut .= '<p>' . count($msgs) . ' messages</p>';
        foreach ($msgs as $m) {
            $htmlOut .= '<div class="msg"><div class="id">' . htmlspecialchars($m['id']) . '</div><div>' . $m['html'] . '</div></div>';
        }
        $htmlOut .= '</body></html>';
        file_put_contents($saveDir . 'index.html', $htmlOut);
        $totalFiles += 2;

        $emit('progress', [
            'percent' => 100, 'files' => $totalFiles, 'size' => formatSize($totalSize),
            'size_bytes' => $totalSize, 'errors' => count($errors),
            'current' => '✅ اكتمل!',
            'hidden_found' => 0, 'admin_found' => 0, 'db_found' => 0,
        ]);

        return [
            'total_files' => $totalFiles, 'total_size' => $totalSize,
            'total_size_fmt' => formatSize($totalSize),
            'messages' => count($msgs), 'errors' => $errors,
            'duration' => time() - $startTime,
            'channel' => "@$username",
        ];
    }

    public function __construct($token, $saveDir) {
        $this->token = $token;
        $this->saveDir = rtrim($saveDir, '/') . '/';
        $this->apiBase = "https://api.telegram.org/bot$token";
        $this->startTime = time();
        if (!is_dir($this->saveDir)) @mkdir($this->saveDir, 0777, true);
    }

    public function setProgressCallback($cb) { $this->progressCb = $cb; }

    public function scrape($opts = []) {
        $this->emit('start', ['message' => 'جاري الاتصال بـ Telegram...']);

        // 1. Verify bot
        $me = $this->api('getMe');
        if (!$me || !($me['ok'] ?? false)) {
            $this->emit('error', ['message' => 'فشل الاتصال بالبوت - توكن غير صالح']);
            return ['error' => 'Invalid token'];
        }
        $botInfo = $me['result'];
        $this->emit('progress', [
            'percent' => 5, 'files' => 0, 'size' => '0 B', 'size_bytes' => 0,
            'errors' => 0, 'current' => '✅ ' . ($botInfo['first_name'] ?? 'Bot'),
            'hidden_found' => 0, 'admin_found' => 0, 'db_found' => 0,
        ]);

        // 2. Get bot profile photos
        $this->downloadBotPhoto();

        // 3. Get updates (messages)
        $maxUpdates = $opts['max_updates'] ?? 1000;
        $allUpdates = $this->getAllUpdates($maxUpdates);

        $this->emit('progress', [
            'percent' => 15, 'files' => 0, 'size' => '0 B', 'size_bytes' => 0,
            'errors' => 0, 'current' => '📨 ' . count($allUpdates) . ' رسالة',
            'hidden_found' => 0, 'admin_found' => 0, 'db_found' => 0,
        ]);

        // 4. Process each update
        $total = count($allUpdates);
        foreach ($allUpdates as $i => $update) {
            $pct = 15 + round(($i + 1) / max($total, 1) * 70);
            $msg = $update['message'] ?? $update['channel_post'] ?? $update['edited_message'] ?? null;
            if (!$msg) continue;

            $chat = $msg['chat'] ?? [];
            $chatId = $chat['id'] ?? 'unknown';
            $chatType = $chat['type'] ?? 'private';
            $chatTitle = $chat['title'] ?? $chat['username'] ?? $chat['first_name'] ?? "Chat_$chatId";

            if (!isset($this->chats[$chatId])) {
                $this->chats[$chatId] = [
                    'id' => $chatId, 'title' => $chatTitle, 'type' => $chatType,
                    'msgs' => 0, 'files' => 0, 'dir' => $this->sanitize($chatTitle),
                ];
                @mkdir($this->saveDir . $this->chats[$chatId]['dir'], 0777, true);
            }
            $this->chats[$chatId]['msgs']++;

            // Save message text
            $text = $msg['text'] ?? $msg['caption'] ?? '';
            if ($text) {
                $msgFile = $this->saveDir . $this->chats[$chatId]['dir'] . '/messages.txt';
                $ts = $msg['date'] ?? time();
                $dateStr = date('Y-m-d H:i:s', $ts);
                $from = $msg['from']['username'] ?? $msg['from']['first_name'] ?? 'unknown';
                $line = "[$dateStr] @$from: $text" . PHP_EOL;
                @file_put_contents($msgFile, $line, FILE_APPEND);
            }

            // Download files
            $this->processMessageFiles($msg, $chatId);

            if ($i % 5 === 0 || $i === $total - 1) {
                $this->emit('progress', [
                    'percent' => min($pct, 85), 'files' => $this->totalFiles,
                    'size' => formatSize($this->totalSize), 'size_bytes' => $this->totalSize,
                    'errors' => count($this->errors), 'current' => "📄 $chatTitle: $text" ? mb_substr($text ?? '', 0, 50) : "📄 $chatTitle",
                    'hidden_found' => 0, 'admin_found' => 0, 'db_found' => 0,
                ]);
            }
        }

        // 5. Download chat profile photos
        $this->emit('progress', [
            'percent' => 88, 'files' => $this->totalFiles, 'size' => formatSize($this->totalSize),
            'size_bytes' => $this->totalSize, 'errors' => count($this->errors),
            'current' => '🖼️ صور الملفات الشخصية...',
            'hidden_found' => 0, 'admin_found' => 0, 'db_found' => 0,
        ]);
        foreach ($this->chats as $cid => $info) {
            $this->downloadChatPhoto($cid, $info['dir']);
        }

        // 6. Save summary
        $this->saveSummary();

        $this->emit('progress', [
            'percent' => 100, 'files' => $this->totalFiles, 'size' => formatSize($this->totalSize),
            'size_bytes' => $this->totalSize, 'errors' => count($this->errors),
            'current' => '✅ اكتمل!',
            'hidden_found' => 0, 'admin_found' => 0, 'db_found' => 0,
        ]);

        $duration = time() - $this->startTime;
        return [
            'total_files' => $this->totalFiles, 'total_size' => $this->totalSize,
            'total_size_fmt' => formatSize($this->totalSize),
            'chats' => count($this->chats), 'messages' => $total,
            'errors' => $this->errors, 'duration' => $duration,
        ];
    }

    private function api($method, $data = []) {
        $url = $this->apiBase . '/' . $method;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => !empty($data), CURLOPT_POSTFIELDS => $data,
            CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $r = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) { $this->errors[] = "Telegram API: $err"; return null; }
        return json_decode($r, true);
    }

    private function getAllUpdates($max) {
        $updates = [];
        $offset = 0;
        while (count($updates) < $max) {
            $r = $this->api('getUpdates', [
                'offset' => $offset, 'limit' => min(100, $max - count($updates)),
                'timeout' => 2,
            ]);
            if (!$r || !($r['ok'] ?? false)) break;
            $batch = $r['result'] ?? [];
            if (empty($batch)) break;
            foreach ($batch as $u) {
                $updates[] = $u;
                $offset = $u['update_id'] + 1;
            }
            if (count($batch) < 100) break;
        }
        return $updates;
    }

    private function processMessageFiles($msg, $chatId) {
        $dir = $this->saveDir . ($this->chats[$chatId]['dir'] ?? 'unknown') . '/';
        $fileTypes = [
            'document' => 'documents', 'photo' => 'photos', 'video' => 'videos',
            'audio' => 'audio', 'voice' => 'voice', 'sticker' => 'stickers',
            'animation' => 'animations', 'video_note' => 'video_notes',
        ];
        foreach ($fileTypes as $type => $subDir) {
            if (!isset($msg[$type])) continue;
            $fileData = $type === 'photo' ? $this->getBiggestPhoto($msg[$type]) : $msg[$type];
            $fileId = $fileData['file_id'] ?? null;
            if (!$fileId) continue;
            $this->downloadTelegramFile($fileId, $dir . $subDir . '/', $fileData);
            $this->chats[$chatId]['files']++;
        }
    }

    private function getBiggestPhoto($photos) {
        $last = null;
        foreach ($photos as $p) {
            if (!$last || ($p['file_size'] ?? 0) > ($last['file_size'] ?? 0)) $last = $p;
        }
        return $last ?? [];
    }

    private function downloadTelegramFile($fileId, $dir, $fileData = []) {
        $r = $this->api('getFile', ['file_id' => $fileId]);
        if (!$r || !($r['ok'] ?? false)) return;
        $filePath = $r['result']['file_path'] ?? '';
        if (!$filePath) return;

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!$ext) {
            $mime = $fileData['mime_type'] ?? '';
            $ext = match(true) {
                str_contains($mime, 'image/jpeg') => 'jpg',
                str_contains($mime, 'image/png') => 'png',
                str_contains($mime, 'image/webp') => 'webp',
                str_contains($mime, 'image/gif') => 'gif',
                str_contains($mime, 'video/mp4') => 'mp4',
                str_contains($mime, 'audio/mpeg') => 'mp3',
                str_contains($mime, 'audio/ogg') => 'ogg',
                str_contains($mime, 'application/pdf') => 'pdf',
                str_contains($mime, 'application/zip') => 'zip',
                default => 'dat',
            };
        }

        $name = $fileData['file_name'] ?? (basename($filePath, '.' . pathinfo($filePath, PATHINFO_EXTENSION)) ?: $fileId);
        $cleanName = preg_replace('/[^a-zA-Z0-9_\-\.\(\)\s]/', '_', $name);
        $fname = $cleanName . '.' . $ext;

        $fileUrl = "https://api.telegram.org/file/bot{$this->token}/$filePath";
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $dest = $dir . $fname;
        if (file_exists($dest)) return;

        $cf = curl_init();
        curl_setopt_array($cf, [
            CURLOPT_URL => $fileUrl, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $content = curl_exec($cf);
        $err = curl_error($cf);
        curl_close($cf);

        if ($err || !$content) {
            $this->errors[] = "فشل تحميل: $fname ($err)";
            return;
        }
        file_put_contents($dest, $content);
        $this->totalFiles++;
        $this->totalSize += strlen($content);
    }

    private function downloadBotPhoto() {
        $r = $this->api('getUserProfilePhotos', ['user_id' => explode(':', $this->token)[0], 'limit' => 1]);
        if (!$r || !($r['ok'] ?? false) || empty($r['result']['photos'] ?? [])) return;
        $photo = $this->getBiggestPhoto($r['result']['photos'][0]);
        if ($photo) $this->downloadTelegramFile($photo['file_id'], $this->saveDir . '_bot/');
    }

    private function downloadChatPhoto($chatId, $dirName) {
        $r = $this->api('getChat', ['chat_id' => $chatId]);
        if (!$r || !($r['ok'] ?? false)) return;
        $photo = $r['result']['photo'] ?? null;
        if (!$photo) return;
        $bigFile = $photo['big_file_id'] ?? $photo['small_file_id'] ?? null;
        if ($bigFile) $this->downloadTelegramFile($bigFile, $this->saveDir . $dirName . '/profile/');
    }

    private function saveSummary() {
        $summary = "=== Telegram Scraper Summary ===\n";
        $summary .= "Date: " . date('Y-m-d H:i:s') . "\n";
        $summary .= "Bot: @" . (explode(':', $this->token)[0]) . "\n\n";
        $summary .= "Chats Found: " . count($this->chats) . "\n";
        $summary .= "Total Files: $this->totalFiles\n";
        $summary .= "Total Size: " . formatSize($this->totalSize) . "\n\n";
        foreach ($this->chats as $c) {
            $summary .= "{$c['title']} ({$c['type']}): {$c['msgs']} msgs, {$c['files']} files\n";
        }
        if (!empty($this->errors)) {
            $summary .= "\nErrors (" . count($this->errors) . "):\n";
            foreach (array_slice($this->errors, 0, 20) as $e) $summary .= "  - $e\n";
        }
        @file_put_contents($this->saveDir . '_summary.txt', $summary);
    }

    private function sanitize($name) {
        $name = preg_replace('/[^a-zA-Z0-9_\-\x{0600}-\x{06FF}\s]/u', '_', $name);
        return trim(preg_replace('/_+/', '_', $name), '_') ?: 'chat';
    }

    private function emit($type, $data) {
        if (!$this->progressCb) return;
        $data['type'] = $type;
        call_user_func($this->progressCb, $data);
    }
}
