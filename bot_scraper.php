<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

class BotScraper {
    private $pdo;
    public function __construct() {
        $this->pdo = getDB();
    }

    public function startBotScrape() {
        $handle = popen('python3 ' . escapeshellarg(__DIR__ . '/bot_scraper.py') . ' 2>&1', 'r');
        if (!$handle) return ['success' => false, 'error' => 'Failed to start'];
        $output = '';
        while (!feof($handle)) $output .= fgets($handle);
        pclose($handle);
        $result = @json_decode($output, true);
        if (!$result) $result = ['output' => $output];
        return $result;
    }

    public function getSites() {
        $stmt = $this->pdo->query("SELECT DISTINCT domain FROM bot_sites ORDER BY domain");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getStats() {
        $stmt = $this->pdo->query("SELECT COUNT(*) as total FROM bot_sites");
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = $this->pdo->query("SELECT COUNT(*) as scanned FROM bot_sites WHERE scanned_at IS NOT NULL");
        $scanned = $stmt->fetch(PDO::FETCH_ASSOC);
        $res['scanned'] = $scanned['scanned'];
        return $res;
    }

    public function search($q) {
        $q = '%' . $q . '%';
        $stmt = $this->pdo->prepare("SELECT * FROM bot_sites WHERE url LIKE ? OR title LIKE ? OR description LIKE ? LIMIT 50");
        $stmt->execute([$q, $q, $q]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSite($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM bot_sites WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
