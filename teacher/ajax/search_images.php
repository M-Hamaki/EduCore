<?php
/**
 * البحث عن صور من الإنترنت (AJAX)
 * Search for images on the web using Pixabay, Unsplash & Pexels APIs
 */

require_once __DIR__ . '/../../includes/session_config.php';
header('Content-Type: application/json; charset=utf-8');

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'external_teacher'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../../config/ai_config.php';
require_once __DIR__ . '/../../classes/WebImageSearch.php';
require_once __DIR__ . '/../../includes/http_helpers.php';

// التحقق من الطلب
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'طريقة طلب غير صالحة'], JSON_UNESCAPED_UNICODE);
    exit;
}
requireCsrfToken();

// استلام البيانات
$input = json_decode(file_get_contents('php://input'), true);
$query = trim($input['query'] ?? '');
$category = trim($input['category'] ?? '');
$count = intval($input['count'] ?? 4);

// التحقق من المدخلات
if (empty($query)) {
    echo json_encode(['success' => false, 'message' => 'كلمة البحث مطلوبة'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (mb_strlen($query) > 200) {
    echo json_encode(['success' => false, 'message' => 'كلمة البحث طويلة جداً (حد أقصى 200 حرف)'], JSON_UNESCAPED_UNICODE);
    exit;
}

$count = max(1, min($count, 10)); // 1-10 images

try {
    $imageSearch = new WebImageSearch();
    
    if (!$imageSearch->isAvailable()) {
        echo json_encode([
            'success' => false, 
            'message' => 'خدمة البحث عن الصور غير متوفرة. تحقق من إعداد مفاتيح API (Pixabay/Unsplash/Pexels) في ملف الإعدادات.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $images = $imageSearch->search($query, $category, $count);
    
    if (empty($images)) {
        echo json_encode([
            'success' => true,
            'images' => [],
            'message' => 'لم يتم العثور على صور مطابقة. جرب كلمات بحث مختلفة باللغة الإنجليزية.',
            'query' => $query
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'images' => $images,
        'count' => count($images),
        'query' => $query
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Image search AJAX error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ أثناء البحث: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
