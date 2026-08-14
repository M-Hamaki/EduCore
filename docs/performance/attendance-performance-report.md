# تقرير تشخيص بطء فتح صفحة `attendance.php` + خطة الحل

> تاريخ التشخيص: 2026-07-11
> النطاق: `admin/attendance.php` (وامتداد لكل صفحات الأدمن عبر `includes/admin_header.php`)
> المنهج: فحص فعلي + قياس مباشر + `EXPLAIN` على قاعدة البيانات، لا افتراضات.

---

## 1) الأعراض

عند فتح `admin/attendance.php` (واجهة الحضور والغياب) تتأخر الصفحة بشكل ملحوظ في التحميل والظهور، حتى في الحالة الافتراضية (التبويب "تسجيل الحضور" بدون أي فلتر).

---

## 2) ما الذي **لا** يسبب البطء (تم التحقق منه بالأدلة)

استبعدنا الأسباب الشائعة لأنها بريئة هنا:

| العنصر | الدليل |
|---|---|
| حجم جدول `attendance` | **47 صف فقط** في قاعدة البيانات الحالية |
| الفهارس | ممتازة — 7 فهارس: `PRIMARY`, `idx_date`, `idx_class_date`, `idx_student`, `idx_attendance_date_status_class`, `idx_attendance_academic_year`, ... |
| كفاءة الاستعلام الرئيسي | `EXPLAIN` على استعلام الإحصائيات الافتراضي يُظهر `type: range` + `key: idx_date` + `Using index condition` |
| استعلامات N+1 | غير موجودة في التحميل الافتراضي (`view=record`) |
| AJAX على تحميل الصفحة | **لا يوجد** — لا `$.ajax` ولا `fetch` على `DOMContentLoaded` |

> **الخلاصة:** قاعدة البيانات بريئة تماماً. البطء من جهة الخادم (PHP includes) ومن جهة المتصفح (موارد CDN).

---

## 3) الأسباب الحقيقية (مرتبة بالأثر)

### 🔴 السبب 1: تحميل `vendor/autoload.php` (Dompdf بالكامل) على كل طلب

**أكبر أثر على الخادم — ~73ms لكل فتح صفحة، كلها هدر عدا التصدير.**

`admin/attendance.php:21` يحمل `classes/pdf_handler.php` بشكل غير مشروط:
```php
require_once '../classes/pdf_handler.php';   // السطر 21 — يُنفّذ في كل طلب
```

وداخل `classes/pdf_handler.php:6`:
```php
require_once __DIR__ . '/../vendor/autoload.php';   // مجلد vendor كامل (56MB، Dompdf محرك ضخم)
```

**القياس المباشر (تم بتاريخ التشخيص):**
```
vendor autoload: 73.4 ms   ← على كل طلب
```

المفارقة: منطق تصدير PDF لا يعمل إلا داخل:
```php
// admin/attendance.php:421
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $pdf = new PdfHandler($db);   // السطر 422 — المرجع الوحيد لـ PdfHandler في الملف
    ...
}
```
أي أن `require_once` يركض في **كل** فتح صفحة، لكنّ `new PdfHandler` لا يركض إلا عند `?export=pdf`. فحص كامل للملف أكّد أن `PdfHandler` لا يُذكر في أي مكان آخر خارج هذه الكتلة.

**تأثير مماثل في:** `admin/evaluation_reports.php:14` (نفس النمط، تحميل غير مشروط، استخدام داخل كتلة تصدير في السطر 110).

---

### 🔴 السبب 2: موارد CDN خارجية بدون `preconnect` (أكبر أثر على المتصفح)

`includes/admin_header.php` يحمّل **6 موارد خارجية مترابطة** قبل ظهور الصفحة:

| السطر | المورد | النطاق |
|---|---|---|
| 94 | `bootstrap.rtl.min.css` | `cdn.jsdelivr.net` |
| 96 | `dataTables.bootstrap5.min.css` | `cdn.datatables.net` |
| 98 | `font-awesome all.min.css` | `cdnjs.cloudflare.com` |
| 100-101 | خط Tajawal (6 أوزان!) | `fonts.googleapis.com` |
| 111 | `jquery-3.7.1.min.js` (في `<head>`، **يحجب الترتيب**) | `code.jquery.com` |

وفي `includes/admin_footer.php` يُضاف: SweetAlert2 + SortableJS + DataTables JS (كلها من CDN).

**المشكلتان البارزتان:**
- **لا يوجد أي `preconnect` أو `dns-prefetch`** لأي من النطاقات الأربعة → المتصفح ينتظر DNS+TLS لكل واحد على حدة.
- خط Tajawal يطلب **6 أوزان (300;400;500;700;800;900)** وهذا تنزيل كبير.

---

### 🟡 السبب 3: `no_cache.php` يُعطّل التخزين المؤقت بالكامل

