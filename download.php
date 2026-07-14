<?php
/**
 * Download Handler - تحميل الملفات من خارج document root
 */
$file = isset($_GET['file']) ? $_GET['file'] : '';
if (!$file) die('❌ اسم الملف مطلوب');

$path = '/storage/emulated/0/Download/' . basename($file);
if (!file_exists($path)) die('❌ الملف غير موجود');

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . basename($file) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
