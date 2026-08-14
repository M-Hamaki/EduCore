# عقد الخدمات العامة والمواد — ملغى

> **الحالة في 2026-08-11:** ألغي هذا العقد بطلب المستخدم. لا يوجد كتالوج أو إعداد أو دور أو صفحة خدمات ضيف. `guest.php` تحويل توافق فقط إلى `materials.php`، والوصول المجهول الوحيد هو واجهة `student/materials/` وموادها المفعّلة. التفاصيل أدناه تاريخية ولا تنطبق على التنفيذ الحالي.

## `PublicServiceCatalog`

الكتالوج هو المصدر الوحيد للخدمات التي يمكن جعلها عامة.

```php
// شكل مفاهيمي، وليس توقيعاً ملزماً قبل فحص conventions الموجودة.
[
    'materials' => [
        'label' => 'تحميل المواد الدراسية',
        'route_name' => 'materials',
        'policy' => 'public_materials',
        'direct_link' => true,
    ],
]
```

### invariants

- لا URL أو HTML من قاعدة البيانات.
- صف مجهول في قاعدة البيانات لا يصبح خدمة.
- route name يُحل عبر mapping داخلي.
- كل خدمة جديدة تحتاج policy واختبارات وتسجيل إعداد.

## `PublicPortalRepository`

مسؤول عن إعدادات الخدمات فقط.

### عمليات القراءة

- `getEnabledServicesInDisplayOrder()`
- `getServiceSetting(string $serviceKey)`
- `isEnabled(string $serviceKey)`

### عمليات الكتابة

- `updateService(string $key, bool $enabled, int $order, int $expectedVersion, int $actorId)`
- تجرى داخل transaction يملكها application service مع audit.

## `MaterialCatalogQuery`

عقد قراءة بين PublicPortal ومالك المواد، يمنع الوصول إلى internals مباشرة.

### بيانات الإخراج العامة فقط

- معرف المادة.
- عنوان منقى للعرض.
- وصف منقى عند السماح.
- المرحلة/الصف/المادة الدراسية اللازمة للفلاتر العامة.
- نوع الملف وحجمه عند السماح.
- حالة المادة ووجود الملف كمعلومات قرار داخلية، لا مسار filesystem في DTO العام.

### العمليات

- `listPublicCandidates(filters, pagination)` للإدارة/الاختيار وفق صلاحيتها.
- `getActiveMaterialById(id)` لإعادة فحص وجود المادة وحالتها.
- لا يعيد رابط تنزيل مباشر غير محكوم.

## `GuestAccessPolicy`

```text
allow(serviceKey) = catalog.knows(serviceKey)
                    AND repository.isEnabled(serviceKey)
                    AND serviceSpecificPolicyAllows
```

- تُستدعى من صفحة القائمة ومن endpoint الخدمة والتنزيل.
- لا تعتمد على عنصر مخفي في HTML.

## `PublicMaterialPublicationQuery`

- `isPublished(materialId)`
- `listPublishedMaterialIds(filters, pagination)`
- `publish/unpublish` في application service الإداري فقط.

إذا كان مالك المواد يملك عقداً مطابقاً، يُستخدم اسمه الحالي ولا ينشأ هذا العقد.

## عقد عرض بطاقة الدخول

البيانات التي يسمح controller بتمريرها إلى `includes/public_login_portal.php`:

```text
csrfToken
flashMessage (escaped)
microsoftLoginUrl (internal)
guestUrl (internal or null)
materialsUrl (internal or null)
isTeamsContext (boolean)
```

- لا تمرر stage names.
- لا تمرر secrets أو token.
- إذا كانت المواد معطلة يكون `materialsUrl=null` ولا يظهر الرابط.
- المدخلات المعروضة تُهرب عبر `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
