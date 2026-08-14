<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/session_config.php';
require_once __DIR__ . '/vendor/autoload.php';

use EduCore\Modules\PublicPortal\Domain\IntroVisitPolicy;

$portalConfig = require __DIR__ . '/config/public_portal.php';
$introPolicy = new IntroVisitPolicy((int) ($portalConfig['intro_interval_seconds'] ?? 1296000));
$destination = $introPolicy->normalizeDestination(isset($_GET['destination']) ? (string) $_GET['destination'] : null);
$redirectTarget = $introPolicy->routeForDestination($destination);
$teamsContext = (string) ($_GET['from_teams'] ?? '') === '1';
if ($teamsContext) {
    $redirectTarget .= '&from_teams=1';
    header('Location: ' . $redirectTarget, true, 302);
    exit;
}

$_SESSION['intro_shown'] = true;
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
setcookie(IntroVisitPolicy::COOKIE_NAME, (string) time(), [
    'expires' => time() + $introPolicy->intervalSeconds(),
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مرحباً - نظام الإدارة المدرسية</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            overflow: hidden;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .intro-container {
            width: 100%;
            height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            background: #000;
        }

        .video-container {
            width: 100%;
            height: 100%;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #introVideo {
            width: 100%;
            height: 100%;
            max-width: 100vw;
            max-height: 100vh;
        }

        .skip-button {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: 2px solid white;
            padding: 14px 32px;
            border-radius: 50px;
            font-size: 18px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Cairo', sans-serif;
            z-index: 1000;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .skip-button:hover {
            background: white;
            color: #667eea;
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.5);
        }
        
        .skip-button::before {
            content: '→';
            font-size: 22px;
            transition: transform 0.3s ease;
        }
        
        .skip-button:hover::before {
            transform: translateX(-5px);
        }

        .loading-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 24px;
            font-weight: 600;
            text-align: center;
            z-index: 100;
        }

        .spinner {
            margin: 20px auto;
            width: 50px;
            height: 50px;
            border: 4px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            .skip-button {
                bottom: 20px;
                right: 20px;
                padding: 10px 20px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="intro-container">
        <div class="loading-text" id="loadingText">
            <div class="spinner"></div>
            <p>جاري التحميل...</p>
        </div>
        <div class="video-container">
            <div id="introVideo"></div>
        </div>
        <button class="skip-button" id="skipIntroButton" type="button">
            تخطي الفيديو
        </button>
    </div>

    <script>
        // === Skip / redirect functions defined BEFORE YouTube API to ensure they always work ===
        var player = null;

        function skipIntro() {
            try { if (player && player.stopVideo) player.stopVideo(); } catch(e) {}
            redirectToMain();
        }

        function redirectToMain() {
            window.location.replace(<?php echo json_encode($redirectTarget, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>);
        }

        document.getElementById('skipIntroButton').addEventListener('click', skipIntro);
    </script>

    <!-- YouTube IFrame API -->
    <script src="https://www.youtube.com/iframe_api"></script>
    
    <script>
        const loadingText = document.getElementById('loadingText');

        // ضع هنا ID الفيديو من YouTube
        const YOUTUBE_VIDEO_ID = '23-A4_1VYGE'; // فيديو المدرسة

        // يتم استدعاء هذه الدالة تلقائياً عندما يكون YouTube API جاهز
        function onYouTubeIframeAPIReady() {
            player = new YT.Player('introVideo', {
                height: '100%',
                width: '100%',
                videoId: YOUTUBE_VIDEO_ID,
                playerVars: {
                    'autoplay': 1,        // تشغيل تلقائي
                    'mute': 1,            // كتم الصوت (مطلوب للتشغيل التلقائي)
                    'controls': 0,        // إخفاء أزرار التحكم
                    'showinfo': 0,        // إخفاء معلومات الفيديو
                    'rel': 0,             // عدم إظهار فيديوهات مقترحة
                    'modestbranding': 1,  // إخفاء شعار YouTube
                    'playsinline': 1,     // للموبايل
                    'fs': 0,              // إخفاء زر الشاشة الكاملة
                    'disablekb': 1        // تعطيل التحكم بالكيبورد
                },
                events: {
                    'onReady': onPlayerReady,
                    'onStateChange': onPlayerStateChange,
                    'onError': onPlayerError
                }
            });
        }

        // عندما يكون المشغل جاهز
        function onPlayerReady(event) {
            loadingText.style.display = 'none';
            event.target.playVideo();
            
            // إلغاء كتم الصوت بعد ثانية (اختياري)
            setTimeout(function() {
                if (player && player.unMute) player.unMute();
            }, 1000);
        }

        // عند تغيير حالة الفيديو
        function onPlayerStateChange(event) {
            // YT.PlayerState.ENDED = 0
            if (event.data == YT.PlayerState.ENDED) {
                redirectToMain();
            }
        }

        // في حالة حدوث خطأ
        function onPlayerError(event) {
            console.error('YouTube video error:', event.data);
            redirectToMain();
        }

        // إخفاء شاشة التحميل بعد 5 ثواني كحد أقصى
        setTimeout(function() {
            if (loadingText.style.display !== 'none') {
                loadingText.style.display = 'none';
            }
        }, 5000);

        // Fallback: if YouTube API fails to load within 8 seconds, ensure skip still works
        setTimeout(function() {
            if (!player) {
                loadingText.style.display = 'none';
            }
        }, 8000);
    </script>
</body>
</html>
