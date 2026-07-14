<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

class ApkAnalyzer {
    private string $apkPath;
    private string $workDir;
    private string $reportDir;
    private string $decompileDir;
    private array $config;
    private array $report = [];
    private int $totalFiles = 0;
    private int $totalSize = 0;
    private array $errors = [];
    private $progressCb = null;

    public function __construct(string $apkPath, string $workDir, array $config = []) {
        $this->apkPath = $apkPath;
        $this->workDir = rtrim($workDir, '/') . '/';
        $this->reportDir = $this->workDir . 'report/';
        $this->decompileDir = $this->workDir . 'decompiled/';
        $this->config = array_merge([
            'ai_api_key' => '',
            'ai_model' => 'gemini-2.0-flash',
            'ai_base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        ], $config);
        @mkdir($this->workDir, 0777, true);
        @mkdir($this->reportDir, 0777, true);
        @mkdir($this->decompileDir, 0777, true);
    }

    public function setProgressCallback(callable $cb): void {
        $this->progressCb = $cb;
    }

    private function emit(string $type, array $data = []): void {
        if ($this->progressCb) {
            ($this->progressCb)(array_merge(['type' => $type], $data));
        }
    }

    public function analyze(): array {
        $start = microtime(true);
        $this->emit('start', ['message' => '🔍 بدء تحليل APK...']);

        $this->checkTools();
        $this->emit('progress', ['percent' => 5, 'current' => '📦 فحص هيكل APK']);
        $this->analyzeBasic();

        $this->emit('progress', ['percent' => 15, 'current' => '🔄 فك التجميع']);
        $this->decompile();

        $this->emit('progress', ['percent' => 35, 'current' => '🔎 استخراج الكود والمصادر']);
        $this->extractStrings();

        $this->emit('progress', ['percent' => 55, 'current' => '🕵️ كشف الأسرار والثغرات']);
        $this->findSecrets();

        $this->emit('progress', ['percent' => 70, 'current' => '🧠 تحليل متقدم بالذكاء الاصطناعي']);
        $this->aiAnalysis();

        $this->emit('progress', ['percent' => 90, 'current' => '📊 إنشاء التقرير']);
        $this->generateReport();

        $duration = round(microtime(true) - $start);
        $this->emit('complete', [
            'total_files' => $this->totalFiles,
            'total_size' => $this->totalSize,
            'total_size_fmt' => formatSize($this->totalSize),
            'duration' => $duration,
            'errors' => $this->errors,
            'report_dir' => basename($this->workDir),
        ]);

        return [
            'total_files' => $this->totalFiles,
            'total_size' => $this->totalSize,
            'total_size_fmt' => formatSize($this->totalSize),
            'duration' => $duration,
            'errors' => $this->errors,
            'dir' => basename($this->workDir),
            'report' => $this->report,
        ];
    }

    private function checkTools(): void {
        $tools = ['aapt', 'apktool', 'java'];
        foreach ($tools as $tool) {
            $which = trim(shell_exec("which $tool 2>/dev/null") ?? '');
            if (empty($which)) {
                $this->errors[] = "⚠️ أداة $tool غير موجودة. بعض الوظائف قد لا تعمل.";
            }
        }
    }

    private function analyzeBasic(): void {
        $info = [];

        // aapt dump badging
        $apkEsc = escapeshellarg($this->apkPath);
        $aaptOut = @shell_exec("aapt dump badging $apkEsc 2>/dev/null");
        if ($aaptOut) {
            preg_match("/package: name='([^']+)'/", $aaptOut, $m);
            $info['package'] = $m[1] ?? 'unknown';

            preg_match("/versionCode='(\d+)'/", $aaptOut, $m);
            $info['version_code'] = $m[1] ?? '0';

            preg_match("/versionName='([^']+)'/", $aaptOut, $m);
            $info['version_name'] = $m[1] ?? '0.0';

            preg_match("/compileSdkVersion='(\d+)'/", $aaptOut, $m);
            $info['compile_sdk'] = $m[1] ?? '';

            preg_match("/minSdkVersion:'(\d+)'/", $aaptOut, $m);
            $info['min_sdk'] = $m[1] ?? '';

            preg_match("/targetSdkVersion:'(\d+)'/", $aaptOut, $m);
            $info['target_sdk'] = $m[1] ?? '';

            preg_match("/application-label:'([^']*)'/", $aaptOut, $m);
            $info['app_name'] = $m[1] ?? '';

            preg_match("/application: label='([^']*)' icon='([^']*)'/", $aaptOut, $m);
            $info['app_label'] = $m[1] ?? '';
            $info['app_icon'] = $m[2] ?? '';

            preg_match_all("/uses-permission: name='([^']+)'/", $aaptOut, $m);
            $info['permissions'] = $m[1] ?? [];

            preg_match_all("/launchable-activity: name='([^']+)'/", $aaptOut, $m);
            $info['launch_activities'] = $m[1] ?? [];

            // Native code
            preg_match_all("/native-code: '([^']+)'/", $aaptOut, $m);
            $info['native_code'] = $m[1] ?? [];

            // All activities
            preg_match_all("/activity: name='([^']+)'/", $aaptOut, $m);
            $info['activities'] = $m[1] ?? [];

            // Services
            preg_match_all("/service: name='([^']+)'/", $aaptOut, $m);
            $info['services'] = $m[1] ?? [];

            // Receivers
            preg_match_all("/receiver: name='([^']+)'/", $aaptOut, $m);
            $info['receivers'] = $m[1] ?? [];

            // Providers
            preg_match_all("/provider: name='([^']+)'/", $aaptOut, $m);
            $info['providers'] = $m[1] ?? [];

            // Features
            preg_match_all("/uses-feature: name='([^']+)'/", $aaptOut, $m);
            $info['features'] = $m[1] ?? [];
        }

        // File info
        $info['file_name'] = basename($this->apkPath);
        $info['file_size'] = filesize($this->apkPath);
        $info['file_size_fmt'] = formatSize($info['file_size']);

        // APK signature / cert info
        $certOut = @shell_exec("aapt dump xmltree $apkEsc AndroidManifest.xml 2>/dev/null");
        $info['manifest_raw'] = $certOut ?? '';

        $this->totalFiles++;
        $this->totalSize += $info['file_size'];
        $this->report['basic'] = $info;
    }

    private function decompile(): void {
        if (is_dir($this->decompileDir)) {
            $this->deleteDirContents($this->decompileDir);
        }

        $apkEsc = escapeshellarg($this->apkPath);
        $outEsc = escapeshellarg($this->decompileDir);

        // apktool d
        $cmd = "apktool d -f -o $outEsc $apkEsc 2>/dev/null";
        $ret = -1;
        $out = @exec($cmd, $_, $ret);

        if ($ret !== 0 || !is_dir($this->decompileDir . 'smali')) {
            $this->errors[] = "❌ فشل فك التجميع بـ apktool";
            return;
        }

        $this->report['decompile'] = [
            'success' => true,
            'tool' => 'apktool',
            'dir' => basename($this->decompileDir),
        ];

        $this->emit('progress', ['percent' => 25, 'current' => '📁 فهرسة الملفات المفككة']);

        // Count smali files
        $smaliFiles = 0;
        $resFiles = 0;
        $otherFiles = 0;
        $totalDecFiles = 0;
        $decSize = 0;

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->decompileDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $p = $f->getPathname();
                $totalDecFiles++;
                $decSize += $f->getSize();
                if (str_contains($p, '/smali')) $smaliFiles++;
                elseif (str_contains($p, '/res/')) $resFiles++;
                else $otherFiles++;
            }
        }

        $this->report['decompile']['stats'] = [
            'total_files' => $totalDecFiles,
            'total_size' => $decSize,
            'size_fmt' => formatSize($decSize),
            'smali_files' => $smaliFiles,
            'resource_files' => $resFiles,
            'other_files' => $otherFiles,
        ];

        $this->totalFiles += $totalDecFiles;
        $this->totalSize += $decSize;
    }

