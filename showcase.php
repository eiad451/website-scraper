<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Website Scraper Pro - Full Source Code by eiad451</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#08080f;--card:rgba(255,255,255,.02);--border:rgba(255,255,255,.05);--text:#e0e0e0;--text2:rgba(255,255,255,.45);--cyan:#00d4ff;--blue:#3b82f6;--purple:#8b5cf6;--green:#10b981;--grad:linear-gradient(135deg,#00d4ff,#3b82f6,#8b5cf6);--radius:14px;--mono:'SF Mono','Fira Code','JetBrains Mono',monospace}
body{font-family:system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--text);line-height:1.5}
.bg{position:fixed;inset:0;pointer-events:none;z-index:0;overflow:hidden}
.bg::before{content:'';position:absolute;width:600px;height:600px;background:rgba(0,212,255,.06);border-radius:50%;filter:blur(150px);top:-200px;right:-200px;animation:f 25s infinite alternate}
.bg::after{content:'';position:absolute;width:500px;height:500px;background:rgba(139,92,246,.05);border-radius:50%;filter:blur(150px);bottom:-150px;left:-150px;animation:f 25s -8s infinite alternate}
@keyframes f{0%{transform:translate(0,0)}100%{transform:translate(40px,-40px)}}
.container{max-width:1100px;margin:0 auto;padding:20px;position:relative;z-index:1}
.header{text-align:center;padding:50px 30px;background:var(--card);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:20px;backdrop-filter:blur(20px)}
.header h1{font-size:2rem;font-weight:800;background:var(--grad);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.header .sub{color:var(--text2);font-size:.9rem;margin-top:6px}
.badge{display:inline-flex;align-items:center;gap:8px;margin-top:12px;padding:6px 16px;background:rgba(0,212,255,.06);border:1px solid rgba(0,212,255,.12);border-radius:100px;font-size:.8rem;color:var(--cyan)}
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:14px;backdrop-filter:blur(20px);overflow:hidden}
.card-h{display:flex;justify-content:space-between;align-items:center;padding:14px 20px;cursor:pointer;transition:.2s;user-select:none}
.card-h:hover{background:rgba(255,255,255,.02)}
.card-h .name{font-family:var(--mono);font-size:.85rem;color:var(--cyan);font-weight:600}
.card-h .meta{font-size:.72rem;color:var(--text2)}
.card-h .arrow{transition:transform .3s;color:var(--text2);font-size:.8rem}
.card-h.open .arrow{transform:rotate(180deg)}
.card-b{padding:0 20px 20px;display:none}
.card-b.open{display:block}
pre{background:#050510;border-radius:10px;padding:16px;overflow-x:auto;font-family:var(--mono);font-size:.72rem;line-height:1.55;color:rgba(255,255,255,.72);white-space:pre;tab-size:4;max-height:600px;overflow-y:auto;direction:ltr;text-align:left}
pre::-webkit-scrollbar{width:4px;height:4px}
pre::-webkit-scrollbar-track{background:transparent}
pre::-webkit-scrollbar-thumb{background:rgba(255,255,255,.06);border-radius:2px}
.stats-row{display:flex;gap:16px;justify-content:center;flex-wrap:wrap;margin:16px 0}
.stat{text-align:center;padding:14px 20px;background:rgba(255,255,255,.02);border:1px solid rgba(255,255,255,.04);border-radius:10px;min-width:90px}
.stat .n{font-size:1.5rem;font-weight:700;background:var(--grad);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.stat .l{font-size:.72rem;color:var(--text2);margin-top:2px}
.footer{text-align:center;padding:30px;color:var(--text2);font-size:.78rem;border-top:1px solid var(--border);margin-top:30px}
.footer a{color:var(--cyan);text-decoration:none}
.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 24px;background:var(--grad);color:#fff;border:none;border-radius:10px;font-size:.85rem;font-weight:600;cursor:pointer;text-decoration:none;transition:.3s;font-family:inherit}
.btn:hover{transform:translateY(-2px);box-shadow:0 6px 25px rgba(0,212,255,.2)}
.btn-outline{background:transparent;border:1px solid var(--border);color:var(--text2)}
.btn-outline:hover{background:rgba(255,255,255,.04);border-color:var(--cyan);color:var(--text)}
.btn-group{display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin-top:14px}
.fade{animation:fi .5s ease}
@keyframes fi{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
.search-box{margin-bottom:12px;display:flex;gap:8px}
.search-box input{flex:1;padding:10px 14px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:8px;color:var(--text);font-family:var(--mono);font-size:.82rem;outline:none;transition:.2s}
.search-box input:focus{border-color:rgba(0,212,255,.3)}
.search-box input::placeholder{color:var(--text2);font-family:system-ui,sans-serif}
.count-bar{padding:8px 20px;font-size:.78rem;color:var(--text2);border-bottom:1px solid var(--border);display:flex;justify-content:space-between}
</style>
</head>
<body>
<div class="bg"></div>
<div class="container">
<div class="header fade">
  <div style="font-size:2.5rem">📜</div>
  <h1>Website Scraper Pro</h1>
  <p class="sub">الكود المصدري الكامل — جميع الملفات بدون أي نقصان</p>
  <div class="badge"><span>© 2026 eiad451</span></div>
  <div class="stats-row">
    <?php
    $files = [
      'index.html','index.php','style.css','script.js','config.php','db.php',
      'functions.php','scraper.php','api.php','login.php','register.php',
      'admin.php','telegram_scraper.php','apk_analyzer.php','bot_scraper.php',
      'bot_scraper.py','cloudscraper_helper.py','server.sh','start.php','stop.php',
      'download.php','_test.php'
    ];
    $totalLines = 0; $totalSize = 0;
    foreach ($files as $f) {
      $path = __DIR__ . '/' . $f;
      if (file_exists($path)) {
        $c = file_get_contents($path);
        $totalLines += substr_count($c, "\n") + 1;
        $totalSize += strlen($c);
      }
    }
    ?>
    <div class="stat"><span class="n"><?=count($files)?></span><span class="l">ملف</span></div>
    <div class="stat"><span class="n"><?=number_format($totalLines)?></span><span class="l">سطر</span></div>
    <div class="stat"><span class="n"><?=number_format($totalSize)?></span><span class="l">بايت</span></div>
  </div>
  <div class="btn-group">
    <a class="btn" href="index.php">🚀 الدخول للمشروع</a>
    <a class="btn btn-outline" href="https://github.com/eiad451/website-scraper">🐙 GitHub</a>
  </div>
</div>

<div class="card fade">
  <div class="count-bar">
    <span>🔍 تصفية الملفات</span>
    <span id="visibleCount"><?=count($files)?> ملف</span>
  </div>
  <div style="padding:8px 20px 0">
    <div class="search-box">
      <input type="text" id="search" placeholder="ابحث عن ملف..." oninput="filterFiles()">
    </div>
  </div>
</div>

<?php
$extIcons = [
  'php' => '🐘', 'html' => '🌐', 'css' => '🎨', 'js' => '⚡',
  'py' => '🐍', 'sh' => '📟',
];
$extColors = [
  'php' => '#777bb3', 'html' => '#e34c26', 'css' => '#264de4',
  'js' => '#f7df1e', 'py' => '#3572a5', 'sh' => '#89e051',
];

foreach ($files as $fi):
  $path = __DIR__ . '/' . $fi;
  if (!file_exists($path)) continue;
  $content = file_get_contents($path);
  $ext = pathinfo($fi, PATHINFO_EXTENSION);
  $icon = $extIcons[$ext] ?? '📄';
  $color = $extColors[$ext] ?? '#666';
  $lines = substr_count($content, "\n") + 1;
  $size = strlen($content);
  $sizeFmt = $size < 1024 ? "$size B" : ($size < 1048576 ? round($size/1024,1).' KB' : round($size/1048576,1).' MB');
  $slug = preg_replace('/[^a-zA-Z0-9]/', '_', $fi);
?>
<div class="card file-card" data-name="<?=strtolower($fi)?>">
  <div class="card-h" onclick="toggle(this)">
    <div>
      <span class="name"><?=$icon?> <?=$fi?></span>
      <span class="meta" style="margin-right:10px"><?=$lines?> سطر — <?=$sizeFmt?></span>
    </div>
    <span class="arrow">▼</span>
  </div>
  <div class="card-b">
    <pre><code><?=htmlspecialchars($content)?></code></pre>
  </div>
</div>
<?php endforeach; ?>

<div class="footer">
  <p>© 2026 <strong>eiad451</strong> — Website Scraper Pro v4.0</p>
  <p style="margin-top:4px;font-size:.7rem;color:var(--text2)">جميع الحقوق محفوظة — جميع الملفات معروضة بكامل محتواها</p>
  <p style="margin-top:8px"><a href="https://github.com/eiad451/website-scraper">github.com/eiad451/website-scraper</a></p>
</div>
</div>

<script>
function toggle(el) {
  el.classList.toggle('open');
  var body = el.nextElementSibling;
  body.classList.toggle('open');
}
function filterFiles() {
  var q = document.getElementById('search').value.toLowerCase();
  var cards = document.querySelectorAll('.file-card');
  var vis = 0;
  cards.forEach(function(c) {
    var name = c.getAttribute('data-name');
    if (name.includes(q)) { c.style.display = ''; vis++; }
    else { c.style.display = 'none'; }
  });
  document.getElementById('visibleCount').textContent = vis + ' ملف';
}
// Open all on double-click header
document.addEventListener('dblclick', function(e) {
  var h = e.target.closest('.card-h');
  if (!h) return;
  var allBodies = document.querySelectorAll('.card-b');
  var allHeads = document.querySelectorAll('.card-h');
  var anyClosed = false;
  allBodies.forEach(function(b) { if (!b.classList.contains('open')) anyClosed = true; });
  allBodies.forEach(function(b) { b.classList.toggle('open', !anyClosed); });
  allHeads.forEach(function(h) { h.classList.toggle('open', !anyClosed); });
});
</script>
</body>
</html>