`includes/no_cache.php` يُرسل على كل استجابة:
```
Cache-Control: no-cache, no-store, must-revalidate, max-age=0
Pragma: no-cache
Expires: Thu, 01 Jan 1970 00:00:00 GMT
ETag: "<random md5 of microtime>"   ← عشوائي في كل مرة => يُلغي التخزين تماماً
Vary: *
```
النتيجة: يُعاد تنزيل كل موارد CDN وكل JS محلية في كل تنقّل بين الصفحات. مناسب للتطوير، لكنه يضيف ثوانٍ على كل فتح.

---

### 🟡 السبب 4: صفحة ثقيلة DOM

`admin/attendance.php` = 1844 سطر / 97KB: شريط جانبي + 6 تبويبات + 4 فلاتر cascade + DataTables. ليس السبب الرئيسي، لكنه يضيف وقع بطء بعد تنزيل الموارد.

---

## 4) الحلول

> **القرار:** نطاق "الآمن فقط" — لا نُغيّر سلوك jQuery (لا `defer`)، لا نُغيّر سياسة التخزين المؤقت، لا نقل أي CDN إلى ملفات محلية، لا نُغيّر استعلامات الحضور أو الفهارس. كل التغييرات آمنة على كل صفحات الأدمن.

---

### ✅ الحل 1: تحميل PdfHandler/Dompdf فقط عند تصدير PDF فعلياً

**الملف:** `admin/attendance.php`

**الإجراء 1-A — احذف السطر 21:**
```php
require_once '../classes/pdf_handler.php';   // ← احذف هذا السطر بالكامل
```

**الإجراء 1-B — أضف `require_once` داخل كتلة التصدير (قبل السطر 422):**

السطر 421 حالياً:
```php
// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $pdf = new PdfHandler($db);
```

اجعله:
```php
// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    require_once '../classes/pdf_handler.php';
    $pdf = new PdfHandler($db);
```

**الملف:** `admin/evaluation_reports.php`

**الإجراء 1-C — احذف السطر 14:**
```php
require_once '../classes/pdf_handler.php';   // ← احذف هذا السطر بالكامل
```

**الإجراء 1-D — أضف `require_once` داخل كتلة التصدير (السطر 108-110):**

حالياً:
```php
if (isset($_GET['export']) && $_GET['export'] == 'pdf') {
    // ...
    $pdf_handler = new PdfHandler($db);
```

اجعله:
```php
if (isset($_GET['export']) && $_GET['export'] == 'pdf') {
    require_once '../classes/pdf_handler.php';
    // ...
    $pdf_handler = new PdfHandler($db);
```

> **لماذا هذا آمن؟** `require_once` لا يُحمّل المكتبة مرتين أبداً مهما كرّرناها، و`new PdfHandler` هو الاستخدام الوحيد في كلا الملفين (مؤكد بالفحص الكامل). نُحرّك `require_once` من "كل طلب" إلى "تصدير فقط" → توفير ~73ms على كل فتح صفحة عادي.

**الأثر المتوقع:** توفير ~70ms من زمن استجابة الخادم في كل فتح صفحة (وليس فقط التصدير).

---

### ✅ الحل 2: إضافة `preconnect` للنطاقات الأربعة

**الملف:** `includes/admin_header.php`

**الإجراء:** أضف هذه الكتلة **فقط قبل السطر 94** (قبل أول `<link>` لـ CDN):

```html
    <!-- Performance: Preconnect to external CDN origins -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
```

> **لماذا هذا آمن؟** `preconnect` مجرد تلميح (hint) للمتصفح لإقامة اتصال DNS+TLS+TCP مبكراً. لا يُغيّر أي منطق، لا يُنزّل أي شيء إضافي، ولا يُكسر أي تحميل موجود. تأثيره فقط إزالة زمن DNS+TLS من المسار الحرج للتحميل. ينطبق تلقائياً على كل صفحات الأدمن.

**الأثر المتوقع:** تقليل 100-300ms من زمن بدء تنزيل أول CSS خارجي (يعتمد على الشبكة).

---

### ✅ الحل 3: تصحيح أوزان خط Tajawal

**الملف:** `includes/admin_header.php` — السطر 100-101

**الإجراء:** عدّل رابط خط Tajawal.

**حالياً (السطر 100-101):**
```html
    <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&display=swap">
```

**اجعله:**
```html
    <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700;800;900&display=swap">
```

> **لماذا هذا التصحيح تحديداً؟** فحصُ الـ CSS المحلي (`style.css` + `premium-dashboard.css` + `buttons.css` + `admin-unified.css`) أظهر الأوزان المستخدمة فعلياً:
> - `400` (normal) — مطلوب
> - `500` — كثير الاستخدام في `style.css`
> - `600` — **كثير الاستخدام لكنه غير محمّل أصلاً!** (Bootstrap يطلبه عبر `fw-semibold`، و`style.css` يستخدمه صراحةً)
> - `700` — مطلوب (`fw-bold`)
> - `800`, `900` — مطلوبان في `premium-dashboard.css` للعناوين
> - `300` — **غير مستخدم في أي مكان** → يُحذف
>
> إذن هذا التصحيح يضيف وزناً مفقوداً مهماً (600) ويحذف وزناً غير مستخدم (300)، **دون زيادة عدد الأوزان** (يبقى 6).

---

## 5) ما **لن** نلمسه (وفق اختيار النطاق الآمن)

