<?php

declare(strict_types=1);

require_once __DIR__ . '/config/env_loader.php';

$organizationName = trim((string) env('ORGANIZATION_NAME', 'EduCore Deployment'));
$supportEmail = trim((string) env('SUPPORT_EMAIL', 'admin@example.com'));
$supportPhone = trim((string) env('SUPPORT_PHONE', ''));
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سياسة الخصوصية - <?= $escape($organizationName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { background: #f8f9fa; }
        .content-card { background: white; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 15px 15px 0 0; }
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="content-card">
            <div class="header p-4">
                <h1 class="mb-0"><i class="fas fa-shield-alt me-2"></i>سياسة الخصوصية</h1>
                <p class="mb-0 mt-2 opacity-75"><?= $escape($organizationName) ?></p>
            </div>
            <div class="p-4">
                <p class="text-muted">قالب عام — يجب على مشغل النشر تخصيصه قبل الاستخدام.</p>

                <div class="alert alert-warning">
                    <i class="fas fa-triangle-exclamation me-2"></i>
                    Customize this privacy notice for your organization, jurisdiction, integrations,
                    and data-processing practices. كل مؤسسة تشغّل EduCore مسؤولة عن إعداد سياسة الخصوصية
                    المناسبة لتشغيلها وقوانين بلدها.
                </div>

                <h4 class="mt-4"><i class="fas fa-info-circle me-2 text-primary"></i>مقدمة</h4>
                <p>توضح هذه الصفحة كيف يمكن لنشر <?= $escape($organizationName) ?> جمع البيانات واستخدامها وحمايتها. يجب على المشغل مراجعة النص وتحديد التفاصيل الفعلية قبل إتاحته للمستخدمين.</p>

                <h4 class="mt-4"><i class="fas fa-database me-2 text-primary"></i>البيانات التي قد تُعالج</h4>
                <ul>
                    <li>بيانات الحساب والهوية التي يحددها المشغل.</li>
                    <li>البيانات الأكاديمية أو الوظيفية اللازمة للخدمات المفعّلة.</li>
                    <li>سجلات الحضور والتقييم والنشاط وفق إعدادات النشر.</li>
                    <li>الملفات والمرفقات التي يرفعها المستخدمون المصرح لهم.</li>
                </ul>
                <p class="text-muted">تختلف الفئات الفعلية حسب الوحدات والتكاملات التي يفعّلها كل مشغل.</p>

                <h4 class="mt-4"><i class="fas fa-cogs me-2 text-primary"></i>أغراض المعالجة</h4>
                <ul>
                    <li>توفير الخدمات التعليمية والإدارية المفعّلة.</li>
                    <li>إدارة الحسابات والصلاحيات والتواصل التشغيلي.</li>
                    <li>تقديم التقارير والمواد التعليمية عند السماح بذلك.</li>
                    <li>حماية النظام والتحقيق في الحوادث الأمنية.</li>
                </ul>

                <h4 class="mt-4"><i class="fas fa-lock me-2 text-primary"></i>الحماية والاحتفاظ</h4>
                <p>على المشغل تحديد ضوابط الوصول، ومواقع التخزين، وفترات الاحتفاظ، والنسخ الاحتياطي، وإجراءات الاستجابة للحوادث بما يتناسب مع بياناته والتزاماته القانونية. يجب عدم استخدام بيانات إنتاج حقيقية في تقارير الأخطاء أو بيئات التطوير.</p>

                <h4 class="mt-4"><i class="fab fa-microsoft me-2 text-primary"></i>التكاملات الخارجية</h4>
                <p>إذا فعّل المشغل Microsoft SSO أو Teams أو خدمات AI أو أي تكامل آخر، فيجب وصف البيانات التي تنتقل إلى كل مزود وأساس النقل والاحتفاظ بها في سياسة النشر النهائية.</p>

                <h4 class="mt-4"><i class="fas fa-user-shield me-2 text-primary"></i>حقوق المستخدمين</h4>
                <p>يحدد المشغل آلية طلب الوصول إلى البيانات أو تصحيحها أو حذفها أو الاعتراض على معالجتها، مع مراعاة القانون المحلي وطبيعة السجلات التعليمية أو الوظيفية.</p>

                <h4 class="mt-4"><i class="fas fa-envelope me-2 text-primary"></i>التواصل معنا</h4>
                <p>للاستفسارات المتعلقة بالخصوصية في هذا النشر:</p>
                <ul>
                    <?php if ($supportEmail !== ''): ?>
                        <li>البريد: <a href="mailto:<?= $escape($supportEmail) ?>"><?= $escape($supportEmail) ?></a></li>
                    <?php endif; ?>
                    <?php if ($supportPhone !== ''): ?>
                        <li>الهاتف: <?= $escape($supportPhone) ?></li>
                    <?php endif; ?>
                </ul>

                <div class="mt-5 text-center">
                    <a href="login.php" class="btn btn-primary">
                        <i class="fas fa-arrow-right me-2"></i>العودة لتسجيل الدخول
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
