<?php
/**
 * صفحة الترحيب - أداة تحضير الدروس بالذكاء الاصطناعي
 * Welcome Page - AI Lesson Preparation Tool
 */

// تحميل إعدادات الجلسة
require_once '../includes/session_config.php';
require_once '../classes/utilities.php';

// التحقق من تسجيل الدخول (يسمح للمعلمين الداخليين والخارجيين)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'external_teacher'])) {
    header('Location: ../index.php');
    exit;
}

$teacher_name = isset($_SESSION['name']) ? $_SESSION['name'] : 'المعلم';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>أداة تحضير الدروس بالذكاء الاصطناعي - DMLS</title>

    <!-- Bootstrap RTL CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=1.3">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            direction: rtl;
            transition: all 0.3s ease;
            overflow-x: hidden;
            padding-top: 0 !important;
            margin: 0;
        }

        /* Dark Mode Support */
        body.dark-mode {
            background: linear-gradient(135deg, #1e3a8a 0%, #4c1d95 100%);
        }

        /* Floating decorative elements */
        .floating-shapes {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }
        .floating-shapes .shape {
            position: absolute;
            border-radius: 50%;
            opacity: 0.08;
            animation: floatShape 20s ease-in-out infinite;
        }
        .floating-shapes .shape:nth-child(1) {
            width: 300px; height: 300px;
            background: #fff;
            top: -50px; right: -80px;
            animation-delay: 0s;
        }
        .floating-shapes .shape:nth-child(2) {
            width: 200px; height: 200px;
            background: #10b981;
            bottom: 10%; left: -50px;
            animation-delay: -5s;
            animation-duration: 25s;
        }
        .floating-shapes .shape:nth-child(3) {
            width: 150px; height: 150px;
            background: #f59e0b;
            top: 40%; right: 10%;
            animation-delay: -10s;
            animation-duration: 18s;
        }
        @keyframes floatShape {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            25% { transform: translate(30px, -30px) rotate(5deg); }
            50% { transform: translate(-20px, 20px) rotate(-3deg); }
            75% { transform: translate(15px, 10px) rotate(2deg); }
        }

        /* Main Content */
        .welcome-container {
            flex: 1 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 80px 20px;
            position: relative;
            z-index: 10;
        }

        .portal-footer .container {
            margin: 0 auto !important;
            padding: 0 20px !important;
        }

        .welcome-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 30px;
            padding: 55px 50px;
            max-width: 750px;
            width: 100%;
            text-align: center;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.15), 0 0 0 1px rgba(255,255,255,0.2);
            animation: fadeInUp 0.8s ease-out;
            position: relative;
            overflow: hidden;
        }

        .welcome-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #10b981, #3b82f6, #8b5cf6, #ec4899);
        }

        body.dark-mode .welcome-card {
            background: rgba(30, 41, 59, 0.95);
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.4), 0 0 0 1px rgba(255,255,255,0.05);
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Professional Logo */
        .welcome-logo {
            width: 110px;
            height: 110px;
            margin: 0 auto 25px;
            position: relative;
        }

        .logo-outer-ring {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2, #10b981);
            padding: 4px;
            animation: logoRotate 8s linear infinite;
            position: absolute;
            top: 0;
            left: 0;
        }

        .logo-inner {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: linear-gradient(135deg, #1e293b, #0f172a);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        body.dark-mode .logo-inner {
            background: linear-gradient(135deg, #0f172a, #020617);
        }

        .logo-inner::before {
            content: '';
            position: absolute;
            width: 60px;
            height: 60px;
            background: radial-gradient(circle, rgba(102, 126, 234, 0.3) 0%, transparent 70%);
            animation: logoPulse 3s ease-in-out infinite;
        }

        .logo-icon {
            position: relative;
            z-index: 2;
        }

        .logo-icon .fa-brain {
            font-size: 2.5rem;
            background: linear-gradient(135deg, #60a5fa, #a78bfa, #34d399);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            filter: drop-shadow(0 0 12px rgba(102, 126, 234, 0.5));
        }

        .logo-sparkles {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 110px;
            height: 110px;
            z-index: 3;
            pointer-events: none;
        }

        .logo-sparkles .sparkle {
            position: absolute;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #fbbf24;
            animation: sparkle 2.5s ease-in-out infinite;
        }
        .logo-sparkles .sparkle:nth-child(1) { top: 8px; right: 25px; animation-delay: 0s; }
        .logo-sparkles .sparkle:nth-child(2) { top: 25px; left: 8px; animation-delay: 0.8s; background: #60a5fa; }
        .logo-sparkles .sparkle:nth-child(3) { bottom: 15px; right: 12px; animation-delay: 1.6s; background: #34d399; }

        @keyframes logoRotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        @keyframes logoPulse {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.3); opacity: 0.8; }
        }

        @keyframes sparkle {
            0%, 100% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1); opacity: 1; }
        }

        /* Title */
        .welcome-title-ar {
            font-size: 2.3rem;
            font-weight: 800;
            color: #1e293b;
            margin-bottom: 8px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        body.dark-mode .welcome-title-ar {
            background: linear-gradient(135deg, #818cf8, #c084fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .welcome-title-en {
            font-size: 1.3rem;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 25px;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        body.dark-mode .welcome-title-en {
            color: #94a3b8;
        }

        /* Powered by AI badge */
        .ai-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 16px;
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            border: 1px solid #93c5fd;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            color: #1e40af;
            margin-bottom: 25px;
        }
        .ai-badge i { color: #3b82f6; }
        body.dark-mode .ai-badge {
            background: linear-gradient(135deg, #1e3a5f, #1e40af33);
            border-color: #3b82f6;
            color: #93c5fd;
        }

        /* Description */
        .welcome-description {
            font-size: 1.1rem;
            color: #475569;
            line-height: 1.9;
            margin-bottom: 35px;
            padding: 0 15px;
        }

        body.dark-mode .welcome-description {
            color: #cbd5e1;
        }

        /* Features */
        .features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin-bottom: 35px;
        }

        .feature-item {
            background: #f8fafc;
            padding: 22px 18px 18px;
            border-radius: 16px;
            transition: all 0.3s ease;
            border: 1px solid #e2e8f0;
            position: relative;
            overflow: hidden;
        }

        .feature-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            border-radius: 16px 16px 0 0;
        }

        .feature-item:nth-child(1)::before { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
        .feature-item:nth-child(2)::before { background: linear-gradient(90deg, #8b5cf6, #a78bfa); }
        .feature-item:nth-child(3)::before { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .feature-item:nth-child(4)::before { background: linear-gradient(90deg, #10b981, #34d399); }

        body.dark-mode .feature-item {
            background: #334155;
            border-color: #475569;
        }

        .feature-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.12);
        }
        body.dark-mode .feature-item:hover {
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.3);
        }

        .feature-item .feature-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
            font-size: 1.3rem;
        }

        .feature-item:nth-child(1) .feature-icon {
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            color: #3b82f6;
        }
        .feature-item:nth-child(2) .feature-icon {
            background: linear-gradient(135deg, #f5f3ff, #ede9fe);
            color: #8b5cf6;
        }
        .feature-item:nth-child(3) .feature-icon {
            background: linear-gradient(135deg, #fffbeb, #fef3c7);
            color: #f59e0b;
        }
        .feature-item:nth-child(4) .feature-icon {
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            color: #10b981;
        }

        body.dark-mode .feature-item:nth-child(1) .feature-icon { background: linear-gradient(135deg, #1e3a5f, #1e40af44); }
        body.dark-mode .feature-item:nth-child(2) .feature-icon { background: linear-gradient(135deg, #3b1f6e, #5b21b644); }
        body.dark-mode .feature-item:nth-child(3) .feature-icon { background: linear-gradient(135deg, #78350f, #92400e44); }
        body.dark-mode .feature-item:nth-child(4) .feature-icon { background: linear-gradient(135deg, #064e3b, #065f4644); }

        .feature-item h4 {
            font-size: 0.95rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0 0 4px 0;
        }

        .feature-item .feature-desc {
            font-size: 0.78rem;
            color: #94a3b8;
            margin: 0;
            line-height: 1.4;
        }

        body.dark-mode .feature-item h4 {
            color: #f1f5f9;
        }
        body.dark-mode .feature-item .feature-desc {
            color: #64748b;
        }

        /* Enter Button */
        .enter-btn {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            padding: 16px 50px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            font-size: 1.25rem;
            font-weight: 700;
            text-decoration: none;
            border-radius: 16px;
            transition: all 0.3s ease;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
            position: relative;
            overflow: hidden;
        }

        .enter-btn::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -60%;
            width: 200%;
            height: 200%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent);
            transform: rotate(25deg);
            animation: btnShimmer 4s ease-in-out infinite;
        }

        @keyframes btnShimmer {
            0%, 100% { left: -60%; }
            50% { left: 60%; }
        }

        .enter-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(102, 126, 234, 0.5);
            color: white;
        }

        .enter-btn i {
            font-size: 1.4rem;
        }

        /* Back Button */
        .portal-back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 25px;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #fff;
            font-size: 1rem;
            font-weight: 600;
            text-decoration: none;
            border-radius: 10px;
            margin-top: 18px;
            transition: all 0.3s ease;
            border: none;
            box-shadow: 0 3px 12px rgba(37,99,235,0.35);
        }

        body.dark-mode .portal-back-btn {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            box-shadow: 0 3px 12px rgba(37,99,235,0.3);
        }

        .portal-back-btn:hover {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 5px 18px rgba(37,99,235,0.45);
        }

        body.dark-mode .portal-back-btn:hover {
            background: linear-gradient(135deg, #60a5fa, #3b82f6);
            color: #fff;
        }

        /* Theme Toggle - Bottom Left */
        .theme-toggle {
            position: fixed;
            bottom: 20px;
            left: 20px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            border: none;
            background: white;
            color: #667eea;
            font-size: 1.3rem;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
            z-index: 1000;
        }

        body.dark-mode .theme-toggle {
            background: #334155;
            color: #fbbf24;
        }

        .theme-toggle:hover {
            transform: scale(1.1);
        }

        /* Back Button - Fixed Top Left */
        .back-button-container {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1000;
        }
        .back-button-top {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: white;
            padding: 12px 24px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.95rem;
            box-shadow: 0 4px 20px rgba(37,99,235,0.4);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            border: none;
        }
        .back-button-top:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(37,99,235,0.5);
            color: white;
        }
        body.dark-mode .back-button-top {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            box-shadow: 0 4px 20px rgba(37,99,235,0.4);
        }
        body.dark-mode .back-button-top:hover {
            box-shadow: 0 8px 25px rgba(59,130,246,0.5);
            color: white;
        }
        @media (max-width: 768px) {
            .back-button-container { top: 12px; left: 12px; }
            .back-button-top { padding: 10px 18px; font-size: 0.85rem; }
        }

        /* Footer */
        .portal-footer {
            background: #1e293b;
            color: white;
            padding: 1.25rem 0 0.75rem 0;
            border-top: 3px solid rgba(102, 126, 234, 0.3);
            position: relative;
            z-index: 1;
        }

        body.dark-mode .portal-footer {
            background: #0f172a;
            border-top: 3px solid rgba(100, 116, 139, 0.3);
        }

        .portal-footer p {
            margin: 0 0 0.5rem 0;
            color: rgba(255, 255, 255, 0.9);
            font-size: 1rem;
        }

        .social-media-footer {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .social-footer-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            font-size: 1.3rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }

        .social-footer-icon.facebook {
            background: linear-gradient(135deg, #1877f2 0%, #0c63d4 100%);
        }

        .social-footer-icon.whatsapp {
            background: linear-gradient(135deg, #25d366 0%, #128c7e 100%);
        }

        .social-footer-icon.instagram {
            background: linear-gradient(135deg, #e1306c 0%, #c13584 50%, #833ab4 100%);
        }

        .social-footer-icon:hover {
            transform: translateY(-3px) scale(1.1);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .welcome-card {
                padding: 40px 25px;
            }

            .welcome-logo {
                width: 120px;
                height: 120px;
            }
            .logo-outer-ring {
                width: 120px;
                height: 120px;
            }
            .logo-icon .fa-brain {
                font-size: 2.8rem;
            }
            .logo-sparkles {
                width: 120px;
                height: 120px;
            }

            .welcome-title-ar {
                font-size: 1.7rem;
            }

            .welcome-title-en {
                font-size: 1rem;
            }

            .features-grid {
                grid-template-columns: 1fr;
            }

            .enter-btn {
                padding: 14px 35px;
                font-size: 1.1rem;
            }
        }

        @media (max-width: 480px) {
            .welcome-card {
                padding: 25px 15px;
                margin: 10px;
                border-radius: 20px;
            }
            .welcome-logo {
                width: 100px;
                height: 100px;
            }
            .logo-outer-ring {
                width: 100px;
                height: 100px;
            }
            .logo-icon .fa-brain {
                font-size: 2.4rem;
            }
            .logo-sparkles {
                width: 100px;
                height: 100px;
            }
            .welcome-title-ar {
                font-size: 1.3rem;
            }
            .welcome-title-en {
                font-size: 0.85rem;
                letter-spacing: 1px;
            }
            .welcome-description {
                font-size: 0.88rem !important;
            }
            .feature-item {
                padding: 12px !important;
            }
            .feature-item h4 {
                font-size: 0.9rem !important;
            }
            .feature-item p {
                font-size: 0.8rem !important;
            }
            .enter-btn {
                padding: 12px 25px;
                font-size: 1rem;
                max-width: 100%;
            }
            .back-button-container {
                top: 8px !important;
                left: 8px !important;
            }
            .back-button-top {
                padding: 8px 14px !important;
                font-size: 0.8rem !important;
            }
        }
    </style>
</head>

<body>
    <!-- Particles Background -->
    <div id="particles-js"></div>

    <!-- Back Button - Top Left -->
    <div class="back-button-container">
        <a href="<?php echo ($_SESSION['role'] === 'external_teacher') ? '../external/index.php' : 'portal.php'; ?>" class="back-button-top">
            <i class="fas fa-arrow-right"></i>
            <span>العودة للبوابة</span>
        </a>
    </div>

    <!-- Theme Toggle - Bottom Right -->
    <button class="theme-toggle" id="themeToggle" title="تبديل الوضع المظلم/الفاتح">
        <i class="fas fa-moon"></i>
    </button>

    <!-- Main Content -->
    <div class="welcome-container">
        <div class="welcome-card">
            <!-- Professional Logo -->
            <div class="welcome-logo">
                <div class="logo-outer-ring">
                    <div class="logo-inner">
                        <div class="logo-icon">
                            <i class="fas fa-brain"></i>
                        </div>
                    </div>
                </div>
                <div class="logo-sparkles">
                    <div class="sparkle"></div>
                    <div class="sparkle"></div>
                    <div class="sparkle"></div>
                </div>
            </div>

            <!-- Title -->
            <h1 class="welcome-title-ar">أداة تحضير الدروس الذكية</h1>
            <h2 class="welcome-title-en">AI Lesson Preparation Tool</h2>

            <!-- AI Badge -->
            <div class="ai-badge">
                <i class="fas fa-microchip"></i>
                مدعومة بالذكاء الاصطناعي
            </div>

            <!-- Description -->
            <p class="welcome-description">
                أداة متطورة تعتمد على الذكاء الاصطناعي لمساعدة المعلمين في تحضير الدروس وإنشاء الاختبارات الإلكترونية بشكل احترافي وسريع. قم برفع المحتوى التعليمي واحصل على تحضير متكامل وبنك أسئلة متنوع في ثوانٍ معدودة.
            </p>

            <!-- Features -->
            <div class="features-grid">
                <div class="feature-item">
                    <div class="feature-icon"><i class="fas fa-book-open"></i></div>
                    <h4>تحضير الدرس</h4>
                    <p class="feature-desc">تحضير متكامل بجميع عناصره</p>
                </div>
                <div class="feature-item">
                    <div class="feature-icon"><i class="fas fa-question-circle"></i></div>
                    <h4>بنك الأسئلة</h4>
                    <p class="feature-desc">أسئلة متنوعة ومتدرجة المستوى</p>
                </div>
                <div class="feature-item">
                    <div class="feature-icon"><i class="fas fa-images"></i></div>
                    <h4>مواد بصرية</h4>
                    <p class="feature-desc">صور وخرائط ذهنية تفاعلية</p>
                </div>
                <div class="feature-item">
                    <div class="feature-icon"><i class="fas fa-laptop-code"></i></div>
                    <h4>امتحان إلكتروني</h4>
                    <p class="feature-desc">امتحانات أونلاين جاهزة للنشر</p>
                </div>
            </div>

            <!-- Enter Button -->
            <div style="display: flex; flex-direction: column; align-items: center; gap: 12px;">
                <a href="lesson_prep.php" class="enter-btn">
                    <i class="fas fa-arrow-left"></i>
                    ابدأ التحضير الآن
                </a>

            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="portal-footer">
        <div class="container text-center">
            <p>جميع الحقوق محفوظة © <?php echo date('Y'); ?><br> Delta Modern Language Schools<br>
                Computer Department</p>
            
            <div class="social-media-footer">
                <a href="https://www.facebook.com/DELTA.MLS" target="_blank" class="social-footer-icon facebook" title="صفحتنا على الفيسبوك">
                    <i class="fab fa-facebook-f"></i>
                </a>
                <a href="https://wa.me/201289999818" target="_blank" class="social-footer-icon whatsapp" title="الدعم الفني - واتساب">
                    <i class="fab fa-whatsapp"></i>
                </a>
                <a href="https://www.instagram.com/delta.mls" target="_blank" class="social-footer-icon instagram" title="حسابنا على انستجرام">
                    <i class="fab fa-instagram"></i>
                </a>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script src="script.js?v=1.2"></script>
    
    <script>
        // Theme Toggle
        (function() {
            const themeToggle = document.getElementById('themeToggle');
            const savedTheme = localStorage.getItem('theme');
            
            if (savedTheme === 'dark') {
                document.body.classList.add('dark-mode');
                document.body.classList.remove('light-mode');
                themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
            } else {
                document.body.classList.remove('dark-mode');
                document.body.classList.add('light-mode');
                themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
            }
            if (typeof updateParticlesTheme === 'function') updateParticlesTheme(savedTheme || 'light');
            
            themeToggle.addEventListener('click', () => {
                document.body.classList.toggle('dark-mode');
                
                if (document.body.classList.contains('dark-mode')) {
                    document.body.classList.remove('light-mode');
                    localStorage.setItem('theme', 'dark');
                    themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
                    if (typeof updateParticlesTheme === 'function') updateParticlesTheme('dark');
                } else {
                    document.body.classList.add('light-mode');
                    localStorage.setItem('theme', 'light');
                    themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
                    if (typeof updateParticlesTheme === 'function') updateParticlesTheme('light');
                }
            });
        })();
    </script>
</body>
</html>
