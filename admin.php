<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
initDB();

if (!isLoggedIn()) { header('Location: login.php'); exit; }
$user = getCurrentUser();
if (!$user['is_admin']) { header('Location: index.php'); exit; }

$pdo = getDB();
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'settings') {
        foreach ($_POST as $k => $v) {
            if ($k === 'action') continue;
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
            $stmt->execute([$k, $v]);
        }
        $msg = '✅ تم حفظ الإعدادات';
    }
    if ($action === 'add_coins') {
        $uid = intval($_POST['user_id'] ?? 0);
        $coins = intval($_POST['coins'] ?? 0);
        if ($uid && $coins) {
            $stmt = $pdo->prepare("UPDATE users SET coins = coins + ? WHERE id = ?");
            $stmt->execute([$coins, $uid]);
            $msg = '✅ تم إضافة ' . $coins . ' كوين';
        }
    }
    if ($action === 'approve_payment') {
        $pid = intval($_POST['payment_id'] ?? 0);
        if ($pid) {
            $stmt = $pdo->prepare("SELECT * FROM payment_requests WHERE id = ? AND status = 'pending'");
            $stmt->execute([$pid]);
            $req = $stmt->fetch();
            if ($req) {
                $pdo->prepare("UPDATE payment_requests SET status = 'approved', admin_id = ? WHERE id = ?")->execute([$user['id'], $pid]);
                $pdo->prepare("UPDATE users SET coins = coins + ? WHERE id = ?")->execute([$req['coins'], $req['user_id']]);
                $msg = '✅ تم الموافقة على طلب الشحن';
            }
        }
    }
    if ($action === 'reject_payment') {
        $pid = intval($_POST['payment_id'] ?? 0);
        if ($pid) {
            $pdo->prepare("UPDATE payment_requests SET status = 'rejected', admin_id = ? WHERE id = ?")->execute([$user['id'], $pid]);
            $msg = '✅ تم رفض الطلب';
        }
    }
    if ($action === 'ban_user') {
        $uid = intval($_POST['user_id'] ?? 0);
        if ($uid) {
            $pdo->prepare("UPDATE users SET is_banned = 1 WHERE id = ?")->execute([$uid]);
            $msg = '✅ تم حظر المستخدم';
        }
    }
    if ($action === 'unban_user') {
        $uid = intval($_POST['user_id'] ?? 0);
        if ($uid) {
            $pdo->prepare("UPDATE users SET is_banned = 0 WHERE id = ?")->execute([$uid]);
            $msg = '✅ تم فك الحظر';
        }
    }
}

