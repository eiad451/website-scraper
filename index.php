<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
initDB();

$user = null;
if (isLoggedIn()) $user = getCurrentUser();
$settings = getSettings();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title><?=e($settings['site_name'] ?? 'Website Scraper')?></title>
<link rel="stylesheet" href="style.css">
<style>
.scrape-tabs{display:flex;gap:4px;margin-bottom:10px;background:rgba(255,255,255,0.03);border-radius:10px;padding:4px}
.scrape-tab{flex:1;padding:8px 12px;border:none;background:transparent;color:var(--text-muted);font-size:0.82rem;border-radius:8px;cursor:pointer;transition:all 0.2s;font-family:inherit}
.scrape-tab.active{background:rgba(59,130,246,0.2);color:var(--accent-cyan);font-weight:600}
.scrape-tab:hover{background:rgba(255,255,255,0.05)}
</style>
</head>
<body>

<?php if (!($user && !empty($user['welcomed'])) && !isset($_COOKIE['welcomed'])): ?>
<div id="welcomeOverlay" class="welcome-overlay">
  <div class="welcome-video">
    <iframe id="welcomeFrame" src="<?=e(embedYoutubeUrl($settings['welcome_video_url'] ?? 'https://www.youtube.com/embed/pQqPuMe3PPw'))?>?autoplay=1&mute=1&playsinline=1&rel=0&enablejsapi=1" allow="autoplay; encrypted-media" allowfullscreen></iframe>
    <div id="welcomePlayBtn" class="welcome-play-overlay" onclick="event.stopPropagation();playWithSound()">
      <div class="welcome-play-icon">▶</div>
      <div class="welcome-play-text">اضغط لتشغيل الفيديو بالصوت</div>
    </div>
  </div>
  <a href="api.php?action=welcome_skip" class="welcome-skip">✕ تخطي</a>
</div>
<?php endif; ?>

<div class="bg-effects">
  <div class="bg-video-wrap">
    <iframe id="bgVideo" src="https://www.youtube.com/embed/pQqPuMe3PPw?autoplay=1&mute=1&loop=1&playlist=pQqPuMe3PPw&controls=0&showinfo=0&rel=0&iv_load_policy=3&modestbranding=1&playsinline=1&enablejsapi=1" allow="autoplay; encrypted-media" allowfullscreen style="pointer-events:none"></iframe>
  </div>
  <div class="bg-overlay"></div>
  <div class="bg-grid"></div>
</div>

