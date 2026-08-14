/**
 * Air Datepicker — Central Initialization
 * -----------------------------------------
 * التهيئة المركزية لحامل التاريخ Air Datepicker v3.5.0 المستخدم في كامل النظام.
 * المرجع الأصلي: admin/calculation_tools.php
 *
 * قواعد الاستخدام:
 *  - أي حقل تاريخ يُعطى الكلاس `flatpickr-date` سيُهيّأ تلقائياً.
 *  - الحقول المُحقنة ديناميكياً تُهيّأ باستدعاء `initAirDatepickers(scopeElement)`.
 *  - تُحفظ سمات `max` / `min` على الحقل وتُترجَم إلى `maxDate` / `minDate`.
 *  - عند الاختيار يُطلَق حدث `change` أصلي (bubbles) لضمان عمل `onchange="..."`
 *    ومستمعي `addEventListener('change', ...)` القائمين في الصفحات.
 */
(function () {
    'use strict';

    var datepickerInstances = new WeakMap();

    // Arabic translation object for Air Datepicker v3
    var arabicLocale = {
        days: ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'],
        daysShort: ['أحد', 'اثنين', 'ثلاثاء', 'أربعاء', 'خميس', 'جمعة', 'سبت'],
        daysMin: ['أح', 'اث', 'ثل', 'أر', 'خم', 'جم', 'سب'],
        months: ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'],
        monthsShort: ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'],
        today: 'اليوم',
        clear: 'مسح',
        dateFormat: 'yyyy-MM-dd',
        timeFormat: 'hh:mm aa',
        firstDay: 6
    };

    // اقرأ قيود التاريخ من سمات الحقل (max/min) لتمريرها إلى Air Datepicker
    function readDateAttr(el, attr) {
        var raw = el.getAttribute(attr);
        if (!raw) return undefined;
        // تحويل yyyy-mm-dd إلى كائن Date عند الإمكان
        var parts = String(raw).split('-');
        if (parts.length === 3) {
            var d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
            return isNaN(d.getTime()) ? undefined : d;
        }
        return undefined;
    }

    /**
     * يهيّئ كل حقول `.flatpickr-date` داخل النطاق المُعطى.
     * @param {Element|Document} scope - العنصر الحاوي (document افتراضياً).
     * @param {Object} [extraOptions] - خيارات إضافية تُمرَّر لكل منتقي.
     */
    function initAirDatepickers(scope, extraOptions) {
        if (typeof AirDatepicker === 'undefined') return;
        scope = scope || document;
        var nodes = scope.querySelectorAll ? scope.querySelectorAll('.flatpickr-date:not([data-air-datepicker-init])') : [];
        Array.prototype.forEach.call(nodes, function (el) {
            var opts = {
                locale: arabicLocale,
                dateFormat: 'yyyy-MM-dd',
                autoClose: true,
                maxDate: readDateAttr(el, 'max'),
                minDate: readDateAttr(el, 'min')
            };
            if (extraOptions && typeof extraOptions === 'object') {
                opts = mergeOptions(opts, extraOptions);
            }
            var instance = new AirDatepicker(el, opts);
            datepickerInstances.set(el, instance);
            el.setAttribute('data-air-datepicker-init', '1');
        });
    }

    function parseDateValue(value) {
        if (value instanceof Date) {
            return isNaN(value.getTime()) ? null : new Date(value.getFullYear(), value.getMonth(), value.getDate());
        }
        var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
        if (!match) return null;
        var year = parseInt(match[1], 10);
        var month = parseInt(match[2], 10) - 1;
        var day = parseInt(match[3], 10);
        var date = new Date(year, month, day);
        if (date.getFullYear() !== year || date.getMonth() !== month || date.getDate() !== day) {
            return null;
        }
        return date;
    }

    function formatDateValue(date) {
        return date.getFullYear() + '-'
            + String(date.getMonth() + 1).padStart(2, '0') + '-'
            + String(date.getDate()).padStart(2, '0');
    }

    /**
     * يحدّث قيمة حقل التاريخ والحالة الداخلية للمنتقي معاً.
     * استخدمه بدلاً من تعيين input.value مباشرة عند تعبئة الحقول برمجياً.
     * @param {HTMLInputElement|string} inputOrId - الحقل أو معرّفه.
     * @param {Date|string|null} value - تاريخ بصيغة yyyy-MM-dd أو كائن Date أو قيمة فارغة للمسح.
     * @param {Object} [options] - مرّر dispatchChange=false لمنع حدث change.
     * @returns {boolean} نجاح المزامنة.
     */
    function setAirDatepickerValue(inputOrId, value, options) {
        var input = typeof inputOrId === 'string' ? document.getElementById(inputOrId) : inputOrId;
        if (!input) return false;

        var isEmpty = value === null || value === undefined || value === '';
        var date = isEmpty ? null : parseDateValue(value);
        if (!isEmpty && !date) return false;

        var instance = datepickerInstances.get(input);
        if (instance) {
            if (date) {
                instance.selectDate(date, { silent: true });
                instance.setViewDate(date);
            } else {
                instance.clear({ silent: true });
            }
        }

        input.value = date ? formatDateValue(date) : '';
        if (!options || options.dispatchChange !== false) {
            dispatchChange(input);
        }
        return true;
    }

    // دمج بسيط للخيارات (الخيارات الإضافية لها الأولوية لكن onSelect يُدمج)
    function mergeOptions(base, extra) {
        var out = {};
        Object.keys(base).forEach(function (k) { out[k] = base[k]; });
        Object.keys(extra).forEach(function (k) { out[k] = extra[k]; });
        // دمج onSelect بحيث يُطلِق change أصلي ثم يُنفّذ onSelect المخصص إن وُجد
        if (extra.onSelect) {
            var userOnSelect = extra.onSelect;
            out.onSelect = function (data) {
                dispatchChange(elForData(data));
                userOnSelect(data);
            };
        } else {
            out.onSelect = function (data) { dispatchChange(elForData(data)); };
        }
        return out;
    }

    function elForData(data) {
        return (data && data.datepicker && data.datepicker.$el) || null;
    }

    function dispatchChange(el) {
        if (!el) return;
        var event = new Event('change', { bubbles: true });
        el.dispatchEvent(event);
    }

    /**
     * يمسح قيمة حقل تاريخ ويُطلِق حدث change.
     * @param {string} inputId - معرّف الحقل.
     */
    function clearDateInput(inputId) {
        var input = document.getElementById(inputId);
        if (!input) return;
        setAirDatepickerValue(input, '');
        // دعم الحالات الخاصة بالمحول الهجري في calculation_tools.php
        if (inputId === 'hij_date_picker') {
            var g = document.getElementById('greg_result_txt');
            if (g) g.innerText = "-";
        } else if (inputId === 'greg_to_conv') {
            var h = document.getElementById('hijri_result_txt');
            if (h) h.innerText = "-";
        }
    }

    // كشف الواجهة العامة
    window.AirDatepickerArabicLocale = arabicLocale;
    window.initAirDatepickers = initAirDatepickers;
    window.setAirDatepickerValue = setAirDatepickerValue;
    window.clearDateInput = clearDateInput;

    // تهيئة تلقائية عند تحميل DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initAirDatepickers(document); });
    } else {
        initAirDatepickers(document);
    }
})();
