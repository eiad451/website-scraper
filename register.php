<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
initDB();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $email = trim($_POST['email'] ?? '');
    if ($username && $password && $email) {
        if (strlen($username) < 3 || strlen($username) > 30) {
            $error = 'اسم المستخدم يجب أن يكون بين 3 و 30 حرف';
        } elseif (strlen($password) < 4) {
            $error = 'كلمة المرور يجب أن تكون 4 أحرف على الأقل';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'البريد الإلكتروني غير صالح';
        } else {
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                $error = 'اسم المستخدم أو البريد موجود بالفعل';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, email) VALUES (?, ?, ?)");
                $stmt->execute([$username, $hash, $email]);
                $success = '✅ تم التسجيل بنجاح! يمكنك تسجيل الدخول الآن';
            }
        }
    } else {
        $error = 'يرجى ملء جميع الحقول';
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>إنشاء حساب</title>
<link rel="stylesheet" href="style.css">
<style>
body{display:flex;align-items:center;justify-content:center;min-height:100vh}
.form-error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.2);padding:10px 14px;border-radius:var(--radius-xs);font-size:0.82rem;color:var(--accent-red);margin-bottom:14px}
.form-success{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.2);padding:10px 14px;border-radius:var(--radius-xs);font-size:0.82rem;color:var(--accent-4);margin-bottom:14px}
.auth-link{color:var(--accent-1);text-decoration:none;font-weight:500}
.auth-link:hover{text-decoration:underline}
</style>
</head>
<body>
<div class="bg-effects">
  <div class="bg-grid"></div>
  <div class="bg-orb bg-orb-1"></div>
  <div class="bg-orb bg-orb-2"></div>
  <div class="bg-orb bg-orb-3"></div>
</div>
<div style="max-width:420px;width:90%;animation:fadeInUp 0.6s ease">
  <div class="card" style="text-align:center;padding:32px">
    <div style="font-size:3rem;margin-bottom:12px;filter:drop-shadow(0 0 20px rgba(0,212,255,0.2))">📝</div>
    <h2 style="margin-bottom:20px;font-size:1.3rem;font-weight:700">إنشاء حساب جديد</h2>
    <?php if ($error): ?>
      <div class="form-error"><?=e($error)?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="form-success"><?=e($success)?></div>
    <?php endif; ?>
    <form method="post">
      <div class="form-group">
        <label>👤 اسم المستخدم</label>
        <input type="text" name="username" required minlength="3" dir="ltr" value="<?=e($_POST['username'] ?? '')?>">
      </div>
      <div class="form-group">
        <label>📧 البريد الإلكتروني</label>
        <input type="email" name="email" required dir="ltr" value="<?=e($_POST['email'] ?? '')?>">
      </div>
      <div class="form-group">
        <label>🔑 كلمة المرور</label>
        <input type="password" name="password" required minlength="4" dir="ltr">
      </div>
      <button type="submit" class="btn-primary" style="width:100%">✅ تسجيل</button>
    </form>
    <div style="margin-top:16px;font-size:0.82rem;color:var(--text-muted)">
      عندك حساب؟ <a href="login.php" class="auth-link">سجل دخول</a>
    </div>
  </div>
</div>
</body>
</html>