<div class="container">
  <?php if (!$user): ?>
  <div style="text-align:center;padding:80px 20px">
    <div style="font-size:4rem;margin-bottom:16px">🕵️</div>
    <h1 style="margin-bottom:12px"><?=e($settings['site_name'] ?? 'Website Scraper')?></h1>
    <p style="color:var(--text-muted);margin-bottom:24px"><?=e($settings['site_description'] ?? 'اسحب أي موقع كامل')?></p>
    <a href="login.php" class="btn-primary" style="display:inline-block;width:auto;padding:14px 40px">🔑 تسجيل الدخول</a>
  </div>
  <?php else: ?>
  <div class="header">
    <div class="header-icon">🕵️</div>
    <h1><?=e($settings['site_name'])?></h1>
    <div class="subtitle"><?=e($settings['site_description'])?></div>
    <div class="badge"><?=e($user['username'])?> • <?=number_format($user['coins'])?> كوين • <span class="badge-dot"></span> متصل</div>
    <div class="header-actions">
      <a href="admin.php" class="btn btn-glass" style="display:<?=$user['is_admin']?'':'none'?>">⚙ الإدارة</a>
      <a href="api.php?action=logout" class="btn btn-glass" onclick="return confirm('تسجيل الخروج؟')" style="color:var(--accent-red);border-color:rgba(239,68,68,0.2)">🚪 خروج</a>
    </div>
  </div>

  <div class="features">
    <div class="feature"><div class="fi">⚡</div><div class="fl">HTML + CSS</div></div>
    <div class="feature"><div class="fi">⚡</div><div class="fl">JS + JSON</div></div>
    <div class="feature"><div class="fi">🖼️</div><div class="fl">صور + خطوط</div></div>
    <div class="feature"><div class="fi">🔍</div><div class="fl">Hidden + Admin</div></div>
    <div class="feature"><div class="fi">🗄️</div><div class="fl">قواعد بيانات</div></div>
    <div class="feature"><div class="fi">📡</div><div class="fl">API Endpoints</div></div>
    <div class="feature"><div class="fi">🛡️</div><div class="fl">WAF Bypass</div></div>
    <div class="feature"><div class="fi">📦</div><div class="fl">ZIP للتحميل</div></div>
  </div>

  <div class="scrape-tabs">
    <button class="scrape-tab active" data-mode="site" onclick="switchMode('site')">🌐 موقع</button>
    <button class="scrape-tab" data-mode="github" onclick="switchMode('github')">🐙 GitHub</button>
    <button class="scrape-tab" data-mode="telegram" onclick="switchMode('telegram')">✈️ تلغرام</button>
    <button class="scrape-tab" data-mode="apk" onclick="switchMode('apk')">📱 APK</button>
  </div>

  <div class="grid">
    <div>
      <div class="card">
        <div class="card-title"><span class="dot" style="background:var(--accent-cyan)"></span>🎯 إعدادات السحب</div>
        <form id="scrapeForm">
          <div id="siteMode">
            <div class="form-group">
              <label>🔗 رابط الموقع <span style="color:var(--accent-red)">*</span></label>
              <input type="url" id="targetUrl" placeholder="https://example.com" required dir="ltr">
            </div>
            <div class="form-group">
              <label>🧩 خيارات السحب</label>
              <div class="chip-group">
                <label class="chip active"><input type="checkbox" id="optHidden" checked> 📁 Hidden</label>
                <label class="chip active"><input type="checkbox" id="optAdmin" checked> 🔐 Admin</label>
                <label class="chip active"><input type="checkbox" id="optDB" checked> 🗄️ Database</label>
              </div>
            </div>
            <div class="input-row">
              <div class="form-group"><label>📏 العمق</label><input type="number" id="optDepth" value="<?=e($settings['max_depth'] ?? 20)?>" min="1" max="50"></div>
              <div class="form-group"><label>⚡ التزامن</label><select id="optConcurrent"><option value="10">10</option><option value="20">20</option><option value="30" selected>30</option><option value="50">50</option><option value="100">100</option></select></div>
            </div>
            <div class="price-notice">💰 تكلفة السحبة: <strong><?=e($settings['scrape_price'] ?? 30)?> كوين</strong> | رصيدك: <?=number_format($user['coins'])?> كوين</div>
          </div>
          <div id="githubMode" style="display:none">
            <div class="form-group">
              <label>🐙 يوزر GitHub <span style="color:var(--accent-red)">*</span></label>
              <input type="text" id="githubUsername" placeholder="username" dir="ltr">
            </div>
            <div style="font-size:0.8rem;color:var(--text-muted);margin-bottom:10px">هيسحب كل الريبوهات والملفات الخاصة بالمستخدم</div>
          </div>
          <div id="apkMode" style="display:none">
            <div class="form-group">
              <label>📱 ملف APK <span style="color:var(--accent-red)">*</span></label>
              <input type="file" id="apkFile" accept=".apk,.xapk,.apkm,.apks" class="file-upload" onchange="this.classList.toggle('has-file', this.files.length>0)">
            </div>
            <div style="font-size:0.8rem;color:var(--text-muted);margin-bottom:10px">
              🚀 يفك التجميع بالكامل، يستخرج النصوص والروابط، يكتشف الأسرار والثغرات، ويحلل بالذكاء الاصطناعي
            </div>
          </div>
          <div id="telegramMode" style="display:none">
            <div class="form-group">
              <label>🤖 Bot Token <span style="color:var(--accent-red)">*</span></label>
              <input type="text" id="tgToken" placeholder="123456:ABCdef..." dir="ltr">
            </div>
            <div class="form-group">
              <label>👤 يوزر/آيدي الشات (اختياري)</label>
              <input type="text" id="tgChat" placeholder="@channel_username or chat_id" dir="ltr">
            </div>
            <div class="form-group" style="margin-top:8px">
              <label>🧩 طريقة السحب</label>
              <select id="tgMethod" style="width:100%">
                <option value="bot">Bot API (بالتوكن)</option>
                <option value="public">t.me/s/ (بدون توكن)</option>
              </select>
            </div>
            <div id="tgPublicFields" style="display:none">
              <div class="form-group">
                <label>🔗 رابط القناة العامة</label>
                <input type="text" id="tgPublicUrl" placeholder="https://t.me/s/username" dir="ltr">
              </div>
            </div>
            <div style="font-size:0.8rem;color:var(--text-muted);margin-bottom:10px">🚀 يسحب كل الرسائل والملفات من البوت أو القناة</div>
          </div>
          <button type="submit" class="btn-primary" id="startBtn">🚀 بدء السحب الأسطوري</button>
        </form>
      </div>

      <div class="card">
        <div class="card-title"><span class="dot" style="background:var(--accent-orange)"></span>💳 شحن الرصيد</div>
        <p style="font-size:0.8rem;color:var(--text-secondary);margin-bottom:12px">حوّل المبلغ على أحد الرقمين وادخل البيانات:</p>
        <div style="background:rgba(255,255,255,0.03);border-radius:8px;padding:12px;margin-bottom:12px">
          <div style="font-size:0.8rem;color:var(--text-muted)">📱 <?=e($settings['payment_number_1'] ?? '01000000000')?></div>
          <div style="font-size:0.8rem;color:var(--text-muted);margin-top:4px">📱 <?=e($settings['payment_number_2'] ?? '01000000001')?></div>
          <div style="font-size:0.7rem;color:var(--text-muted);margin-top:6px">⚡ المبلغ ينقسم تلقائياً بين المسؤولين</div>
        </div>
        <form id="paymentForm">
          <div class="form-group"><label>المبلغ (جنيه)</label><input type="number" id="payAmount" required min="10" placeholder="20"></div>
          <div class="form-group"><label>رقمك للتواصل</label><input type="text" id="payPhone" required placeholder="01XXXXXXXXX"></div>
          <div class="form-group"><label>حولت على رقم</label><select id="payPhoneTo"><option value="<?=e($settings['payment_number_1'] ?? '')?>"><?=e($settings['payment_number_1'] ?? '')?></option><option value="<?=e($settings['payment_number_2'] ?? '')?>"><?=e($settings['payment_number_2'] ?? '')?></option></select></div>
          <div class="form-group"><label>رقم العملية</label><input type="text" id="payTxId" required placeholder="Transaction ID"></div>
          <button type="submit" class="btn-primary" style="background:var(--gradient-2)" id="payBtn">💳 إرسال طلب الشحن</button>
        </form>
        <div id="payResult" style="display:none;margin-top:10px;padding:10px;border-radius:8px;text-align:center;font-size:0.85rem"></div>
      </div>
    </div>

    <div>
      <div id="progressSection" class="card hidden">
        <div class="card-title">
          <span class="dot" style="background:var(--accent-green)"></span>
          <span id="statusText" style="font-size:0.8rem;color:var(--text-muted)">⏳ جاري السحب...</span>
        </div>
        <div class="progress-box">
          <div class="progress-header"><span class="label">التقدم</span><span class="value" id="progressPct">0%</span></div>
          <div class="progress-bar"><div class="fill" id="progressFill" style="width:0%"></div></div>
        </div>
        <div class="stats-row">
          <div class="stats-cell"><span class="num cyan" id="statFiles">0</span><span class="lbl">ملف</span></div>
          <div class="stats-cell"><span class="num green" id="statSize">0 B</span><span class="lbl">الحجم</span></div>
          <div class="stats-cell"><span class="num orange" id="statErrors">0</span><span class="lbl">أخطاء</span></div>
          <div class="stats-cell"><span class="num purple" id="statFound">0</span><span class="lbl">مكتشف</span></div>
          <div class="stats-cell"><span class="num" id="statSecrets" style="color:var(--accent-red)">0</span><span class="lbl">أسرار</span></div>
        </div>
        <div class="cur-file" id="currentFile">⏳ جاري الاتصال...</div>
        <div class="log-box" id="logBox"></div>
        <div style="display:flex;gap:8px;margin-top:10px">
          <button class="btn-danger" id="stopBtn" style="flex:1" onclick="abortController?.abort();scraping=false;location.reload()">⏹ إيقاف</button>
        </div>
      </div>

      <div id="resultSection" class="card hidden">
        <div class="res-box">
          <h3>✅ تم سحب الموقع بنجاح!</h3>
          <div class="res-grid">
            <div class="res-cell"><span class="rl">الملفات</span><span class="rv" id="resFiles">0</span></div>
            <div class="res-cell"><span class="rl">الحجم</span><span class="rv" id="resSize">0</span></div>
            <div class="res-cell"><span class="rl">الوقت</span><span class="rv" id="resDur">0s</span></div>
            <div class="res-cell"><span class="rl">أخطاء حقيقية</span><span class="rv" id="resErr" style="color:var(--accent-red)">0</span></div>
            <div class="res-cell"><span class="rl">فحوصات (غير موجود)</span><span class="rv" id="resProbe" style="color:var(--text-muted)">0</span></div>
          <div class="res-cell"><span class="rl">🔑 أسرار مكتشفة</span><span class="rv" id="resSecrets" style="color:var(--accent-red)">0</span></div>
          </div>
          <div class="dl-actions">
            <a class="dl-primary" id="dlBtn" href="#">📥 تحميل ZIP</a>
            <button class="dl-secondary" onclick="location.reload()">🔄 سحب جديد</button>
          </div>
          <div id="res-section"></div>
        </div>
      </div>

      <div id="emptySection" class="card">
        <div class="empty-state">
          <div class="icon">🕵️</div>
          <h2>أنتظر منك الرابط!</h2>
          <p>أدخل رابط الموقع أو ارفع APK وابدأ السحب</p>
        </div>
      </div>
    </div>
  </div>

  <div class="footer">
    Website Scraper Pro v4.0 — <?=e($settings['site_name'] ?? '')?> — الملفات تصل للمستخدم مباشرة
  </div>
