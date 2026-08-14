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
    <title>شروط الاستخدام - <?= $escape($organizationName) ?></title>
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
                <h1 class="mb-0"><i class="fas fa-file-contract me-2"></i>شروط الاستخدام</h1>
                <p class="mb-0 mt-2 opacity-75"><?= $escape($organizationName) ?></p>
            </div>
            <div class="p-4">
                <p class="text-muted">قالب عام — يجب على مشغل النشر مراجعته وتخصيصه قبل الاستخدام.</p>

                <div class="alert alert-info">
                    <i class="fas fa-circle-info me-2"></i>
                    هذه الصفحة قالب عام لنشر EduCore. كل مؤسسة تشغّل النظام مسؤولة عن اعتماد شروطها
                    المناسبة لخدماتها ومستخدميها والقوانين السارية في نطاقها.
                </div>

                <h4 class="mt-4"><i class="fas fa-check-circle me-2 text-primary"></i>القبول بالشروط</h4>
                <p>باستخدامك لخدمة <?= $escape($organizationName) ?>، فإنك توافق على الالتزام بهذه الشروط والأحكام. إذا كنت لا توافق على أي جزء منها، يرجى عدم استخدام الخدمة.</p>

                <h4 class="mt-4"><i class="fas fa-user me-2 text-primary"></i>حسابات المستخدمين</h4>
                <ul>
                    <li>يجب الحفاظ على سرية بيانات تسجيل الدخول.</li>
                    <li>عدم مشاركة كلمة المرور مع أي شخص.</li>
                    <li>إبلاغ مشغل النشر فوراً عند الشك في اختراق الحساب.</li>
                    <li>كل مستخدم مسؤول عن الأنشطة التي تتم من حسابه.</li>
                </ul>

                <h4 class="mt-4"><i class="fas fa-graduation-cap me-2 text-primary"></i>الاستخدام المقبول</h4>
                <p>يُسمح باستخدام النشر للأغراض التعليمية والإدارية التي يحددها مشغل المؤسسة.</p>

                <h4 class="mt-4"><i class="fas fa-ban me-2 text-danger"></i>الاستخدام المحظور</h4>
                <ul>
                    <li>محاولة الوصول غير المصرح به.</li>
                    <li>تعديل أو حذف بيانات الآخرين دون صلاحية.</li>
                    <li>نشر محتوى غير قانوني أو غير لائق.</li>
                    <li>محاولة اختراق النظام أو تعطيله.</li>
                </ul>

                <h4 class="mt-4"><i class="fas fa-copyright me-2 text-primary"></i>الملكية</h4>
                <p>EduCore برنامج مفتوح المصدر مرخص بموجب AGPL-3.0-only. أما البيانات والمحتوى والعلامات التجارية التي يضيفها المشغل فتظل خاضعة لحقوقه وشروطه الخاصة.</p>

                <h4 class="mt-4"><i class="fas fa-exclamation-triangle me-2 text-warning"></i>إخلاء المسؤولية</h4>
                <p>هذا القالب لا يمثل استشارة قانونية. يجب على كل مؤسسة مراجعة الشروط وتحديثها لتناسب خدماتها وولايتها القضائية وتكاملاتها.</p>

                <h4 class="mt-4"><i class="fas fa-gavel me-2 text-primary"></i>التعديلات</h4>
                <p>يجوز لمشغل النشر تعديل هذه الشروط عند الحاجة، مع إشعار المستخدمين وفق الإجراءات المعتمدة لديه.</p>

                <h4 class="mt-4"><i class="fas fa-envelope me-2 text-primary"></i>التواصل</h4>
                <p>للاستفسارات المتعلقة بهذا النشر:</p>
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
