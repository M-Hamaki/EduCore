<?php
/**
 * This diagnostic endpoint used to print student credentials.
 * It is intentionally disabled because passwords are no longer reversible.
 */

http_response_code(410);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>الميزة متوقفة</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 40px; background: #f8fafc; color: #1f2937; }
        .box { max-width: 720px; margin: auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; }
    </style>
</head>
<body>
    <div class="box">
        <h1>تم تعطيل عرض بيانات الاعتماد</h1>
        <p>كلمات المرور لا تُعرض ولا تُنسخ إلى أنظمة أخرى. استخدم اسم المستخدم للربط مع تقارير الطلاب، وأعد تعيين كلمة المرور عند الحاجة.</p>
    </div>
</body>
</html>