</div>

<script>
let scraping = false;
let abortController = null;
let currentMode = 'site';

function switchMode(mode) {
  currentMode = mode;
  document.querySelectorAll('.scrape-tab').forEach(t => t.classList.remove('active'));
  document.querySelector(`.scrape-tab[data-mode="${mode}"]`).classList.add('active');
  document.getElementById('siteMode').style.display = mode === 'site' ? '' : 'none';
  document.getElementById('githubMode').style.display = mode === 'github' ? '' : 'none';
  document.getElementById('telegramMode').style.display = mode === 'telegram' ? '' : 'none';
  document.getElementById('apkMode').style.display = mode === 'apk' ? '' : 'none';
}

function tgMethodChange() {
  const m = document.getElementById('tgMethod').value;
  document.getElementById('tgToken').disabled = m !== 'bot';
  document.getElementById('tgChat').disabled = m !== 'bot';
  document.getElementById('tgPublicFields').style.display = m === 'public' ? '' : 'none';
}
document.getElementById('tgMethod')?.addEventListener('change', tgMethodChange);

function log(msg, cls) {
  const box = document.getElementById('logBox');
  const d = document.createElement('div');
  d.className = 'log-' + (cls || 'info');
  d.textContent = msg;
  box.appendChild(d);
  box.scrollTop = box.scrollHeight;
}