$settings = getSettings();
$users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();
$payments = $pdo->query("SELECT p.*, u.username FROM payment_requests p LEFT JOIN users u ON p.user_id = u.id ORDER BY p.created_at DESC LIMIT 50")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>لوحة الإدارة</title>
<link rel="stylesheet" href="style.css">
<style>
.admin-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px}
.admin-card{background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);border-radius:12px;padding:16px}
.admin-card h3{font-size:0.9rem;margin-bottom:12px;color:var(--accent-cyan)}
.admin-table{width:100%;border-collapse:collapse;font-size:0.78rem}
.admin-table th,.admin-table td{padding:8px 6px;text-align:center;border-bottom:1px solid rgba(255,255,255,0.05)}
.admin-table th{color:var(--text-muted);font-weight:500}
.admin-table tr:hover{background:rgba(255,255,255,0.02)}
.admin-table .badge{font-size:0.65rem;padding:2px 8px;border-radius:10px}
.badge-pending{background:rgba(251,191,36,0.15);color:#fbbf24}
.badge-approved{background:rgba(16,185,129,0.15);color:#10b981}
.badge-rejected{background:rgba(239,68,68,0.15);color:#ef4444}
.form-inline{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.form-inline input,.form-inline select{flex:1;min-width:80px}
.admin-msg{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.2);padding:10px 14px;border-radius:8px;font-size:0.85rem;margin-bottom:12px}
.sec-tabs{display:flex;gap:4px;margin-bottom:16px;background:rgba(255,255,255,0.03);border-radius:10px;padding:4px}
.sec-tab{padding:7px 16px;border:none;background:transparent;color:var(--text-muted);border-radius:8px;cursor:pointer;font-size:0.8rem;font-family:inherit;transition:all 0.2s}
.sec-tab.active{background:rgba(59,130,246,0.2);color:var(--accent-cyan);font-weight:600}
.sec-tab:hover{background:rgba(255,255,255,0.05)}
.sec{display:none}
.sec.active{display:block}
</style>
</head>
<body>
<div class="bg-effects">
  <div class="bg-grid"></div>
  <div class="bg-orb bg-orb-1"></div>
  <div class="bg-orb bg-orb-2"></div>
  <div class="bg-orb bg-orb-3"></div>
</div>
<div class="container">
  <div class="header" style="padding-bottom:8px">
    <div class="header-icon">⚙️</div>
    <h1>لوحة الإدارة</h1>
    <div class="subtitle">مرحباً <?=e($user['username'])?></div>
    <div class="header-actions">
      <a href="index.php" class="btn btn-glass">🏠 الرئيسية</a>
      <a href="api.php?action=logout" class="btn btn-glass" onclick="return confirm('تسجيل الخروج؟')" style="color:var(--accent-red);border-color:rgba(239,68,68,0.2)">🚪 خروج</a>
    </div>
  </div>

  <?php if (isset($msg)): ?><div class="admin-msg"><?=e($msg)?></div><?php endif; ?>

  <div class="sec-tabs">
    <button class="sec-tab active" data-sec="settings" onclick="switchSec('settings')">⚙️ الإعدادات</button>
    <button class="sec-tab" data-sec="payments" onclick="switchSec('payments')">💳 طلبات الشحن</button>
    <button class="sec-tab" data-sec="users" onclick="switchSec('users')">👥 المستخدمين</button>
    <button class="sec-tab" data-sec="sites" onclick="switchSec('sites')">🌐 المواقع</button>
  </div>

  <div id="sec-settings" class="sec active">
    <div class="admin-card">
      <h3>⚙️ إعدادات الموقع</h3>
      <form method="post" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <input type="hidden" name="action" value="settings">
        <div class="form-group"><label>اسم الموقع</label><input name="site_name" value="<?=e($settings['site_name'] ?? '')?>"></div>
        <div class="form-group"><label>الوصف</label><input name="site_description" value="<?=e($settings['site_description'] ?? '')?>"></div>
        <div class="form-group"><label>العمق الأقصى</label><input name="max_depth" value="<?=e($settings['max_depth'] ?? '20')?>"></div>
        <div class="form-group"><label>سعر السحبة (كوين)</label><input name="scrape_price" value="<?=e($settings['scrape_price'] ?? '30')?>"></div>
        <div class="form-group"><label>رقم الدفع 1</label><input name="payment_number_1" value="<?=e($settings['payment_number_1'] ?? '')?>"></div>
        <div class="form-group"><label>رقم الدفع 2</label><input name="payment_number_2" value="<?=e($settings['payment_number_2'] ?? '')?>"></div>
        <div class="form-group" style="grid-column:1/-1"><label>رابط فيديو الترحيب</label><input name="welcome_video_url" value="<?=e($settings['welcome_video_url'] ?? '')?>"></div>
        <div class="form-group" style="grid-column:1/-1"><label>🧠 مفتاح API للذكاء الاصطناعي (Gemini)</label><input name="ai_api_key" value="<?=e($settings['ai_api_key'] ?? '')?>" placeholder="AIza... أو أي مفتاح Gemini" dir="ltr"></div>
        <div class="form-group" style="grid-column:1/-1"><label>🔑 كلمات سرية للبحث (كلمة لكل سطر)</label><textarea name="secret_keywords" rows="4" style="font-family:monospace;font-size:0.8rem" dir="ltr"><?=e($settings['secret_keywords'] ?? '')?></textarea><div style="font-size:0.72rem;color:var(--text-muted);margin-top:4px">السكربت هيبحث عنهم تلقائياً في كل ملف بيتم سحبه</div></div>
        <button type="submit" class="btn-primary" style="grid-column:1/-1">💾 حفظ الإعدادات</button>
      </form>
    </div>
  </div>

  <div id="sec-payments" class="sec">
    <div class="admin-card">
      <h3>💳 طلبات الشحن (آخر 50)</h3>
      <?php if (empty($payments)): ?><p style="color:var(--text-muted);font-size:0.85rem">لا توجد طلبات</p><?php endif; ?>
      <table class="admin-table">
        <thead><tr><th>ID</th><th>المستخدم</th><th>المبلغ</th><th>كوين</th><th>رقم المحول</th><th>TxID</th><th>الحالة</th><th>إجراء</th></tr></thead>
        <tbody>
          <?php foreach ($payments as $p): ?>
          <tr>
            <td>#<?=$p['id']?></td>
            <td><?=e($p['username'] ?? '?')?></td>
            <td><?=e($p['amount'])?> جنيه</td>
            <td><?=e($p['coins'])?></td>
            <td><?=e($p['phone'])?></td>
            <td style="font-size:0.65rem;max-width:80px;overflow:hidden;text-overflow:ellipsis"><?=e($p['transaction_id'])?></td>
            <td><span class="badge badge-<?=$p['status']?>"><?=$p['status']?></span></td>
            <td>
              <?php if ($p['status'] === 'pending'): ?>
              <form method="post" class="form-inline" style="gap:4px">
                <input type="hidden" name="action" value="approve_payment">
                <input type="hidden" name="payment_id" value="<?=$p['id']?>">
                <button type="submit" style="padding:3px 10px;border:none;border-radius:6px;background:rgba(16,185,129,0.2);color:#10b981;cursor:pointer;font-size:0.7rem">✅</button>
              </form>
              <form method="post" class="form-inline" style="gap:4px">
                <input type="hidden" name="action" value="reject_payment">
                <input type="hidden" name="payment_id" value="<?=$p['id']?>">
                <button type="submit" style="padding:3px 10px;border:none;border-radius:6px;background:rgba(239,68,68,0.2);color:#ef4444;cursor:pointer;font-size:0.7rem">❌</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div id="sec-users" class="sec">
    <div class="admin-card">
      <h3>👥 المستخدمين</h3>
      <table class="admin-table">
        <thead><tr><th>ID</th><th>اسم المستخدم</th><th>البريد</th><th>كوين</th><th>حظر</th><th>أدمن</th><th>التسجيل</th><th>إجراء</th></tr></thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <tr>
            <td>#<?=$u['id']?></td>
            <td><?=e($u['username'])?></td>
            <td style="font-size:0.7rem"><?=e($u['email'] ?? '—')?></td>
            <td><?=number_format($u['coins'])?></td>
            <td><?=$u['is_banned'] ? '🔴' : '🟢'?></td>
            <td><?=$u['is_admin'] ? '👑' : '—'?></td>
            <td style="font-size:0.7rem"><?=e($u['created_at'] ?? '')?></td>
            <td>
              <form method="post" class="form-inline" style="gap:4px;flex-wrap:nowrap">
                <input type="hidden" name="user_id" value="<?=$u['id']?>">
                <?php if ($u['is_banned']): ?>
                <input type="hidden" name="action" value="unban_user">
                <button type="submit" style="padding:3px 8px;border:none;border-radius:6px;background:rgba(16,185,129,0.2);color:#10b981;cursor:pointer;font-size:0.7rem">فك حظر</button>
                <?php else: ?>
                <input type="hidden" name="action" value="ban_user">
                <button type="submit" style="padding:3px 8px;border:none;border-radius:6px;background:rgba(239,68,68,0.2);color:#ef4444;cursor:pointer;font-size:0.7rem" onclick="return confirm('حظر <?=e($u['username'])?>?')">حظر</button>
                <?php endif; ?>
                <input type="number" name="coins" placeholder="كوين" style="width:60px;padding:3px 6px;border:none;border-radius:6px;background:rgba(255,255,255,0.05);color:inherit;font-size:0.7rem">
                <button type="submit" formaction="" name="action" value="add_coins" style="padding:3px 8px;border:none;border-radius:6px;background:rgba(59,130,246,0.2);color:var(--accent-cyan);cursor:pointer;font-size:0.7rem">➕</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div id="sec-sites" class="sec">
    <div class="admin-card">
      <h3>🌐 المواقع المسحوبة</h3>
      <div id="sitesList" style="font-size:0.85rem;color:var(--text-muted)">جاري التحميل...</div>
    </div>
  </div>

  <div class="footer" style="margin-top:24px">
    Website Scraper Pro v4.0 — لوحة الإدارة
  </div>
</div>

<script>
function switchSec(id) {
  document.querySelectorAll('.sec').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.sec-tab').forEach(t => t.classList.remove('active'));
  document.getElementById('sec-' + id).classList.add('active');
  document.querySelector(`.sec-tab[data-sec="${id}"]`).classList.add('active');
}

// Load sites
fetch('api.php?action=list').then(r=>r.json()).then(d=>{
  const el = document.getElementById('sitesList');
  if (!d.sites || d.sites.length === 0) {
    el.innerHTML = '<p style="color:var(--text-muted)">لا توجد مواقع بعد</p>';
    return;
  }
  let html = '<table class="admin-table"><thead><tr><th>الموقع</th><th>الملفات</th><th>الحجم</th><th>التاريخ</th><th>تحميل</th></tr></thead><tbody>';
  d.sites.forEach(s => {
    const date = s.created_at ? new Date(s.created_at*1000).toLocaleDateString('ar-EG') : '—';
    const size = s.size ? (s.size > 1048576 ? (s.size/1048576).toFixed(1)+' MB' : (s.size > 1024 ? (s.size/1024).toFixed(1)+' KB' : s.size+' B')) : '—';
    html += `<tr><td style="font-size:0.7rem;max-width:200px;overflow:hidden;text-overflow:ellipsis"><a href="index.php?view=${encodeURIComponent(s.dir)}" style="color:var(--accent-cyan)">${e(s.url||s.dir)}</a></td><td>${s.files||0}</td><td>${size}</td><td>${date}</td><td><a href="api.php?action=download_zip&dir=${encodeURIComponent(s.dir)}" style="color:#10b981">📥 ZIP</a></td></tr>`;
  });
  html += '</tbody></table>';
  el.innerHTML = html;
}).catch(() => document.getElementById('sitesList').textContent = 'خطأ في التحميل');

function e(s) {
  if (!s) return '';
  const d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}
</script>
</body>
</html>