- ❌ لا `defer`/`async` على jQuery أو أي سكربت (يتطلب فحص ~25 صفحة، بعضها فيه استدعاءات jQuery على المستوى الأعلى).
- ❌ لا تغيير على `includes/no_cache.php` أو سياسة التخزين المؤقت.
- ❌ لا تغيير على استعلامات الحضور أو الفهارس (قاعدة البيانات سريعة أصلاً).
- ❌ لا نقل أي CDN إلى ملفات محلية.
- ❌ لا تغيير على `teacher/attendance.php` (لا يحمل `pdf_handler` أصلاً — فُحص).
- ❌ لا تغيير على Font Awesome — مطلوب لـ `<i class="fas fa-*">` ولأسهم فرز DataTables في `premium-dashboard.css:570-601` (باستخدام `font-family: "Font Awesome 6 Free"`).

---

## 6) خطوات التنفيذ والتحقق ( checklist )

نفّذ بالترتيب:

- [ ] **1-A.** `admin/attendance.php`: حذف `require_once '../classes/pdf_handler.php';` (السطر 21).
- [ ] **1-B.** `admin/attendance.php`: إضافة `require_once '../classes/pdf_handler.php';` داخل كتلة `if (isset($_GET['export']) && $_GET['export'] === 'pdf')` قبل `new PdfHandler($db)`.
- [ ] **1-C.** `admin/evaluation_reports.php`: حذف `require_once '../classes/pdf_handler.php';` (السطر 14).
- [ ] **1-D.** `admin/evaluation_reports.php`: إضافة `require_once '../classes/pdf_handler.php';` داخل كتلة `if (isset($_GET['export']) && $_GET['export'] == 'pdf')` قبل `new PdfHandler($db)`.
- [ ] **2.** `includes/admin_header.php`: إضافة كتلة `preconnect` الأربعة قبل السطر 94.
- [ ] **3.** `includes/admin_header.php`: تعديل رابط Tajawal في السطر 100-101 (إزالة 300، إضافة 600).
- [ ] **التحقق النحوي:**
      ```
      php -l admin/attendance.php
      php -l admin/evaluation_reports.php
      php -l includes/admin_header.php
      ```
      كلها يجب أن تُرجع `No syntax errors detected`.
- [ ] **اختبار يدوي:**
      - افتح `admin/attendance.php` عاديًا → يجب أن تفتح أسرع وبدون أخطاء.
      - جرّب تصدير PDF من أحد التبويبات (`?view=...&export=pdf`) → يجب أن يعمل التصدير.
      - افتح `admin/evaluation_reports.php` عاديًا، ثم جرّب تصدير PDF → يجب أن يعمل.
      - تأكد بصرياً من: ظهور الأيقونات (fas fa-*)، سُمك العناوين، أسهم فرز جداول DataTables.
- [ ] **اختبار النطاق العريض:** افتح 2-3 صفحات أدمن أخرى (مثل `students.php`, `fee_payments.php`) للتأكد من أن تغييرات `admin_header.php` لم تكسر شيئاً.

---

## 7) ملخص الأثر المتوقع

| التغيير | الأثر | أين يظهر |
|---|---|---|
| تحميل PdfHandler داخل كتلة التصدير فقط | توفير **~70ms** لكل فتح صفحة | خادم (PHP) |
| `preconnect` لـ 4 نطاقات | تقليل **100-300ms** من بدء تنزيل CSS | متصفح |
| تصحيح أوزان Tajawal | إضافة وزن 600 المفقود + إزالة 300 الزائد | جودة عرض + توفير طفيف |

كل التغييرات آمنة، لا تلمس منطقاً وظيفياً، وتنطبق على كل صفحات الأدمن (لأنها في `admin_header.php`) ما عدا تغيير PdfHandler الذي يخصّ الملفين فقط.

---

## 8) ملاحق — قراءات إضافية مؤجّلة (خارج النطاق الآمن)

إذا رغبت لاحقاً بتسريع أكبر، هذه الخيارات مرتّبة بالأثر/الخطر:

1. **`defer` على jQuery والسكربتات الخارجية** — يتطلب فحص ~25 صفحة (معظمها ملفوف بـ `ready`/`DOMContentLoaded` لكن فيها استثناءات مثل `admin/teacher_evaluations.php:575`). أكبر أثر على المتصفح لكنه مخاطرة متوسطة.
2. **تخفيف `no_cache.php`** — جعله يُفعّل `no-store` فقط في وضع التطوير (dev)، ويترك التخزين الطبيعي في الإنتاج.
3. **استضافة Font Awesome + jQuery + Bootstrap محلياً** — مفيد جداً إذا كان الخادم محلياً (XAMPP) والإنترنت بطيئاً، لأن CDN سيكون أبطأ من ملف محلي على `localhost`.
4. **دمج/ضغط CSS المحلي** (`style.css` + `premium-dashboard.css` + `buttons.css` + `admin-unified.css`) في ملف واحد minified.

---

> نهاية التقرير. هذا الملف جاهز للتنفيذ اليدوي لاحقاً دون الحاجة لعودة للتحقق.