document.getElementById('scrapeForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  if (scraping) return;
  scraping = true;
  abortController = new AbortController();

  document.getElementById('emptySection').classList.add('hidden');
  document.getElementById('resultSection').classList.add('hidden');
  document.getElementById('progressSection').classList.remove('hidden');
  document.getElementById('logBox').innerHTML = '';
  document.getElementById('progressFill').style.width = '0%';
  document.getElementById('progressPct').textContent = '0%';
  document.getElementById('statFiles').textContent = '0';
  document.getElementById('statSize').textContent = '0 B';
  document.getElementById('statErrors').textContent = '0';
  document.getElementById('startBtn').disabled = true;
  document.getElementById('startBtn').innerHTML = '⏳ جاري...';

  let url = '';
  let useFormData = false;
  let formDataBody = null;
  if (currentMode === 'site') {
    url = 'api.php?action=scrape_sse&url=' + encodeURIComponent(document.getElementById('targetUrl').value);
    url += '&depth=' + document.getElementById('optDepth').value;
    url += '&threads=' + document.getElementById('optConcurrent').value;
    url += '&scan_hidden=' + (document.getElementById('optHidden').checked ? '1' : '0');
    url += '&scan_admin=' + (document.getElementById('optAdmin').checked ? '1' : '0');
    url += '&scan_db=' + (document.getElementById('optDB').checked ? '1' : '0');
  } else if (currentMode === 'telegram') {
    const method = document.getElementById('tgMethod').value;
    if (method === 'bot') {
      url = 'api.php?action=telegram_sse&token=' + encodeURIComponent(document.getElementById('tgToken').value);
      const chat = document.getElementById('tgChat').value.trim();
      if (chat) url += '&chat=' + encodeURIComponent(chat);
    } else {
      const u = document.getElementById('tgPublicUrl').value.trim().replace('https://t.me/','').replace('@','');
      url = 'api.php?action=telegram_public_sse&username=' + encodeURIComponent(u) + '&pages=5';
    }
  } else if (currentMode === 'apk') {
    const fileInput = document.getElementById('apkFile');
    if (!fileInput.files || !fileInput.files[0]) { log('❌ الرجاء اختيار ملف APK', 'err'); scraping=false; document.getElementById('startBtn').disabled = false; document.getElementById('startBtn').innerHTML = '🚀 بدء السحب الأسطوري'; return; }
    url = 'api.php?action=apk_sse';
    useFormData = true;
    formDataBody = new FormData();
    formDataBody.append('apk_file', fileInput.files[0]);
  }

  try {
    const fetchOpts = { signal: abortController.signal };
    if (useFormData && formDataBody) {
      fetchOpts.method = 'POST';
      fetchOpts.body = formDataBody;
    }
    const r = await fetch(url, fetchOpts);

    const reader = r.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      const lines = buffer.split('\n');
      buffer = lines.pop() || '';
      for (const line of lines) {
        if (line.startsWith('data: ')) {
          try {
            const data = JSON.parse(line.substring(6));
            handleSSE(data);
          } catch(e) {}
        }
      }
    }
  } catch(e) {
    if (e.name !== 'AbortError') log('❌ خطأ في الاتصال: ' + e.message, 'err');
  }

  scraping = false;
  document.getElementById('startBtn').disabled = false;
  document.getElementById('startBtn').innerHTML = '🚀 بدء السحب الأسطوري';
});

