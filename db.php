<?php
require_once __DIR__ . '/config.php';

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $dbDir = dirname(DB_PATH);
        if (!is_dir($dbDir)) @mkdir($dbDir, 0777, true);
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
    }
    return $pdo;
}

function initDB() {
    $pdo = getDB();
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL,
        email TEXT,
        coins INTEGER DEFAULT 100,
        is_admin INTEGER DEFAULT 0,
        is_banned INTEGER DEFAULT 0,
        welcomed INTEGER DEFAULT 0,
        created_at TEXT DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        amount REAL NOT NULL,
        coins INTEGER NOT NULL,
        phone TEXT NOT NULL,
        phone_paid_to TEXT,
        transaction_id TEXT NOT NULL,
        status TEXT DEFAULT 'pending',
        admin_id INTEGER,
        created_at TEXT DEFAULT (datetime('now')),
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS scrape_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        url TEXT NOT NULL,
        files_count INTEGER DEFAULT 0,
        total_size INTEGER DEFAULT 0,
        status TEXT DEFAULT 'completed',
        created_at TEXT DEFAULT (datetime('now')),
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_sites (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        url TEXT NOT NULL,
        domain TEXT NOT NULL,
        title TEXT,
        description TEXT,
        scraped_at TEXT DEFAULT (datetime('now'))
    )");

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE is_admin = 1");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $pwd = password_hash('admin', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT OR IGNORE INTO users (username, password, email, coins, is_admin, welcomed) VALUES (?, ?, ?, ?, 1, 1)")
            ->execute(['admin', $pwd, 'admin@scraper.com', 999999]);
    }

    // Default settings
    $defaults = [
        'site_name' => 'Website Scraper',
        'site_description' => 'اسحب أي موقع كامل',
        'max_depth' => '20',
        'scrape_price' => '30',
        'payment_number_1' => '01000000000',
        'payment_number_2' => '01000000001',
        'welcome_video_url' => 'https://www.youtube.com/watch?v=pQqPuMe3PPw',
        'ai_api_key' => '',
        'ai_model' => 'gemini-2.0-flash',
        'secret_keywords' => '',
    ];
    foreach ($defaults as $k => $v) {
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
        $stmt->execute([$k, $v]);
    }
}

function getSettings() {
    $pdo = getDB();
    $stmt = $pdo->query("SELECT key, value FROM settings");
    $settings = [];
    while ($row = $stmt->fetch()) $settings[$row['key']] = $row['value'];
    return $settings;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getCurrentUser() {
    if (!isset($_SESSION['user_id'])) return null;
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}
