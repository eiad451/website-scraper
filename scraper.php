<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

class SiteScraper {
    private $baseUrl, $saveDir, $baseDomain;
    private $visited = [], $queued = [], $downloaded = [];
    private $realErrors = [], $probeErrors = [];
    private $totalSize = 0, $totalFiles = 0;
    private $startTime, $progressCb = null;
    private $maxDepth, $maxFiles, $concurrent, $timeout;
    private $scanHidden, $scanAdmin, $scanDB, $rateLimitDetected = false;
    private $secretKeywords = [], $secretMatches = [];

    public function __construct($baseUrl, $saveDir, $opts = []) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->saveDir = rtrim($saveDir, '/') . '/';
        $this->baseDomain = strtolower(parse_url($baseUrl, PHP_URL_HOST) ?: '');
        $this->startTime = time();
        $this->maxDepth = $opts['max_depth'] ?? 20;
        $this->maxFiles = $opts['max_files'] ?? 100000;
        $this->concurrent = $opts['concurrent'] ?? 30;
        $this->timeout = $opts['timeout'] ?? 30;
        $this->scanHidden = $opts['scan_hidden'] ?? true;
        $this->scanAdmin = $opts['scan_admin'] ?? true;
        $this->scanDB = $opts['scan_db'] ?? true;
        if (!empty($opts['secret_keywords'])) {
            $this->secretKeywords = array_filter(array_map('trim', explode("\n", $opts['secret_keywords'])));
        }
        if (!is_dir($this->saveDir)) @mkdir($this->saveDir, 0777, true);
    }

    public function setProgressCallback($cb) { $this->progressCb = $cb; }

    public function scrape() {
        $this->emit('start', ['message' => '...']);

        $mh = curl_multi_init();
        $curlMap = [];

        // Phase 1: Main crawl
        $queue = [['url' => $this->baseUrl, 'depth' => 0, 'type' => 'html']];
        $this->queued[md5($this->baseUrl)] = true;
        $this->processCrawl($mh, $curlMap, $queue, $this->timeout);

        // Phase 2: Feeds/Sitemaps
        $feedPaths = $this->discoverFeeds();
        if (!empty($feedPaths)) {
            $feedQueue = [];
            foreach ($feedPaths as $u) $feedQueue[] = ['url' => $u, 'depth' => 0, 'type' => 'feed'];
            $this->processCrawl($mh, $curlMap, $feedQueue, 10);
        }

        // Phase 3: Site index scan
        $indexPaths = $this->discoverSiteIndex();
        if (!empty($indexPaths)) {
            $idxQueue = [];
            foreach ($indexPaths as $u) $idxQueue[] = ['url' => $u, 'depth' => 1, 'type' => 'index'];
            $this->processCrawl($mh, $curlMap, $idxQueue, 8);
        }

        // Phase 4: Discovery (hidden/admin/db)
        $discPaths = [];
        if ($this->scanHidden) $discPaths = array_merge($discPaths, $this->buildDiscoveryPaths());
        if ($this->scanAdmin)  $discPaths = array_merge($discPaths, $this->buildAdminPaths());
        if ($this->scanDB)     $discPaths = array_merge($discPaths, $this->buildDBPaths());

        if (!empty($discPaths)) {
            if (count($discPaths) > 1000) { shuffle($discPaths); $discPaths = array_slice($discPaths, 0, 1000); }
            $this->emit('progress', [
                'percent' => min(round(($this->totalFiles / max($this->maxFiles, 1)) * 100, 1), 99),
                'files' => $this->totalFiles, 'size' => formatSize($this->totalSize),
                'size_bytes' => $this->totalSize, 'errors' => count($this->realErrors),
                'current' => '.. ' . count($discPaths) . ' ...',
                'hidden_found' => 0, 'admin_found' => 0, 'db_found' => 0,
            ]);
            $discQueue = [];
            foreach ($discPaths as $u) $discQueue[] = ['url' => $u, 'depth' => 99, 'type' => 'disc'];
            $this->processCrawl($mh, $curlMap, $discQueue, 3);
        }

        // Phase 5: Re-crawl for new links
        $reCrawlUrls = $this->findUnvisitedLinks();
        if (!empty($reCrawlUrls)) {
            $reQueue = [];
            foreach ($reCrawlUrls as $u) $reQueue[] = ['url' => $u, 'depth' => 5, 'type' => 'recrawl'];
            $this->processCrawl($mh, $curlMap, $reQueue, $this->timeout);
        }

        curl_multi_close($mh);

        return [
            'total_files' => $this->totalFiles, 'total_size' => $this->totalSize,
            'total_size_fmt' => formatSize($this->totalSize),
            'real_errors' => $this->realErrors, 'probe_errors' => $this->probeErrors,
            'duration' => time() - $this->startTime,
            'secret_matches' => $this->secretMatches,
            'secrets_found' => count($this->secretMatches),
        ];
    }

    private function processCrawl($mh, &$curlMap, &$queue, $timeout) {
        while (!empty($queue) || count($curlMap) > 0) {
            while (count($curlMap) < $this->concurrent && !empty($queue)) {
                if ($this->totalFiles >= $this->maxFiles) break 2;
                $item = array_shift($queue);
                $url = $item['url']; $depth = $item['depth']; $type = $item['type'];
                $key = md5($url);
                if (isset($this->visited[$key])) continue;
                $this->visited[$key] = true;

                if ($this->rateLimitDetected) usleep(100000);

                $ch = curl_init();
                $ua = unserialize(USER_AGENTS)[array_rand(unserialize(USER_AGENTS))];
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                    CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_USERAGENT => $ua, CURLOPT_ENCODING => '',
                    CURLOPT_HTTPHEADER => [
                        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                        'Accept-Language: en-US,en;q=0.9,ar;q=0.8',
                    ],
                ]);
                curl_multi_add_handle($mh, $ch);
                $curlMap[(int)$ch] = ['ch' => $ch, 'url' => $url, 'depth' => $depth, 'type' => $type, 'retries' => 0];
            }

            if (count($curlMap) === 0) break;
            $running = 0;
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 1);

            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $key = (int)$ch;
                if (!isset($curlMap[$key])) continue;
                $item = $curlMap[$key];
                $url = $item['url']; $depth = $item['depth']; $type = $item['type'];
                $body = curl_multi_getcontent($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_multi_remove_handle($mh, $ch);
                unset($curlMap[$key]);

                if ($httpCode === 429) $this->rateLimitDetected = true;

                $bypassed = false;
                if ($body === false || $body === '' || $httpCode >= 400 || $error) {
                    if (!in_array($type, ['disc', 'feed', 'index', 'recrawl']) && $item['retries'] < 2 && ($httpCode === 403 || $httpCode === 0)) {
                        $cfResult = cloudScrape($url, min($timeout, 10));
                        if ($cfResult && !isset($cfResult['error']) && $cfResult['status'] >= 200 && $cfResult['status'] < 400) {
                            $body = base64_decode($cfResult['body_b64']);
                            $httpCode = $cfResult['status'];
                            $bypassed = true;
                        }
                    }
                    if (!$bypassed) {
                        if (!in_array($type, ['disc', 'feed', 'index', 'recrawl']) && $item['retries'] < 2 && ($httpCode === 0 || $httpCode >= 500 || $httpCode === 429 || $error)) {
                            $item['retries']++;
                            $queue[] = $item;
                        } else {
                            $errMsg = $error ? "[TIMEOUT] $url" : "[$httpCode] $url";
                            if (in_array($type, ['disc', 'feed', 'index', 'recrawl'])) $this->probeErrors[] = $errMsg;
                            else $this->realErrors[] = $errMsg;
                        }
                        continue;
                    }
                }

                if ($httpCode >= 200 && $httpCode < 400 && !in_array($type, ['disc', 'feed', 'index']) && isCloudflareBlock($body)) {
                    $cfResult = cloudScrape($url, min($timeout, 10));
                    if ($cfResult && !isset($cfResult['error']) && $cfResult['status'] >= 200 && $cfResult['status'] < 400 && !isCloudflareBlock(base64_decode($cfResult['body_b64']))) {
                        $body = base64_decode($cfResult['body_b64']);
                        $httpCode = $cfResult['status'];
                    }
                }

                $path = $this->makePath($url);
                $full = $this->saveDir . $path;
                if (!is_dir(dirname($full))) @mkdir(dirname($full), 0777, true);
                file_put_contents($full, $body);

                if (!empty($this->secretKeywords)) {
                    foreach ($this->secretKeywords as $kw) {
                        if (str_contains($body, $kw) || str_contains($body, strtolower($kw)) || str_contains($body, strtoupper($kw))) {
                            $this->secretMatches[] = ['keyword' => $kw, 'url' => $url, 'file' => $path];
                        }
                    }
                }

                $size = strlen($body);
                $this->totalSize += $size;
                $this->totalFiles++;
                $this->downloaded[] = ['url' => $url, 'path' => $path, 'size' => $size];

                if ($this->totalFiles % 3 === 0 || count($curlMap) === 0) {
                    $this->emit('progress', $this->getProgressData());
                }

                if ($depth < $this->maxDepth && $type !== 'disc') {
                    $ct = $this->detectType($url, $body);
                    $extracted = [];
                    if ($ct === 'html' || $ct === 'feed') $extracted = $this->extractLinks($url, $body);
                    elseif ($ct === 'css') $extracted = $this->extractCSS($url, $body);
                    elseif ($ct === 'js') $extracted = $this->extractJS($url, $body);
                    elseif ($ct === 'json') $extracted = $this->extractJSON($url, $body);
                    elseif ($ct === 'xml') $extracted = $this->extractXML($url, $body);
                    $extracted = array_merge($extracted, $this->extractRawUrls($url, $body));
                    foreach ($extracted as $l) {
                        $lk = md5($l);
                        if (!isset($this->visited[$lk]) && !isset($this->queued[$lk]) && $this->sameSite($l)) {
                            $this->queued[$lk] = true;
                            $queue[] = ['url' => $l, 'depth' => $depth + 1, 'type' => $ct];
                        }
                    }
                }
                if ($this->totalFiles >= $this->maxFiles) break 2;
            }
        }
    }

    private function discoverFeeds() {
        $p = parse_url($this->baseUrl);
        $root = ($p['scheme'] ?? 'http') . '://' . ($p['host'] ?? '');
        return [
            $root.'/robots.txt', $root.'/sitemap.xml', $root.'/sitemap_index.xml',
            $root.'/feed.xml', $root.'/rss.xml', $root.'/atom.xml',
            $root.'/rss/', $root.'/feed/', $root.'/blog/feed/',
            $root.'/?feed=rss2', $root.'/wp-json/', $root.'/wp-json/wp/v2/posts',
        ];
    }

    private function discoverSiteIndex() {
        $p = parse_url($this->baseUrl);
        $root = ($p['scheme'] ?? 'http') . '://' . ($p['host'] ?? '');
        $patterns = [];
        foreach (['page','post','article','news','blog','category','tag','archive','product','item'] as $sec) {
            for ($i = 1; $i <= 5; $i++) $patterns[] = $root.'/'.$sec.'/'.$i.'/';
        }
        foreach (['/wp-content/','/wp-includes/','/uploads/','/assets/','/static/','/public/','/dist/','/build/','/js/','/css/','/images/','/img/','/media/','/files/','/api/','/api/v1/','/graphql'] as $ep) {
            $patterns[] = $root . $ep;
        }
        return $patterns;
    }

    private function findUnvisitedLinks() {
        $dir = $this->saveDir;
        if (!is_dir($dir)) return [];
        $unvisited = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $f) {
            $ext = strtolower($f->getExtension());
            if (!in_array($ext, ['html','htm','php','xml'])) continue;
            $body = @file_get_contents($f->getPathname());
            if (!$body) continue;
            foreach ($this->extractLinks($this->baseUrl, $body) as $l) {
                $lk = md5($l);
                if (!isset($this->visited[$lk]) && !isset($this->queued[$lk]) && $this->sameSite($l)) {
                    $this->queued[$lk] = true;
                    $unvisited[] = $l;
                }
            }
        }
        return array_slice($unvisited, 0, 500);
    }

    private function buildDiscoveryPaths() {
        $p = parse_url($this->baseUrl);
        $root = ($p['scheme'] ?? 'http') . '://' . ($p['host'] ?? '');
        $paths = [];
        foreach (unserialize(HIDDEN_PATHS) as $hp) $paths[] = $root . '/' . ltrim($hp, '/');
        return $paths;
    }

    private function buildAdminPaths() {
        $p = parse_url($this->baseUrl);
        $root = ($p['scheme'] ?? 'http') . '://' . ($p['host'] ?? '');
        $paths = [];
        foreach (unserialize(ADMIN_PATHS) as $ap) $paths[] = $root . '/' . ltrim($ap, '/');
        return $paths;
    }

    private function buildDBPaths() {
        $p = parse_url($this->baseUrl);
        $root = ($p['scheme'] ?? 'http') . '://' . ($p['host'] ?? '');
        $paths = [];
        $exts = unserialize(DATABASE_EXTS);
        $names = ['db','database','backup','dump','sql','data','site','wp','admin'];
        foreach ($names as $n) foreach ($exts as $e) $paths[] = $root . '/' . $n . $e;
        foreach (['db','database','backup'] as $n)
            foreach (['db/','backup/','database/'] as $d)
                foreach (['.sql','.db','.sqlite','.sql.gz','.zip','.tar.gz','.7z','.bak'] as $e)
                    $paths[] = $root . '/' . $d . $n . $e;
        return $paths;
    }

    private function extractLinks($pageUrl, $html) {
        $links = [];
        $base = $pageUrl;
        if (preg_match('/<base\s+[^>]*href=["\']([^"\']+)["\']/i', $html, $m)) $base = $m[1];

        foreach (['/(?:src|href|action|data|poster|ping|formaction|background)=["\']([^"\']+)["\']/i',
            '/data-(?:src|href|url|original|lazy-src|lazy|srcset|background|bg|image|img|video|iframe|content|file|path|load|include|asset|resource|api|endpoint)=["\']([^"\']+)["\']/i',
            '/url\(["\']?([^"\')\s]+)["\']?\)/i',
            '/srcset=["\']([^"\']+)["\']/i',
        ] as $pat) {
            if (preg_match_all($pat, $html, $m)) {
                foreach ($m[1] as $v) {
                    if (str_contains($v, ',')) {
                        foreach (explode(',', $v) as $part) {
                            $u = trim(preg_split('/\s+/', trim($part))[0] ?? '');
                            if ($u) $this->addUrl($links, $base, $u);
                        }
                    } else {
                        $this->addUrl($links, $base, $v);
                    }
                }
            }
        }

        // Meta refresh
        if (preg_match_all('/<meta[^>]*http-equiv=["\']refresh["\'][^>]*content=["\'][^"\']*url=\s*([^"\']+)["\']/i', $html, $m)
            || preg_match_all('/<meta[^>]*content=["\'][^"\']*url=\s*([^"\']+)["\'][^>]*http-equiv=["\']refresh["\']/i', $html, $m)) {
            foreach ($m[1] as $v) { $this->addUrl($links, $base, trim($v)); }
        }

        // Meta og/twitter
        if (preg_match_all('/<meta[^>]*(?:property|name)=["\'](?:og|twitter|article|video|audio|image):([^"\']+)["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m)
            || preg_match_all('/<meta[^>]*content=["\']([^"\']+)["\'][^>]*(?:property|name)=["\'](?:og|twitter|article|video|audio|image):([^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $v) { $this->addUrl($links, $base, $v); }
        }

        // Iframe srcdoc
        if (preg_match_all('/<iframe[^>]*srcdoc=["\']([^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $srcdoc) {
                foreach ($this->extractLinks($pageUrl, html_entity_decode($srcdoc, ENT_QUOTES)) as $l) {
                    $links[] = $l;
                }
            }
        }

        // JSON-LD scripts
        if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $m)) {
            foreach ($m[1] as $json) {
                $d = @json_decode($json, true);
                if ($d) array_walk_recursive($d, function($v) use (&$links, $base) {
                    if (is_string($v) && preg_match('/^https?:\/\//i', $v) && strpos($v, 'data:') !== 0) $links[] = $v;
                });
            }
        }

        // Sitemap links from robots.txt
        if (preg_match_all('/Sitemap:\s*(https?:\/\/[^\s]+)/i', $html, $m)) {
            foreach ($m[1] as $v) { $links[] = trim($v); }
        }

        return array_unique($links);
    }

    private function addUrl(&$links, $base, $url) {
        $url = trim($url);
        if (!$url) return;
        if ($url[0] === '#' || $url[0] === '?') return;
        if (preg_match('/^(data|mailto|javascript|tel|blob|facetime|sms|fax):/i', $url)) return;
        $abs = $this->absUrl($base, $url);
        if ($abs) $links[] = $abs;
    }

    private function extractCSS($cssUrl, $css) {
        $res = [];
        if (preg_match_all('/url\(["\']?([^"\')\s]+)["\']?\)/i', $css, $m)) {
            foreach ($m[1] as $v) {
                $v = trim($v);
                if (!$v || strpos($v, 'data:') === 0) continue;
                $abs = $this->absUrl($cssUrl, $v);
                if ($abs) $res[] = $abs;
            }
        }
        if (preg_match_all('/@import\s+["\']?([^"\';) ]+)["\']?\s*;/i', $css, $m)
            || preg_match_all('/@import\s+url\(["\']?([^"\')\s]+)["\']?\)/i', $css, $m)) {
            foreach ($m[1] as $v) {
                $abs = $this->absUrl($cssUrl, trim($v));
                if ($abs) $res[] = $abs;
            }
        }
        return array_unique($res);
    }

    private function extractJS($jsUrl, $js) {
        $res = [];
        $patterns = [
            '/require\s*\(["\']([^"\']+)["\']\)/i',
            '/import\s+["\']([^"\']+)["\']/i',
            '/import\s*\(\s*["\']([^"\']+)["\']\s*\)/i',
            '/fetch\s*\(\s*["\']([^"\']+)["\']/i',
            '/\$\.(?:ajax|get|post|getJSON)\s*\(\s*["\']([^"\']+)["\']/i',
            '/axios\.(?:get|post|put|patch|delete)\s*\(\s*["\']([^"\']+)["\']/i',
            '/\.open\s*\(\s*["\'][A-Z]+["\']\s*,\s*["\']([^"\']+)["\']/i',
            '/new\s+URL\s*\(\s*["\']([^"\']+)["\']/i',
            '/window\.(?:location|open)\s*[=\(]\s*["\']([^"\']+)["\']/i',
            '/url\s*:\s*["\']([^"\']+)["\']/i',
        ];
        foreach ($patterns as $p) {
            if (preg_match_all($p, $js, $m)) {
                foreach ($m[1] as $v) {
                    $abs = $this->absUrl($jsUrl, trim($v));
                    if ($abs) $res[] = $abs;
                }
            }
        }
        if (preg_match_all('/["\']((?:https?:)?\/\/[^"\']+\.(?:js|css|json|mjs|cjs|ts|jsx|tsx|vue|svelte|wasm))["\']/i', $js, $m)) {
            foreach ($m[1] as $v) {
                $abs = $this->absUrl($jsUrl, $v);
                if ($abs) $res[] = $abs;
            }
        }
        return array_unique($res);
    }

    private function extractJSON($jsonUrl, $json) {
        $res = [];
        $data = @json_decode($json, true);
        if (!$data) return $res;
        array_walk_recursive($data, function($v) use (&$res, $jsonUrl) {
            if (is_string($v) && preg_match('/^https?:\/\//i', $v)) {
                $abs = $this->absUrl($jsonUrl, $v);
                if ($abs) $res[] = $abs;
            }
        });
        return array_unique($res);
    }

    private function extractXML($xmlUrl, $xml) {
        $res = [];
        foreach (['/<link[^>]*href=["\']([^"\']+)["\']/i',
            '/<loc[^>]*>(.*?)<\/loc>/is', '/<url[^>]*>(.*?)<\/url>/is',
            '/<enclosure[^>]*url=["\']([^"\']+)["\']/i',
            '/<media:content[^>]*url=["\']([^"\']+)["\']/i',
            '/<image:loc[^>]*>(.*?)<\/image:loc>/is',
            '/<atom:link[^>]*href=["\']([^"\']+)["\']/i',
        ] as $p) {
            if (preg_match_all($p, $xml, $m)) {
                foreach ($m[1] as $v) {
                    $abs = $this->absUrl($xmlUrl, trim($v));
                    if ($abs) $res[] = $abs;
                }
            }
        }
        return array_unique($res);
    }

    private function extractRawUrls($fromUrl, $body) {
        $res = [];
        if (preg_match_all('/https?:\/\/[^"\'<>\s\[\](){}|;]+/i', $body, $m)) {
            foreach ($m[0] as $v) {
                $v = rtrim($v, '.!,:;?)]}>');
                if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg|ico|css|js|json|xml|html|php|pdf|zip|tar|gz|mp4|webm|mp3|woff2?|ttf|otf|eot|mjs|cjs|ts|tsx|jsx|avif|heic|heif)$/i', $v)) {
                    $abs = $this->absUrl($fromUrl, $v);
                    if ($abs && $this->sameSite($v)) $res[] = $abs;
                }
            }
        }
        return array_unique($res);
    }

    private function absUrl($base, $rel) {
        $rel = trim($rel);
        if (preg_match('/^https?:\/\//i', $rel)) return $rel;
        if (strpos($rel, '//') === 0) {
            $p = parse_url($base);
            return ($p['scheme'] ?? 'http') . ':' . $rel;
        }
        if (strlen($rel) > 0 && $rel[0] === '/') {
            $p = parse_url($base);
            return ($p['scheme'] ?? 'http') . '://' . ($p['host'] ?? '') . $rel;
        }
        $p = parse_url($base);
        $scheme = ($p['scheme'] ?? 'http') . '://';
        $host = $p['host'] ?? '';
        $path = isset($p['path']) && $p['path'] !== '' ? $p['path'] : '/';
        $dir = dirname($path);
        if ($dir === '\\' || $dir === '.') $dir = '/';
        if (substr($dir, -1) !== '/') $dir .= '/';
        $combined = $dir . $rel;
        $parts = explode('/', $combined);
        $r = [];
        foreach ($parts as $pt) {
            if ($pt === '..') array_pop($r);
            elseif ($pt !== '.' && $pt !== '') $r[] = $pt;
        }
        return $scheme . $host . '/' . implode('/', $r);
    }

    private function detectType($url, $body) {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '/', PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp','svg','ico','bmp','avif','tiff','heic','heif'])) return 'image';
        if ($ext === 'css') return 'css';
        if (in_array($ext, ['js','mjs','cjs','jsx','ts','tsx'])) return 'js';
        if (in_array($ext, ['woff','woff2','ttf','otf','eot','svgz'])) return 'font';
        if (in_array($ext, ['html','htm','php','asp','aspx','jsp','shtml','phtml','cfm'])) return 'html';
        if (in_array($ext, ['json'])) return 'json';
        if (in_array($ext, ['xml','rss','atom','rdf','kml','wsdl','xsd','xsl'])) return 'xml';
        if (in_array($ext, ['txt','csv','pdf','doc','docx','xls','xlsx','ppt','pptx'])) return 'doc';
        if (preg_match('/^\s*</', $body)) return 'html';
        if (preg_match('/^\s*{/', $body)) return 'json';
        if (preg_match('/^\s*<\?xml/', $body)) return 'xml';
        return 'other';
    }

    private function makePath($url) {
        $p = parse_url($url);
        $host = preg_replace('/[^a-zA-Z0-9.\-]/', '_', $p['host'] ?? 'unknown');
        $path = $p['path'] ?? '/';
        $query = isset($p['query']) ? '_' . md5($p['query']) : '';
        if ($path === '/' || $path === '') $path = '/index' . $query . '.html';
        if (substr($path, -1) === '/') $path .= 'index' . $query . '.html';
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        if (!$ext) $path .= $query . '.html';
        elseif ($query) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            $path = dirname($path) . '/' . $name . $query . '.' . $ext;
        }
        $clean = preg_replace('/[^a-zA-Z0-9_\-.\/]/', '_', ltrim($path, '/'));
        return $host . '/' . $clean;
    }

    private function sameSite($url) {
        $domain = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        if (!$domain) return false;
        return $domain === $this->baseDomain || str_ends_with($domain, '.' . $this->baseDomain)
            || str_ends_with($this->baseDomain, '.' . $domain);
    }

    private function getProgressData() {
        $pct = $this->maxFiles > 0 ? min(round(($this->totalFiles / $this->maxFiles) * 100, 1), 99.9) : 0;
        $last = $this->totalFiles > 0 ? $this->downloaded[$this->totalFiles - 1]['url'] : '';
        $found = ['hidden' => 0, 'admin' => 0, 'db' => 0];
        foreach (array_slice($this->downloaded, -30) as $f) {
            $u = $f['url'];
            foreach (unserialize(HIDDEN_PATHS) as $h) { if (str_contains($u, $h)) $found['hidden']++; }
            foreach (unserialize(ADMIN_PATHS) as $a) { if (str_contains($u, $a)) $found['admin']++; }
            foreach (unserialize(DATABASE_EXTS) as $d) { if (str_contains($u, $d)) $found['db']++; }
        }
        return [
            'percent' => $pct, 'files' => $this->totalFiles,
            'size' => formatSize($this->totalSize), 'size_bytes' => $this->totalSize,
            'errors' => count($this->realErrors),
            'current' => mb_strlen($last) > 80 ? mb_substr($last, 0, 40) . '...' . mb_substr($last, -35) : $last,
            'hidden_found' => $found['hidden'], 'admin_found' => $found['admin'], 'db_found' => $found['db'],
            'secrets_found' => count($this->secretMatches),
        ];
    }

    private function emit($type, $data) {
        if (!$this->progressCb) return;
        $data['type'] = $type;
        call_user_func($this->progressCb, $data);
    }
}