function handleSSE(data) {
  switch(data.type) {
    case 'start':
      log('🚀 ' + (data.message || ''), 'ok');
      break;
    case 'progress':
      document.getElementById('progressFill').style.width = Math.min(data.percent||0,100) + '%';
      document.getElementById('progressPct').textContent = Math.round(data.percent||0) + '%';
      document.getElementById('statFiles').textContent = data.files||0;
      document.getElementById('statSize').textContent = data.size||'0 B';
      document.getElementById('statErrors').textContent = data.errors||0;
      document.getElementById('statFound').textContent = (data.admin_found||0)+(data.hidden_found||0)+(data.db_found||0);
      document.getElementById('statSecrets').textContent = data.secrets_found||0;
      if (data.current && data.current.length > 3) {
        document.getElementById('currentFile').textContent = '📄 ' + data.current;
        log('📄 ' + data.current, 'file');
      }
      break;
    case 'complete':
      document.getElementById('progressFill').style.width = '100%';
      document.getElementById('progressPct').textContent = '100%';
      document.getElementById('progressSection').classList.add('hidden');
      document.getElementById('resultSection').classList.remove('hidden');
      const r = data.result || data;
      document.getElementById('resFiles').textContent = r.total_files||0;
      document.getElementById('resSize').textContent = r.total_size_fmt||'0 B';
      document.getElementById('resDur').textContent = (r.duration||0) + 's';
      document.getElementById('resErr').textContent = (r.real_errors||[]).length||0;
      document.getElementById('resProbe').textContent = (r.probe_errors||[]).length||0;
      document.getElementById('resSecrets').textContent = r.secrets_found||0;
      if (r.secrets_found > 0) {
        let secHtml = '<div style="margin-top:12px;padding:10px;background:rgba(239,68,68,0.08);border-radius:8px;text-align:right"><div style="font-size:0.8rem;color:var(--accent-red);font-weight:700;margin-bottom:6px">🔑 كلمات سرية مكتشفة:</div>';
        (r.secret_matches||[]).forEach(s => {
          secHtml += '<div style="font-size:0.72rem;padding:3px 0;border-bottom:1px solid rgba(255,255,255,0.04)"><span style="color:var(--accent-red)">' + e(s.keyword) + '</span> ← <span style="color:var(--text-muted);font-size:0.65rem">' + e(s.url) + '</span></div>';
        });
        secHtml += '</div>';
        document.getElementById('res-section').innerHTML = secHtml + (document.getElementById('res-section').innerHTML||'');
      }
      if (r.dir) {
        document.getElementById('dlBtn').href = 'api.php?action=download_zip&dir=' + r.dir;
        document.getElementById('res-section').innerHTML = '<div style="margin-top:10px"><a class="dl-primary" href="api.php?action=view_file&dir=' + r.dir + '&file=report/report.html" target="_blank">📊 عرض التقرير الكامل</a></div>';
      }
      log('✅ تم ' + (r.dir && r.dir.startsWith('apk_') ? 'تحليل APK' : 'السحب') + ' بنجاح! ' + r.total_files + ' ملف', 'ok');
      log('📦 الحجم: ' + r.total_size_fmt, 'info');
      break;
    case 'error':
      log('❌ ' + (data.message||''), 'err');
      document.getElementById('progressSection').classList.add('hidden');
      document.getElementById('emptySection').classList.remove('hidden');
      document.getElementById('emptySection').querySelector('h2').textContent = '❌ ' + (data.message||'حدث خطأ');
      scraping = false;
      document.getElementById('startBtn').disabled = false;
      document.getElementById('startBtn').innerHTML = '🚀 بدء السحب الأسطوري';
      break;
  }
}

