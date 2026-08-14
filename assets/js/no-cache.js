/**
 * إعدادات مسح الكاش وتحسين الأداء للنظام
 * يتم تحميله في جميع الصفحات لضمان عدم تخزين البيانات مؤقتاً
 */

// تهيئة منع تخزين طلبات البيانات دون حذف تفضيلات المستخدم.
document.addEventListener('DOMContentLoaded', function () {
    // إضافة timestamp لجميع الطلبات AJAX لمنع التخزين المؤقت
    setupAjaxNoCaching();

    // منع تخزين الفورم مؤقتاً
    disableFormCaching();
});

// إعداد AJAX لمنع التخزين المؤقت
function setupAjaxNoCaching() {
    // jQuery إذا كان متوفراً
    if (typeof $ !== 'undefined') {
        $.ajaxSetup({
            cache: false,
            beforeSend: function (xhr, settings) {
                // إضافة timestamp لكل طلب
                var separator = settings.url.indexOf('?') !== -1 ? '&' : '?';
                settings.url += separator + '_nocache=' + new Date().getTime();
            }
        });
    }

    // Fetch API
    const originalFetch = window.fetch;
    window.fetch = function (url, options = {}) {
        // إضافة headers لمنع التخزين المؤقت
        options.headers = options.headers || {};
        options.headers['Cache-Control'] = 'no-cache, no-store, must-revalidate';
        options.headers['Pragma'] = 'no-cache';
        options.headers['Expires'] = '0';

        // إضافة timestamp للـ URL
        if (typeof url === 'string') {
            const separator = url.indexOf('?') !== -1 ? '&' : '?';
            url += separator + '_nocache=' + new Date().getTime();
        }

        return originalFetch(url, options);
    };
}

// منع تخزين الفورم مؤقتاً
function disableFormCaching() {
    // إضافة autocomplete="off" لجميع الفورم
    const forms = document.querySelectorAll('form');
    forms.forEach(function (form) {
        form.setAttribute('autocomplete', 'off');

        // منع التخزين المؤقت للـ inputs
        const inputs = form.querySelectorAll('input, select, textarea');
        inputs.forEach(function (input) {
            input.setAttribute('autocomplete', 'off');
        });
    });
}

// منع استخدام زر الرجوع للصفحات المحمية
window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
        // إذا تم تحميل الصفحة من الكاش، إعادة تحميلها
        window.location.reload();
    }
});

// إعدادات أمان إضافية
(function () {
    // منع النقر بالزر الأيمن (اختياري)
    // document.addEventListener('contextmenu', function(e) {
    //     e.preventDefault();
    // });

    // منع F12 للمطورين (اختياري)
    // document.addEventListener('keydown', function(e) {
    //     if (e.key === 'F12' || (e.ctrlKey && e.shiftKey && e.key === 'I')) {
    //         e.preventDefault();
    //     }
    // });
})();
