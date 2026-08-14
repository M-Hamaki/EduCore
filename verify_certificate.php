<?php
/**
 * التحقق من الشهادات - Public Certificate Verification
 * صفحة عامة للتحقق من صحة الشهادات التدريبية
 * لا تحتاج تسجيل دخول
 *
 * تحصين: تحديد معدل الطلبات لكل IP (15/دقيقة) لتقليل خطر تعدّد محاولات تخمين
 * أرقام الشهادات (enumeration). رقم الشهادة يحتوي على جزء عشوائي طويل.
 */
require_once 'config/database.php';
require_once 'classes/Training.php';
require_once 'classes/RateLimiter.php';

$database = new Database();
$db = $database->getConnection();
$db->exec("SET NAMES 'utf8mb4'");

$training = new Training($db);

$certNumber = '';
$result = null;
$searched = false;
$rateLimited = false;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['cert'])) {
    $certNumber = trim($_GET['cert']);

    // تحديد المعدل لكل IP: 15 محاولة بحث/دقيقة كافية للاستخدام الطبيعي.
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    // في حالة وجود X-Forwarded-For متعدد، خذ أول عنوان.
    if (strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }

    if (!empty($certNumber)) {
        if (!RateLimiter::hit('cert_verify:' . $ip, 15, 60)) {
            http_response_code(429);
            $rateLimited = true;
            $searched = true;
        } else {
            $searched = true;
            $result = $training->verifyCertificateByNumber($certNumber);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>التحقق من الشهادات - EduCore</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { font-family: 'Cairo', sans-serif; }
        html { background: #1e293b; min-height: 100%; scroll-behavior: smooth; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(25px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-6px); } }
        body {
            background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 50%, #0a58ca 100%);
            background-attachment: fixed;
            background-color: #1e293b;
            min-height: 100vh;
            margin: 0;
            display: flex;
            flex-direction: column;
        }

        .verify-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }

        .verify-card {
            background: rgba(255,255,255,0.97);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border-radius: 28px;
            padding: 3rem;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15), 0 4px 12px rgba(13,110,253,0.05);
            max-width: 660px;
            width: 100%;
            border: 1px solid rgba(255,255,255,0.6);
            animation: fadeInUp 0.6s ease;
        }

        .verify-logo {
            width: 85px;
            height: 85px;
            object-fit: contain;
            margin-bottom: 1.2rem;
            animation: float 3s ease-in-out infinite;
        }

        .verify-title {
            font-size: 1.8rem;
            font-weight: 800;
            color: #1e293b;
            margin-bottom: 0.3rem;
            letter-spacing: -0.3px;
        }

        .verify-subtitle {
            color: #64748b;
            font-size: 1rem;
            margin-bottom: 2rem;
            line-height: 1.6;
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            width: 100%;
            padding: 16px 60px 16px 20px;
            border: 2px solid #e2e8f0;
            border-radius: 18px;
            font-size: 1.1rem;
            font-family: 'Courier New', monospace;
            letter-spacing: 1px;
            color: #1e293b;
            transition: all 0.3s;
            direction: ltr;
            text-align: center;
        }

        .search-box input:focus {
            outline: none;
            border-color: #0d6efd;
            box-shadow: 0 0 0 4px rgba(13,110,253,0.15);
        }

        .search-box button {
            position: absolute;
            left: 8px;
            top: 50%;
            transform: translateY(-50%);
            width: 46px;
            height: 46px;
            border-radius: 14px;
            border: none;
            background: linear-gradient(135deg, #0d6efd, #0b5ed7);
            color: white;
            font-size: 1.1rem;
            cursor: pointer;
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .search-box button:hover {
            transform: translateY(-50%) scale(1.08);
            box-shadow: 0 6px 18px rgba(13,110,253,0.4);
        }

        /* Result Card */
        .result-card {
            margin-top: 2rem;
            border-radius: 20px;
            overflow: hidden;
            animation: slideUp 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(25px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .result-valid {
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
            border: 2px solid #86efac;
            box-shadow: 0 4px 20px rgba(34,197,94,0.1);
        }

        .result-invalid {
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            border: 2px solid #fca5a5;
            box-shadow: 0 4px 20px rgba(239,68,68,0.1);
        }

        .result-header {
            padding: 1.5rem 2rem;
            text-align: center;
        }

        .result-valid .result-header i {
            font-size: 3rem;
            color: #16a34a;
        }

        .result-invalid .result-header i {
            font-size: 3rem;
            color: #dc2626;
        }

        .result-valid .result-header h4 {
            color: #15803d;
            font-weight: 800;
            margin-top: 0.5rem;
        }

        .result-invalid .result-header h4 {
            color: #b91c1c;
            font-weight: 800;
            margin-top: 0.5rem;
        }

        .result-details {
            padding: 0 2rem 2rem;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 18px;
            border-radius: 14px;
            margin-bottom: 8px;
            background: rgba(255,255,255,0.75);
            transition: background 0.3s, transform 0.3s;
        }
        .detail-row:hover {
            background: rgba(255,255,255,0.95);
            transform: translateX(-3px);
        }

        .detail-label {
            font-weight: 700;
            color: #475569;
            font-size: 0.95rem;
        }
        .detail-label i { color: #0d6efd; }

        .detail-value {
            font-weight: 600;
            color: #1e293b;
            font-size: 0.95rem;
        }

        /* Footer */
        .portal-footer {
            background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
            color: rgba(255,255,255,0.8);
            text-align: center;
            padding: 2rem 1rem;
            font-size: 0.9rem;
        }
        .portal-footer p {
            color: #94a3b8;
            margin: 0.5rem 0;
            line-height: 1.6;
        }
        .portal-footer a {
            color: #93c5fd;
            text-decoration: none;
        }
        .social-media-footer {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-top: 1rem;
        }
        .social-footer-icon {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        .social-footer-icon.facebook { background: linear-gradient(135deg, #1877f2, #0c63d4); }
        .social-footer-icon.whatsapp { background: linear-gradient(135deg, #25d366, #128c7e); }
        .social-footer-icon.instagram { background: linear-gradient(135deg, #e1306c, #c13584 50%, #833ab4); }
        .social-footer-icon:hover { transform: translateY(-4px) scale(1.1); }

        @media (max-width: 576px) {
            .verify-card { padding: 2rem 1.5rem; border-radius: 22px; }
            .verify-title { font-size: 1.4rem; }
            .search-box input { padding: 14px 55px 14px 16px; font-size: 0.95rem; border-radius: 14px; }
            .detail-row { padding: 12px 14px; flex-direction: column; align-items: flex-start; gap: 4px; }
            .result-header { padding: 1.2rem 1.5rem; }
            .result-details { padding: 0 1.5rem 1.5rem; }
        }
    </style>
</head>
<body>
    <div class="verify-container">
        <div class="verify-card">
            <div class="text-center">
                <?php
                if (!function_exists('get_school_logo')) { require_once 'includes/template_helper.php'; }
                ?>
                <img src="<?php echo get_school_logo(''); ?>" alt="شعار المدرسة" class="verify-logo">
                <h1 class="verify-title"><i class="fas fa-shield-alt me-2" style="color: #0d6efd;"></i>التحقق من الشهادات</h1>
                <p class="verify-subtitle">أدخل رقم الشهادة للتحقق من صحتها وصلاحيتها</p>
            </div>

            <form method="GET" action="">
                <div class="search-box">
                    <input type="text" name="cert" placeholder="CERT-2026-001-0001-XXXXXXXXXXXX"
                           value="<?php echo htmlspecialchars($certNumber); ?>"
                           required autocomplete="off">
                    <button type="submit"><i class="fas fa-search"></i></button>
                </div>
            </form>

            <?php if ($searched): ?>
                <?php if ($rateLimited): ?>
                    <!-- Rate Limit Exceeded -->
                    <div class="result-card result-invalid p-4 border border-warning border-2 rounded-3 text-center mt-3">
                        <i class="fas fa-hourglass-half fa-2x text-warning mb-2"></i>
                        <h4 class="text-warning fw-bold">تم تجاوز الحد المسموح من المحاولات</h4>
                        <p class="text-muted mb-0">يرجى المحاولة مرة أخرى بعد دقيقة. الحد المسموح هو 15 محاولة بحث لكل عنوان IP في الدقيقة.</p>
                    </div>
                <?php elseif ($result): ?>
                    <!-- Verification Alert Header -->
                    <div class="alert alert-success d-flex align-items-center mb-3 no-print rounded-3 shadow-sm border-0" role="alert">
                        <i class="fas fa-check-circle fa-2x me-3 text-success"></i>
                        <div>
                            <strong class="fs-6">شهادة رسمية صحيحة ومعتمدة</strong>
                            <div class="small text-muted">تم التحقق بنجاح من بيانات الشهادة المسجلة برقم <code><?php echo htmlspecialchars($result['certificate_number']); ?></code></div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
                        <a href="verify_certificate.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-search me-1"></i>بحث جديد</a>
                        <button onclick="window.print()" class="btn btn-primary btn-sm px-4 shadow"><i class="fas fa-print me-1"></i>طباعة / حفظ PDF</button>
                    </div>

                    <!-- Printable Official Certificate Document -->
                    <div class="certificate-document-wrapper">
                        <div class="certificate-document p-4 p-md-5 bg-white border border-4 rounded-4 position-relative overflow-hidden">
                            <div class="cert-watermark">
                                <i class="fas fa-award"></i>
                            </div>
                            
                            <div class="cert-inner-border p-3 p-md-4 border border-2 border-primary rounded-3 text-center position-relative">
                                <!-- Header Logo & Institution -->
                                <div class="d-flex justify-content-between align-items-center mb-4 cert-header-row">
                                    <div class="text-start">
                                        <h6 class="fw-bold mb-0 text-primary">EduCore</h6>
                                        <small class="text-muted">Open Source School Platform</small>
                                    </div>
                                    <img src="<?php echo get_school_logo(''); ?>" alt="Logo" class="cert-logo" style="max-height: 65px;">
                                    <div class="text-end">
                                        <h6 class="fw-bold mb-0 text-dark">قسم التطوير المهني</h6>
                                        <small class="text-muted">Professional Development</small>
                                    </div>
                                </div>

                                <!-- Title -->
                                <div class="my-4">
                                    <h1 class="cert-main-title text-primary fw-bold">شَهَادَةُ إِتْمَامٍ وَتَقْدِيرٍ</h1>
                                    <p class="text-muted small mb-0">Certificate of Completion & Professional Development</p>
                                </div>

                                <!-- Body Text -->
                                <div class="cert-body py-2">
                                    <p class="fs-6 text-dark mb-1">تشهد إدارة التدريب والتطوير المهني بالمدرسة بأن المعلم / المعلمة:</p>
                                    <h2 class="cert-recipient-name text-dark fw-bold my-3 px-4 py-2 d-inline-block border-bottom border-3 border-warning">
                                        <?php echo htmlspecialchars($result['teacher_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </h2>
                                    <p class="fs-6 text-dark mt-2 mb-1">
                                        قد أتم/أتمت بنجاح وكفاءة متطلبات الدورة التدريبية:
                                    </p>                                    <h3 class="cert-course-name text-primary fw-bold my-2">
                                        « <?php 
                                            $vLang = $result['display_language'] ?? 'ar';
                                            $vTitle = ($vLang === 'en' && !empty($result['course_title_en'])) ? $result['course_title_en'] : $result['course_title'];
                                            echo htmlspecialchars($vTitle, ENT_QUOTES, 'UTF-8'); 
                                        ?> »
                                    </h3>
                                    <p class="text-muted small mb-3">
                                        ضمن برنامج: <strong><?php echo htmlspecialchars($result['program_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <?php if (!empty($result['score'])): ?>
                                             بنسبة نجاح <strong><?php echo round($result['score']); ?>%</strong>
                                        <?php endif; ?>
                                    </p>
                                </div>

                                <!-- Footer Meta & Signatures -->
                                <div class="row align-items-center mt-4 pt-3 border-top cert-footer-row">
                                    <div class="col-4 text-start">
                                        <div class="cert-meta-item">
                                            <small class="text-muted d-block small">تاريخ الإصدار / Issue Date</small>
                                            <strong class="text-dark small"><?php echo date('Y/m/d', strtotime($result['issued_at'])); ?></strong>
                                        </div>
                                        <div class="cert-meta-item mt-2">
                                            <small class="text-muted d-block small">رقم الشهادة / Certificate ID</small>
                                            <code class="text-primary fw-bold small"><?php echo htmlspecialchars($result['certificate_number'], ENT_QUOTES, 'UTF-8'); ?></code>
                                        </div>
                                    </div>
                                    <div class="col-4 text-center">
                                        <div class="gold-badge-emblem">
                                            <i class="fas fa-award fa-3x text-warning"></i>
                                            <div class="small fw-bold text-dark mt-1">شهادة معتمدة</div>
                                        </div>
                                    </div>
                                    <div class="col-4 text-end">
                                        <div class="cert-signature">
                                            <small class="text-muted d-block small">إدارة التطوير المهني</small>
                                            <div class="fw-bold text-dark small mt-2">EduCore Training</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="result-card result-invalid p-4 border border-danger border-2 rounded-3 bg-soft-danger text-center mt-3">
                        <i class="fas fa-times-circle fa-3x text-danger mb-2"></i>
                        <h4 class="text-danger fw-bold">رقم الشهادة غير صحيح</h4>
                        <p class="text-muted mb-0">لم يتم العثور على شهادة بهذا الرقم. يرجى التأكد من إدخال الرقم بشكل صحيح.</p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="text-center mt-4 no-print">
                <small style="color: #94a3b8;">
                    <i class="fas fa-university me-1"></i>
                    نظام الشهادات والتحقق الإلكتروني - مدارس الدلتا الحديثة للغات
                </small>
            </div>
        </div>
    </div>

    <footer class="portal-footer no-print" style="margin-top: 4rem;">
        <div class="container text-center">
            <p style="margin: 0.5rem 0; line-height: 1.6;">
                <strong>جميع الحقوق محفوظة © <?php echo date('Y'); ?></strong>
            </p>
            <p style="margin: 0.5rem 0; line-height: 1.6;">
                EduCore<br>
                Open Source School Platform
            </p>
            
            <!-- Social Media Icons in Footer -->
            <div class="social-media-footer">
                <a href="https://github.com/M-Hamaki/EduCore" target="_blank" class="social-footer-icon github" title="مستودع المشروع">
                    <i class="fab fa-github"></i>
                </a>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