document.getElementById('paymentForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  document.getElementById('payBtn').disabled = true;
  document.getElementById('payBtn').innerHTML = '⏳ جاري...';
  const formData = new URLSearchParams();
  formData.append('action', 'payment_request');
  formData.append('amount', document.getElementById('payAmount').value);
  formData.append('phone', document.getElementById('payPhone').value);
  formData.append('phone_paid_to', document.getElementById('payPhoneTo').value);
  formData.append('transaction_id', document.getElementById('payTxId').value);
  try {
    const r = await fetch('api.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:formData.toString()});
    const d = await r.json();
    const el = document.getElementById('payResult');
    el.style.display = 'block';
    if (d.success) {
      el.style.background = 'rgba(16,185,129,0.1)';
      el.style.border = '1px solid rgba(16,185,129,0.3)';
      el.style.color = 'var(--accent-green)';
      el.textContent = '✅ ' + d.message;
      document.getElementById('payAmount').value = '';
      document.getElementById('payTxId').value = '';
    } else {
      el.style.background = 'rgba(239,68,68,0.1)';
      el.style.border = '1px solid rgba(239,68,68,0.3)';
      el.style.color = 'var(--accent-red)';
      el.textContent = '❌ ' + (d.error||'حدث خطأ');
    }
  } catch(e) {
    document.getElementById('payResult').style.display = 'block';
    document.getElementById('payResult').textContent = '❌ خطأ في الاتصال';
  }
  document.getElementById('payBtn').disabled = false;
  document.getElementById('payBtn').innerHTML = '💳 إرسال طلب الشحن';
});

function playWithSound() {
  const iframe = document.getElementById('welcomeFrame');
  iframe.contentWindow.postMessage('{"event":"command","func":"unMute","args":""}', '*');
  iframe.contentWindow.postMessage('{"event":"command","func":"setVolume","args":[100]}', '*');
  const bg = document.getElementById('bgVideo');
  if (bg) {
    bg.contentWindow.postMessage('{"event":"command","func":"unMute","args":""}', '*');
    bg.contentWindow.postMessage('{"event":"command","func":"setVolume","args":[50]}', '*');
  }
  document.getElementById('welcomePlayBtn').style.display = 'none';
  document.cookie = 'welcomed=1;path=/;max-age=' + (86400*365);
}

// Auto-unmute background video after user first interacts
(function() {
  const bgVideo = document.getElementById('bgVideo');
  if (!bgVideo) return;
  let unmuted = false;
  const tryUnmute = () => {
    if (unmuted) return;
    unmuted = true;
    bgVideo.contentWindow.postMessage('{"event":"command","func":"unMute","args":""}', '*');
    bgVideo.contentWindow.postMessage('{"event":"command","func":"setVolume","args":[50]}', '*');
    bgVideo.contentWindow.postMessage('{"event":"command","func":"playVideo","args":""}', '*');
    document.removeEventListener('click', tryUnmute);
    document.removeEventListener('touchstart', tryUnmute);
    document.removeEventListener('keydown', tryUnmute);
  };
  document.addEventListener('click', tryUnmute);
  document.addEventListener('touchstart', tryUnmute);
  document.addEventListener('keydown', tryUnmute);
  setTimeout(tryUnmute, 2000);
})();

function skipWelcome() {
  window.location.href = 'api.php?action=welcome_skip';
}
</script>

<?php endif; ?>
</body>
</html>
