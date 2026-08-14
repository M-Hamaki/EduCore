# نموذج البيانات: بوابة الوصول الموحدة

> **تعديل نهائي في 2026-08-11:** ألغي وضع الضيف وإعداد `public_portal_services` قبل الإطلاق. لا يقرأ التنفيذ هذا المفتاح ولا يحتاج migration. الوصول المجهول يستخدم نفس `materials.enabled` و`materials.downloadable` ونشاط المرحلة/الصف. الأقسام التي تصف إعداد خدمات الضيف أدناه سجل تخطيطي تاريخي وليست نموذج التنفيذ الحالي.

## المبادئ

- إضافي وغير مدمر وقابل للتراجع.
- default-deny للخدمات والمواد، عدا تمكين خدمة المواد افتراضياً حسب قرار الإطلاق.
- لا تخزين لرموز Microsoft أو كلمات مرور أو سبب تعطيل مكرر.
- الحساب والجلسة وسبب التعطيل تظل في ملاكها الحاليين.
- لا تعديل schema وقت الطلب.

## 1. `public_portal_services`

يمثل اختيار الأدمن للخدمات المعروفة في `PublicServiceCatalog`، ولا يخزن URL أو HTML.

| الحقل | النوع المقترح | القيود | الغرض |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, auto increment | معرف داخلي |
| `service_key` | VARCHAR(64) | UNIQUE, NOT NULL | مفتاح ثابت مثل `materials` |
| `is_enabled` | TINYINT(1) | NOT NULL DEFAULT 0 | الإتاحة العامة |
| `display_order` | INT UNSIGNED | NOT NULL DEFAULT 100 | ترتيب العرض |
| `lock_version` | INT UNSIGNED | NOT NULL DEFAULT 1 | منع فقد تعديل متزامن |
| `created_at` | DATETIME | NOT NULL | الإنشاء |
| `updated_at` | DATETIME | NOT NULL | آخر تعديل |
| `updated_by` | معرف مستخدم متوافق | NULL/FK عند ملاءمة schema | آخر أدمن |

### قواعد

- لا يقبل `service_key` غير مسجل في كتالوج الكود حتى لو وجد صف يدوي في الجدول.
- لا تُحذف الخدمة من واجهة الأدمن؛ تُعطّل لتبقى قابلية التدقيق والتراجع.
- seed `materials` مفعلاً عند الإطلاق النهائي، بعد اختبار سياسة النشر العام.

## 2. نشر المواد العامة

### المسار A — إعادة استخدام الموجود

إذا أثبت الفحص وجود حقل/جدول حالي بمعنى صريح «منشور للضيف العام» وعقد تنزيل يفرضه، يُعاد استخدامه ولا تنشأ بنية جديدة.

### المسار B — `public_material_publications`

يستخدم فقط عند عدم وجود تمثيل مطابق. لا ينسخ بيانات أو ملفات المادة.

| الحقل | النوع المقترح | القيود | الغرض |
|---|---|---|---|
| `id` | BIGINT UNSIGNED | PK, auto increment | معرف النشر |
| `material_id` | نوع معرف المادة الحالي | UNIQUE, NOT NULL | مرجع للمادة |
| `is_published` | TINYINT(1) | NOT NULL DEFAULT 0 | إتاحة عامة صريحة |
| `published_at` | DATETIME | NULL | وقت النشر الحالي |
| `published_by` | معرف مستخدم متوافق | NULL/FK عند الملاءمة | الأدمن الناشر |
| `lock_version` | INT UNSIGNED | NOT NULL DEFAULT 1 | التزامن |
| `created_at` | DATETIME | NOT NULL | الإنشاء |
| `updated_at` | DATETIME | NOT NULL | التعديل |

### قواعد

- المادة غير المذكورة أو `is_published=0` خاصة للضيف.
- publication لا يتجاوز حالة المادة أو وجود الملف أو سياسات مواد أخرى.
- حذف/تعطيل المادة في مالكها يجعلها غير متاحة فوراً حتى لو بقي projection.
- لا يخزن المسار في جدول النشر؛ يُقرأ عبر `MaterialCatalogQuery`.

## 3. كتالوج الخدمات في الكود

ليس جدولاً قابلاً للتعديل الحر. لكل مفتاح:

| الخاصية | مثال المواد |
|---|---|
| `key` | `materials` |
| label RTL | `تحميل المواد الدراسية` |
| icon | مفتاح أيقونة معتمد |
| route name | `materials` |
| policy | `public_materials` |
| supports direct link | نعم |

إضافة خدمة جديدة لاحقاً تتطلب كوداً وعقد صلاحية واختبارات، ثم migration/seed عند الحاجة.

## 4. مؤشر فيديو المقدمة

لا يحتاج جدول قاعدة بيانات في هذا الإصدار.

### Cookie

- اسم مقترح: `educore_intro_seen_at`.
- قيمة: timestamp صالح ومتحقق منه، بلا معرف مستخدم.
- Max-Age: 15 يوماً.
- `SameSite=Lax`، و`Secure` في HTTPS، و`HttpOnly` إذا لم يحتج JavaScript إلى تحديثه.
- قيمة غير صالحة تعامل كأول زيارة، مع حماية من الحلقة عبر session.

### Session fallback

- مفتاح متوافق/جديد مثل `intro_shown_this_session`.
- يمنع تكرار التحويل عند حظر cookie.

## 5. هوية Microsoft والحساب المحلي

لا ينشأ جدول جديد. تُقرأ الحقول الحالية:

- Microsoft object ID المرتبط.
- `email` المحلي.
- `username` المحلي.
- حالة الحساب وسبب التعطيل.
- الدور والحقول اللازمة لإنشاء الجلسة الحالية.

### invariant الدخول الصامت

```text
valid_token
AND one_local_account
AND linked_microsoft_id == token_subject/object_id
AND normalize(verified_microsoft_email) == normalize(local_email)
AND normalize(verified_microsoft_email) == normalize(local_username)
AND account_is_active
AND role_is_valid
```

لا تُكتب بيانات حساب أثناء هذا التحقق.

## 6. سجل التدقيق

الكيانات المقترحة للتسجيل في policy registry المشتركة:

- `public_portal_service_setting`
- `public_material_publication` عند استخدام المسار B أو كيان النشر الحالي عند المسار A.

### redaction

- لا رموز OAuth/Teams.
- لا cookies أو session IDs.
- لا كلمات مرور أو hashes.
- before/after يقتصر على مفاتيح الخدمة، الحالة، الترتيب، المادة، وحالة النشر.

### undo

- تفعيل/تعطيل/ترتيب الخدمة: مؤهل للتراجع مع optimistic conflict check.
- نشر/إلغاء نشر مادة: مؤهل إذا كانت المادة لا تزال موجودة ولم تتغير بعد العملية.
- لا undo لأي session login أو تحقق token.

## migrations والتراجع

- Migration 1: إنشاء `public_portal_services` وفهرس unique وseed معروف.
- Migration 2: إنشاء projection نشر المواد فقط إذا فشل مسار إعادة الاستخدام.
- Down migration للاختبار المعزول فقط؛ في الإنتاج rollback الأول يعطل الأعلام ولا يسقط البيانات.
- لا migration يغيّر أسماء حقول المستخدم أو Microsoft أو سبب التعطيل.