    private function extractStrings(): void {
        $stringsDir = $this->reportDir . 'strings/';
        @mkdir($stringsDir, 0777, true);

        // 1. Extract all strings from decompiled files
        $allStrings = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->decompileDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $skipped = 0;
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getSize() > 1048576) {
                if ($f->isFile() && $f->getSize() > 1048576) $skipped++;
                continue;
            }
            $ext = $f->getExtension();
            if (!in_array($ext, ['smali', 'xml', 'json', 'txt', 'html', 'yml', 'yaml', 'properties', 'config', 'conf'])) continue;
            $content = @file_get_contents($f->getPathname());
            if (!$content) continue;
            preg_match_all('/"([^"]{4,})"/', $content, $m);
            foreach ($m[1] as $s) {
                $s = trim($s);
                if (strlen($s) >= 4 && !preg_match('/^[0-9\s]+$/', $s)) {
                    $allStrings[$s] = ($allStrings[$s] ?? 0) + 1;
                }
            }
        }

        arsort($allStrings);
        $uniqueStrings = array_keys($allStrings);
        $stringContent = "=== STRINGS EXTRACTED ===\nTotal unique: " . count($uniqueStrings) . "\n\n";
        foreach ($uniqueStrings as $i => $s) {
            $stringContent .= ($i + 1) . ": $s\n";
            if ($i >= 9999) break;
        }
        file_put_contents($stringsDir . 'all_strings.txt', $stringContent);
        $this->totalFiles++;
        $this->totalSize += strlen($stringContent);

        // 2. Extract URLs
        $urls = [];
        $it2 = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->decompileDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it2 as $f) {
            if (!$f->isFile() || $f->getSize() > 1048576) continue;
            $content = @file_get_contents($f->getPathname());
            if (!$content) continue;
            preg_match_all('/https?:\/\/[^\s"\'<>]+/', $content, $m);
            foreach ($m[0] as $u) {
                $u = trim($u, '.,;:!?)\'"]');
                if (filter_var($u, FILTER_VALIDATE_URL)) {
                    $urls[$u] = ($urls[$u] ?? 0) + 1;
                }
            }
        }

        $urlContent = "=== URLs FOUND ===\nTotal: " . count($urls) . "\n\n";
        foreach ($urls as $u => $c) {
            $urlContent .= "[$c] $u\n";
        }
        file_put_contents($stringsDir . 'urls.txt', $urlContent);
        $this->totalFiles++;
        $this->totalSize += strlen($urlContent);

        $this->report['strings'] = [
            'unique_strings' => count($uniqueStrings),
            'urls_found' => count($urls),
            'skipped_large_files' => $skipped,
        ];
    }

    private function findSecrets(): void {
        $findings = [
            'api_keys' => [],
            'tokens' => [],
            'passwords' => [],
            'crypto_keys' => [],
            'firebase' => [],
            'aws_keys' => [],
            'jwt_tokens' => [],
            'db_connections' => [],
            'email_config' => [],
            'oauth_keys' => [],
            'telegram_bots' => [],
            'stripe_keys' => [],
            'github_tokens' => [],
            'slack_webhooks' => [],
            'private_keys' => [],
            'custom_urls' => [],
            'obfuscation' => [],
            'dangerous_perms' => [],
            'webview' => [],
            'dynamic_code' => [],
            'native_code_usage' => [],
            'ssl_pinning' => [],
            'root_detection' => [],
            'encryption_keys' => [],
        ];

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->decompileDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $allPermissions = $this->report['basic']['permissions'] ?? [];
        $dangerousPerms = [
            'android.permission.READ_SMS', 'android.permission.SEND_SMS', 'android.permission.RECEIVE_SMS',
            'android.permission.READ_CONTACTS', 'android.permission.READ_CALL_LOG',
            'android.permission.CAMERA', 'android.permission.RECORD_AUDIO',
            'android.permission.ACCESS_FINE_LOCATION', 'android.permission.ACCESS_COARSE_LOCATION',
            'android.permission.READ_EXTERNAL_STORAGE', 'android.permission.WRITE_EXTERNAL_STORAGE',
            'android.permission.READ_PHONE_STATE', 'android.permission.PROCESS_OUTGOING_CALLS',
            'android.permission.INSTALL_PACKAGES', 'android.permission.REQUEST_INSTALL_PACKAGES',
            'android.permission.SYSTEM_ALERT_WINDOW', 'android.permission.BIND_ACCESSIBILITY_SERVICE',
            'android.permission.QUERY_ALL_PACKAGES', 'android.permission.MANAGE_EXTERNAL_STORAGE',
        ];
        foreach ($dangerousPerms as $p) {
            if (in_array($p, $allPermissions)) {
                $findings['dangerous_perms'][] = $p;
            }
        }

        foreach ($it as $f) {
            if (!$f->isFile() || $f->getSize() > 1048576) continue;
            $content = @file_get_contents($f->getPathname());
            $relPath = substr($f->getPathname(), strlen($this->decompileDir));
            if (!$content) continue;
            $lines = explode("\n", $content);

            foreach ($lines as $ln => $line) {
                $lineNum = $ln + 1;

                // API Keys (generic)
                if (preg_match('/["\']([a-zA-Z0-9_\-]{20,45})["\']/', $line, $m) &&
                    !preg_match('/[а-яА-Я\s]/', $m[1]) &&
                    preg_match('/[A-Z]/', $m[1]) && preg_match('/[a-z]/', $m[1]) && preg_match('/[0-9]/', $m[1])) {
                    $key = $m[1];
                    $ctx = $this->getContext($line, $key);
                    if ($this->isLikelySecret($key, $line)) {
                        $findings['api_keys'][] = ['key' => $key, 'file' => $relPath, 'line' => $lineNum, 'context' => $ctx];
                    }
                }

                // AWS Keys
                if (preg_match('/AKIA[0-9A-Z]{16}/', $line, $m)) {
                    $findings['aws_keys'][] = ['key' => $m[0], 'file' => $relPath, 'line' => $lineNum];
                }
                if (preg_match('/["\']?((?:aws|amazon).{0,20}(?:secret|access|key).{0,20}["\']?\s*[:=]\s*["\'][^"\']+["\'])/i', $line, $m)) {
                    $findings['aws_keys'][] = ['key' => $m[1], 'file' => $relPath, 'line' => $lineNum];
                }

                // Firebase URLs
                if (preg_match('/https?:\/\/[a-zA-Z0-9-]+\.firebaseio\.com/', $line, $m)) {
                    $findings['firebase'][] = ['url' => $m[0], 'file' => $relPath, 'line' => $lineNum];
                }
                if (preg_match('/[a-zA-Z0-9]+:AIza[0-9A-Za-z_\-]{35}/', $line, $m)) {
                    $findings['firebase'][] = ['key' => $m[0], 'file' => $relPath, 'line' => $lineNum];
                }

                // JWT
                if (preg_match('/eyJ[a-zA-Z0-9_-]{10,}\.[a-zA-Z0-9_-]{10,}\.[a-zA-Z0-9_-]{10,}/', $line, $m)) {
                    $findings['jwt_tokens'][] = ['token' => substr($m[0], 0, 50) . '...', 'file' => $relPath, 'line' => $lineNum];
                }

                // Telegram bot tokens
                if (preg_match('/[0-9]{8,10}:[a-zA-Z0-9_-]{35}/', $line, $m)) {
                    $findings['telegram_bots'][] = ['token' => substr($m[0], 0, 20) . '...', 'file' => $relPath, 'line' => $lineNum];
                }

                // Stripe
                if (preg_match('/(?:sk|pk)_(?:live|test)_[0-9a-zA-Z]{10,}/', $line, $m)) {
                    $findings['stripe_keys'][] = ['key' => substr($m[0], 0, 20) . '...', 'file' => $relPath, 'line' => $lineNum];
                }

                // GitHub tokens
                if (preg_match('/ghp_[a-zA-Z0-9]{36}/', $line, $m)) {
                    $findings['github_tokens'][] = ['token' => substr($m[0], 0, 15) . '...', 'file' => $relPath, 'line' => $lineNum];
                }

                // Slack webhooks
                if (preg_match('/https:\/\/hooks\.slack\.com\/services\/[a-zA-Z0-9\/]+/', $line, $m)) {
                    $findings['slack_webhooks'][] = ['url' => $m[0], 'file' => $relPath, 'line' => $lineNum];
                }

                // Private keys
                if (preg_match('/-----BEGIN (?:RSA |EC )?PRIVATE KEY-----/', $line)) {
                    $findings['private_keys'][] = ['file' => $relPath, 'line' => $lineNum];
                }

                // Passwords
                if (preg_match('/["\'](?:password|passwd|pwd|pass)["\']\s*[:=]\s*["\'][^"\'"]+["\']/i', $line, $m)) {
                    $val = $m[0];
                    if (!preg_match('/["\'](?:password|passwd|pwd|pass)["\']\s*[:=]\s*["\'](?:true|false|null|undefined|0|1|your_password)["\']/i', $val)) {
                        $findings['passwords'][] = ['value' => $val, 'file' => $relPath, 'line' => $lineNum];
                    }
                }

                // DB connections
                if (preg_match('/(?:mysql|postgresql|mongodb|sqlite|redis):\/\/[^\s"\'<>]+/', $line, $m)) {
                    $findings['db_connections'][] = ['url' => $m[0], 'file' => $relPath, 'line' => $lineNum];
                }

                // Encryption keys
                if (preg_match('/(?:AES|DES|RSA|Blowfish|TripleDES|ChaCha20)[^a-zA-Z].{0,30}["\'][0-9a-zA-Z+\/=]{16,}["\']/i', $line, $m)) {
                    $findings['encryption_keys'][] = ['match' => substr($m[0], 0, 80), 'file' => $relPath, 'line' => $lineNum];
                }

                // Obfuscation detection
                if (preg_match('/\b(?:getString|loadClass|reflect|ClassLoader|forName|invoke|invokeMethod|dalvik|setAccessible)\b/i', $line)) {
                    $findings['obfuscation'][] = ['file' => $relPath, 'line' => $lineNum, 'code' => trim($line)];
                }

                // WebView
                if (preg_match('/(?:WebView|loadUrl|setJavaScriptEnabled|addJavascriptInterface|loadData|evaluateJavascript)/i', $line)) {
                    $findings['webview'][] = ['file' => $relPath, 'line' => $lineNum, 'code' => trim(substr($line, 0, 120))];
                }

                // Dynamic code loading
                if (preg_match('/(?:DexClassLoader|PathClassLoader|InMemoryDexClassLoader|loadDex|MultiDex|dalvik\.system\.DexFile)/i', $line)) {
                    $findings['dynamic_code'][] = ['file' => $relPath, 'line' => $lineNum, 'code' => trim(substr($line, 0, 120))];
                }

                // Native code usage
                if (preg_match('/\b(?:System\.loadLibrary|System\.load|JNI_|native\s)/', $line)) {
                    $findings['native_code_usage'][] = ['file' => $relPath, 'line' => $lineNum, 'code' => trim(substr($line, 0, 120))];
                }

                // SSL pinning
                if (preg_match('/(?:CertificatePinner|sslPinning|SSLPinning|certificatePinning|publishCert|checkServerTrusted)/i', $line)) {
                    $findings['ssl_pinning'][] = ['file' => $relPath, 'line' => $lineNum, 'code' => trim(substr($line, 0, 120))];
                }

                // Root detection
                if (preg_match('/(?:root_be?[ck]|detectRoot|isRooted|checkRoot|su\.exists|Superuser|supersu|busybox)/i', $line)) {
                    $findings['root_detection'][] = ['file' => $relPath, 'line' => $lineNum, 'code' => trim(substr($line, 0, 120))];
                }

                // Crypto constant keys
                if (preg_match('/["\']([0-9a-fA-F]{32,64})["\']/', $line, $m)) {
                    $hex = $m[1];
                    if (in_array(strlen($hex), [32, 48, 64]) && preg_match('/^[0-9a-fA-F]+$/', $hex)) {
                        $ctxS = $this->getContext($line, $hex);
                        $findings['crypto_keys'][] = ['hex' => $hex, 'len' => strlen($hex), 'file' => $relPath, 'line' => $lineNum, 'context' => $ctxS];
                    }
                }

                // Email config
                if (preg_match('/["\'](?:smtp|imap|pop3?)\.?\w+["\']\s*[:=]/i', $line)) {
                    $findings['email_config'][] = ['file' => $relPath, 'line' => $lineNum, 'code' => trim(substr($line, 0, 120))];
                }

                // OAuth
                if (preg_match('/(?:client_id|client_secret|oauth|redirect_uri|authorization_url|token_url)/i', $line)) {
                    $findings['oauth_keys'][] = ['file' => $relPath, 'line' => $lineNum, 'code' => trim(substr($line, 0, 120))];
                }
            }
        }

        // Filter unique findings
        foreach ($findings as $k => $v) {
            if (in_array($k, ['dangerous_perms'])) continue;
            $findings[$k] = array_slice($v, 0, 200);
        }

        $this->report['secrets'] = $findings;

        // Write findings report
        $secretContent = "=== SECURITY FINDINGS ===\n\n";
        $totalSecrets = 0;
        foreach ($findings as $category => $items) {
            if (empty($items)) continue;
            $totalSecrets += count($items);
            $label = str_replace('_', ' ', strtoupper($category));
            $secretContent .= "--- $label ---\n";
            foreach ($items as $item) {
                if (is_string($item)) {
                    $secretContent .= "  $item\n";
                } else {
                    $file = $item['file'] ?? '';
                    $line = $item['line'] ?? '';
                    $loc = $file ? "$file:$line" : '';
                    $val = $item['key'] ?? $item['url'] ?? $item['token'] ?? $item['value'] ?? $item['code'] ?? $item['match'] ?? $item['hex'] ?? $item['context'] ?? json_encode($item);
                    $secretContent .= "  $loc - $val\n";
                }
            }
            $secretContent .= "\n";
        }
        $secretContent .= "=== TOTAL FINDINGS: $totalSecrets ===\n";
        file_put_contents($this->reportDir . 'security_findings.txt', $secretContent);
        $this->totalFiles++;
        $this->totalSize += strlen($secretContent);
    }

    private function isLikelySecret(string $key, string $line): bool {
        $lineLower = strtolower($line);
        $keyWords = ['key', 'api', 'token', 'secret', 'auth', 'pass', 'bearer', 'jwt', 'credential', 'signature', 'hash', 'encrypt', 'private', 'public'];
        foreach ($keyWords as $w) {
            if (str_contains($lineLower, $w)) return true;
        }
        return false;
    }

    private function getContext(string $line, string $match): string {
        $pos = mb_strpos($line, $match);
        if ($pos === false) return trim(substr($line, 0, 100));
        $start = max(0, $pos - 30);
        $end = min(mb_strlen($line), $pos + mb_strlen($match) + 30);
        return trim(mb_substr($line, $start, $end - $start));
    }

    private function aiAnalysis(): void {
        $apiKey = $this->config['ai_api_key'];
        if (empty($apiKey)) {
            $this->report['ai'] = ['status' => 'skipped', 'reason' => 'No API key configured'];
            return;
        }

        $findings = $this->report['secrets'] ?? [];

        $prompt = "أنت خبير أمن تطبيقات Android. حلل التقرير التالي وقدم:\n";
        $prompt .= "1. تقييم أمني عام (1-10)\n";
        $prompt .= "2. أهم الثغرات المكتشفة\n";
        $prompt .= "3. توصيات للإصلاح\n";
        $prompt .= "4. تحليل الأكواد الضارة المحتملة\n";
        $prompt .= "5. تحليل التشفير المستخدم وقوته\n\n";

        $prompt .= "## معلومات أساسية\n";
        $basic = $this->report['basic'] ?? [];
        $prompt .= "- الحزمة: " . ($basic['package'] ?? 'unknown') . "\n";
        $prompt .= "- الإصدار: " . ($basic['version_name'] ?? '') . " (code: " . ($basic['version_code'] ?? '') . ")\n";
        $prompt .= "- minSdk: " . ($basic['min_sdk'] ?? '') . ", targetSdk: " . ($basic['target_sdk'] ?? '') . "\n";
        $prompt .= "- عدد الصلاحيات: " . count($basic['permissions'] ?? []) . "\n";
        $prompt .= "- الأنشطة: " . count($basic['activities'] ?? []) . "\n";
        $prompt .= "- الخدمات: " . count($basic['services'] ?? []) . "\n\n";

        $prompt .= "## الصلاحيات الخطرة\n";
        foreach (($findings['dangerous_perms'] ?? []) as $p) {
            $prompt .= "- $p\n";
        }

        $secretCategories = ['api_keys', 'tokens', 'passwords', 'crypto_keys', 'encryption_keys', 'firebase', 'aws_keys', 'jwt_tokens', 'db_connections', 'telegram_bots', 'stripe_keys', 'github_tokens', 'private_keys', 'obfuscation', 'webview', 'dynamic_code', 'native_code_usage', 'ssl_pinning', 'root_detection'];
        $prompt .= "\n## الاكتشافات الأمنية\n";
        $hasAny = false;
        foreach ($secretCategories as $cat) {
            if (!empty($findings[$cat])) {
                $hasAny = true;
                $prompt .= "\n### " . str_replace('_', ' ', strtoupper($cat)) . "\n";
                foreach (array_slice($findings[$cat], 0, 20) as $item) {
                    $prompt .= "- " . json_encode($item, JSON_UNESCAPED_UNICODE) . "\n";
                }
            }
        }
        if (!$hasAny) $prompt .= "لا توجد اكتشافات ملحوظة\n";

        $prompt .= "\n## الصلاحيات\n";
        foreach (($basic['permissions'] ?? []) as $perm) {
            $prompt .= "- $perm\n";
        }

        $baseUrl = rtrim($this->config['ai_base_url'], '/');
        $model = $this->config['ai_model'];
        $url = "$baseUrl/models/$model:generateContent?key=$apiKey";

        $payload = json_encode([
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 4096,
                'topP' => 0.95,
            ],
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $this->report['ai'] = [
                'status' => 'success',
                'analysis' => $text,
                'model' => $model,
            ];

            file_put_contents($this->reportDir . 'ai_analysis.txt', $text);
            $this->totalFiles++;
            $this->totalSize += strlen($text);
        } else {
            $this->report['ai'] = [
                'status' => 'error',
                'http_code' => $httpCode,
                'response' => substr($response ?? '', 0, 500),
            ];
            $this->errors[] = "⚠️ فشل الاتصال بالذكاء الاصطناعي (HTTP $httpCode)";
        }
    }

    private function generateReport(): void {
        $basic = $this->report['basic'] ?? [];
        $decompile = $this->report['decompile'] ?? [];
        $strings = $this->report['strings'] ?? [];
        $findings = $this->report['secrets'] ?? [];
        $ai = $this->report['ai'] ?? [];

        $totalFindings = 0;
        $secretCategories = ['api_keys', 'tokens', 'passwords', 'crypto_keys', 'encryption_keys', 'firebase', 'aws_keys', 'jwt_tokens', 'db_connections', 'email_config', 'oauth_keys', 'telegram_bots', 'stripe_keys', 'github_tokens', 'slack_webhooks', 'private_keys', 'obfuscation', 'webview', 'dynamic_code', 'native_code_usage', 'ssl_pinning', 'root_detection'];
        foreach ($secretCategories as $cat) {
            if (isset($findings[$cat])) $totalFindings += count($findings[$cat]);
        }

        $html = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>تقرير تحليل APK</title>';
        $html .= '<style>
body{font-family:"Segoe UI",system-ui,sans-serif;background:#0a0a1a;color:#e0e0e0;margin:0;padding:20px;direction:rtl}
.container{max-width:1000px;margin:auto}
h1{color:#60a5fa;border-bottom:2px solid #1e3a5f;padding-bottom:10px}
h2{color:#93c5fd;margin-top:30px}
h3{color:#b9d4fd}
.section{background:#111128;border-radius:12px;padding:16px;margin:12px 0;border:1px solid #1e293b}
.badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:0.75rem;margin:2px}
.badge-red{background:#7f1d1d;color:#fca5a5}
.badge-yellow{background:#713f12;color:#fcd34d}
.badge-green{background:#14532d;color:#86efac}
.badge-blue{background:#1e3a5f;color:#93c5fd}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px}
.item{background:rgba(255,255,255,0.03);padding:8px 12px;border-radius:6px;font-size:0.85rem}
.label{color:#94a3b8;font-size:0.75rem}
.value{color:#e2e8f0}
.finding{background:rgba(239,68,68,0.08);border-right:3px solid #ef4444;padding:8px 12px;margin:4px 0;border-radius:0 6px 6px 0;font-size:0.82rem;overflow-x:auto;white-space:pre-wrap;word-break:break-all}
.ai-box{background:rgba(59,130,246,0.05);border:1px solid rgba(59,130,246,0.2);border-radius:10px;padding:16px;white-space:pre-wrap;font-size:0.9rem;line-height:1.6}
.perms-list{max-height:300px;overflow-y:auto}
code{background:rgba(0,0,0,0.3);padding:1px 5px;border-radius:4px;font-size:0.8rem}
.summary-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin:16px 0}
.summary-card{background:linear-gradient(135deg,rgba(59,130,246,0.1),rgba(139,92,246,0.1));border-radius:10px;padding:16px;text-align:center}
.summary-num{font-size:1.8rem;font-weight:700;color:#60a5fa}
.summary-label{font-size:0.75rem;color:#94a3b8;margin-top:4px}
.tab{display:flex;gap:4px;margin:16px 0;flex-wrap:wrap}
.tab button{background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);color:#94a3b8;padding:8px 16px;border-radius:8px;cursor:pointer;font-size:0.8rem}
.tab button.active{background:rgba(59,130,246,0.2);color:#60a5fa;border-color:#3b82f6}
.tab-content{display:none}
.tab-content.active{display:block}
.dl-btn{display:inline-block;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;padding:12px 32px;border-radius:10px;text-decoration:none;font-weight:600;margin:16px 0}
.dl-btn:hover{opacity:0.9}
</style></head><body>';
        $html .= '<div class="container">';

        // Header
        $html .= '<h1>🔍 تقرير تحليل APK</h1>';
        $html .= '<div class="summary-grid">';
        $html .= '<div class="summary-card"><div class="summary-num">' . ($basic['file_name'] ?? 'N/A') . '</div><div class="summary-label">اسم الملف</div></div>';
        $html .= '<div class="summary-card"><div class="summary-num">' . ($basic['file_size_fmt'] ?? '0') . '</div><div class="summary-label">حجم الملف</div></div>';
        $html .= '<div class="summary-card"><div class="summary-num">' . ($decompile['stats']['total_files'] ?? 0) . '</div><div class="summary-label">ملفات مفككة</div></div>';
        $html .= '<div class="summary-card"><div class="summary-num">' . ($strings['unique_strings'] ?? 0) . '</div><div class="summary-label">نصوص مستخرجة</div></div>';
        $html .= '<div class="summary-card"><div class="summary-num">' . $totalFindings . '</div><div class="summary-label">اكتشافات أمنية</div></div>';
        $html .= '<div class="summary-card"><div class="summary-num">' . count($basic['permissions'] ?? []) . '</div><div class="summary-label">صلاحيات</div></div>';
        $html .= '</div>';

        // AI Analysis
        if (isset($ai['analysis'])) {
            $html .= '<div class="section"><h2>🧠 تحليل الذكاء الاصطناعي</h2><div class="ai-box">' . nl2br(e($ai['analysis'])) . '</div></div>';
        } elseif (isset($ai['status']) && $ai['status'] === 'skipped') {
            $html .= '<div class="section"><h2>🧠 تحليل الذكاء الاصطناعي</h2><p style="color:#94a3b8">⚠️ لم يتم تفعيل تحليل AI — يرجى إضافة مفتاح API في الإعدادات</p></div>';
        } else {
            $html .= '<div class="section"><h2>🧠 تحليل الذكاء الاصطناعي</h2><p style="color:#fca5a5">❌ فشل الاتصال بالذكاء الاصطناعي</p></div>';
        }

        // Categories tab
        $html .= '<div class="tab" id="reportTabs">';
        $tabs = ['basic' => '📦 أساسيات', 'permissions' => '🔐 صلاحيات', 'findings' => '🕵️ اكتشافات', 'strings' => '📝 نصوص', 'structure' => '🏗️ هيكل'];
        foreach ($tabs as $id => $label) {
            $active = $id === 'basic' ? ' active' : '';
            $html .= "<button class=\"tab-btn$active\" onclick=\"switchTab('$id')\">$label</button>";
        }
        $html .= '</div>';

        // Basic tab
        $html .= '<div id="tab-basic" class="tab-content active"><div class="section">';
        $html .= '<h2>📦 معلومات أساسية</h2><div class="grid">';
        $fields = [
            'الحزمة' => $basic['package'] ?? '-',
            'اسم التطبيق' => $basic['app_name'] ?? $basic['app_label'] ?? '-',
            'الإصدار' => ($basic['version_name'] ?? '') . ' (code ' . ($basic['version_code'] ?? '') . ')',
            'minSdk' => $basic['min_sdk'] ?? '-',
            'targetSdk' => $basic['target_sdk'] ?? '-',
            'compileSdk' => $basic['compile_sdk'] ?? '-',
            'عدد الأنشطة' => count($basic['activities'] ?? []),
            'عدد الخدمات' => count($basic['services'] ?? []),
            'عدد المستقبلات' => count($basic['receivers'] ?? []),
            'عدد المزوّدين' => count($basic['providers'] ?? []),
            'Native Libs' => implode(', ', $basic['native_code'] ?? []) ?: '-',
            'عدد الميزات' => count($basic['features'] ?? []),
        ];
        foreach ($fields as $k => $v) {
            $html .= "<div class=\"item\"><div class=\"label\">$k</div><div class=\"value\">" . e($v) . "</div></div>";
        }
        $html .= '</div></div></div>';

        // Permissions tab
        $html .= '<div id="tab-permissions" class="tab-content"><div class="section">';
        $html .= '<h2>🔐 الصلاحيات</h2><div class="perms-list">';
        $perms = $basic['permissions'] ?? [];
        $dangerousPermsList = $findings['dangerous_perms'] ?? [];
        if (empty($perms)) {
            $html .= '<p style="color:#94a3b8">لا توجد صلاحيات</p>';
        } else {
            foreach ($perms as $perm) {
                $isDanger = in_array($perm, $dangerousPermsList);
                $badge = $isDanger ? 'badge-red' : 'badge-blue';
                $html .= "<div><span class=\"badge $badge\">" . ($isDanger ? '⚠️' : 'ℹ️') . '</span> ' . e($perm) . "</div>";
            }
        }
        $html .= '</div></div></div>';

        // Findings tab
        $html .= '<div id="tab-findings" class="tab-content"><div class="section">';
        $html .= '<h2>🕵️ الاكتشافات الأمنية</h2>';
        $catLabels = [
            'api_keys' => '🔑 API Keys', 'tokens' => '🎫 Tokens', 'passwords' => '🔒 كلمات مرور',
            'crypto_keys' => '🔐 مفاتيح تشفير (HEX)', 'encryption_keys' => '🔏 مفاتيح تشفير',
            'firebase' => '🔥 Firebase', 'aws_keys' => '☁️ AWS Keys', 'jwt_tokens' => '📜 JWT Tokens',
            'db_connections' => '🗄️ اتصالات قواعد بيانات', 'email_config' => '📧 إعدادات بريد',
            'oauth_keys' => '🔑 OAuth Keys', 'telegram_bots' => '✈️ توكنات تيليجرام',
            'stripe_keys' => '💳 Stripe Keys', 'github_tokens' => '🐙 GitHub Tokens',
            'slack_webhooks' => '💬 Slack Webhooks',  'private_keys' => '🔑 مفاتيح خاصة',
            'obfuscation' => '🌀 تشويش/Reflection', 'webview' => '🌐 WebView', 'dynamic_code' => '📦 تحميل ديناميكي',
            'native_code_usage' => '⚙️ كود أصلي', 'ssl_pinning' => '🔒 SSL Pinning', 'root_detection' => '📱 كشف روت',
        ];
        $hasFindings = false;
        foreach ($catLabels as $cat => $label) {
            if (empty($findings[$cat])) continue;
            $hasFindings = true;
            $html .= "<h3>$label <span class=\"badge badge-red\">" . count($findings[$cat]) . "</span></h3>";
            foreach ($findings[$cat] as $item) {
                if (is_string($item)) {
                    $html .= "<div class=\"finding\">" . e($item) . "</div>";
                } else {
                    $text = $item['key'] ?? $item['url'] ?? $item['token'] ?? $item['value'] ?? $item['code'] ?? $item['match'] ?? $item['hex'] ?? $item['context'] ?? json_encode($item);
                    $file = $item['file'] ?? '';
                    $line = $item['line'] ?? '';
                    $loc = $file ? " <code style=\"font-size:0.7rem\">$file:$line</code>" : '';
                    $html .= "<div class=\"finding\">" . e(mb_substr($text, 0, 200)) . "$loc</div>";
                }
            }
        }
        if (!$hasFindings) {
            $html .= '<p style="color:#86efac">✅ لا توجد اكتشافات أمنية ملحوظة</p>';
        }
        $html .= '</div></div>';

        // Strings tab
        $html .= '<div id="tab-strings" class="tab-content"><div class="section">';
        $html .= '<h2>📝 النصوص المستخرجة</h2>';
        $html .= '<p style="color:#94a3b8">إجمالي النصوص الفريدة: ' . ($strings['unique_strings'] ?? 0) . '</p>';
        $html .= '<p style="color:#94a3b8">الروابط: ' . ($strings['urls_found'] ?? 0) . '</p>';
        $stringsFile = $this->reportDir . 'strings/all_strings.txt';
        if (file_exists($stringsFile)) {
            $preview = file_get_contents($stringsFile, false, null, 0, 5000);
            $html .= '<div style="background:rgba(0,0,0,0.2);border-radius:6px;padding:12px;max-height:400px;overflow-y:auto;font-size:0.75rem;font-family:monospace;white-space:pre-wrap">' . e($preview) . '</div>';
        }
        $html .= '</div></div>';

        // Structure tab
        $html .= '<div id="tab-structure" class="tab-content"><div class="section">';
        $html .= '<h2>🏗️ هيكل التطبيق</h2>';
        $html .= '<div class="grid">';
        if (!empty($basic['activities'])) {
            $html .= '<div class="item"><div class="label">الأنشطة (' . count($basic['activities']) . ')</div>';
            foreach (array_slice($basic['activities'], 0, 20) as $a) {
                $html .= "<div style=\"font-size:0.75rem;padding:2px 0;color:#93c5fd\">" . e($a) . "</div>";
            }
            if (count($basic['activities']) > 20) $html .= "<div style=\"font-size:0.7rem;color:#94a3b8\">+ " . (count($basic['activities']) - 20) . " إضافية</div>";
            $html .= '</div>';
        }
        if (!empty($basic['services'])) {
            $html .= '<div class="item"><div class="label">الخدمات (' . count($basic['services']) . ')</div>';
            foreach (array_slice($basic['services'], 0, 10) as $s) {
                $html .= "<div style=\"font-size:0.75rem;padding:2px 0;color:#93c5fd\">" . e($s) . "</div>";
            }
            if (count($basic['services']) > 10) $html .= "<div style=\"font-size:0.7rem;color:#94a3b8\">+ " . (count($basic['services']) - 10) . " إضافية</div>";
            $html .= '</div>';
        }
        if (!empty($basic['receivers'])) {
            $html .= '<div class="item"><div class="label">المستقبلات (' . count($basic['receivers']) . ')</div>';
            foreach (array_slice($basic['receivers'], 0, 10) as $r) {
                $html .= "<div style=\"font-size:0.75rem;padding:2px 0;color:#93c5fd\">" . e($r) . "</div>";
            }
            if (count($basic['receivers']) > 10) $html .= "<div style=\"font-size:0.7rem;color:#94a3b8\">+ " . (count($basic['receivers']) - 10) . " إضافية</div>";
            $html .= '</div>';
        }
        $html .= '</div>';

        // Smali files
        $smaliCount = $decompile['stats']['smali_files'] ?? 0;
        $resCount = $decompile['stats']['resource_files'] ?? 0;
        $html .= '<div class="grid" style="margin-top:12px">';
        $html .= "<div class=\"item\"><div class=\"label\">ملفات Smali</div><div class=\"value\">$smaliCount</div></div>";
        $html .= "<div class=\"item\"><div class=\"label\">ملفات موارد</div><div class=\"value\">$resCount</div></div>";
        $html .= "<div class=\"item\"><div class=\"label\">إجمالي الملفات المفككة</div><div class=\"value\">" . ($decompile['stats']['total_files'] ?? 0) . "</div></div>";
        $html .= "<div class=\"item\"><div class=\"label\">حجم الكود المفكك</div><div class=\"value\">" . ($decompile['stats']['size_fmt'] ?? '0') . "</div></div>";
        $html .= '</div></div></div>';

        // Download buttons
        $dlDir = $this->report['dir'] ?? basename($this->workDir);
        $html .= '<div style="text-align:center;margin:24px 0">';
        $html .= "<a class=\"dl-btn\" href=\"api.php?action=download_zip&dir=$dlDir\">📥 تحميل التقرير الكامل (ZIP)</a>";
        $html .= '</div>';

        $html .= '</div>'; // container

        // Tab switching JS
        $html .= '<script>
function switchTab(id){
document.querySelectorAll(".tab-content").forEach(e=>e.classList.remove("active"));
document.querySelectorAll(".tab-btn").forEach(e=>e.classList.remove("active"));
document.getElementById("tab-"+id).classList.add("active");
document.querySelector(`.tab-btn[onclick*="\'${id}\'"]`).classList.add("active");
}
</script>';

        $html .= '</body></html>';

        file_put_contents($this->reportDir . 'report.html', $html);
        $this->totalFiles++;
        $this->totalSize += strlen($html);
    }

    private function deleteDirContents(string $dir): void {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $f) {
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->deleteDirContents($p) : @unlink($p);
        }
    }

    public static function handleUpload(array $file): ?string {
        $allowedExt = ['apk', 'xapk', 'apkm', 'apks'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt)) return null;
        if ($file['size'] > 500 * 1024 * 1024) return null;

        $dest = TEMP_DIR . md5($file['name'] . time() . rand()) . '.' . $ext;
        if (isset($file['tmp_name']) && file_exists($file['tmp_name'])) {
            if (@move_uploaded_file($file['tmp_name'], $dest)) return $dest;
            if (@copy($file['tmp_name'], $dest)) return $dest;
        }
        return null;
    }
}
