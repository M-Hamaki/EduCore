/**
 * DataTables Arabic (العربية) language configuration
 * This file provides complete Arabic translation for DataTables
 */

window.DataTablesArabic = {
    "language": {
        "search": "البحث:",
        "lengthMenu": "عرض _MENU_ مدخلات",
        "info": "عرض _START_ إلى _END_ من أصل _TOTAL_ مدخل",
        "infoEmpty": "عرض 0 إلى 0 من أصل 0 مدخل",
        "infoFiltered": "(منقح من _MAX_ مدخل إجمالي)",
        "loadingRecords": "جاري التحميل...",
        "zeroRecords": "لم يتم العثور على أي سجلات مطابقة",
        "emptyTable": "لا توجد بيانات متاحة في الجدول",
        "processing": "جاري المعالجة...",
        "paginate": {
            "first": "الأول",
            "last": "الأخير",
            "next": "التالي",
            "previous": "السابق"
        },
        "aria": {
            "sortAscending": ": تنشيط لترتيب العمود تصاعديًا",
            "sortDescending": ": تنشيط لترتيب العمود تنازليًا"
        },
        "select": {
            "rows": {
                "_": "تم تحديد %d صفوف",
                "0": "لا توجد صفوف محددة",
                "1": "تم تحديد صف واحد"
            }
        },
        "buttons": {
            "copy": "نسخ",
            "copyTitle": "نسخ إلى الحافظة",
            "copySuccess": {
                "_": "تم نسخ %d صفوف",
                "1": "تم نسخ صف واحد"
            },
            "print": "طباعة",
            "excel": "Excel",
            "pdf": "PDF",
            "colvis": "إظهار/إخفاء الأعمدة"
        },
        "decimal": ".",
        "thousands": ","
    }
};

window.EduCoreDataTableDefaults = {
    pageLength: 50,
    lengthMenu: [[10, 25, 50, 100, 200, 500, -1], [10, 25, 50, 100, 200, 500, 'الكل']]
};

if (window.jQuery && window.jQuery.fn && window.jQuery.fn.dataTable) {
    window.jQuery.extend(true, window.jQuery.fn.dataTable.defaults, window.EduCoreDataTableDefaults);
}
