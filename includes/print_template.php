<?php
/**
 * قالب الطباعة الموحد - يُستخدم في جميع صفحات النظام
 * يوفر هيدر وفوتر احترافي للطباعة مع CSS موحد
 * 
 * الاستخدام:
 * 1. في أعلى الصفحة: require_once '../includes/print_template.php';
 * 2. حدد المتغيرات المطلوبة قبل الاستدعاء:
 *    - $print_stage_id (int|null) — رقم المرحلة لتحديد مدير المرحلة تلقائياً
 *    - $print_stage_code (string|null) — أو كود المرحلة: 'kg', 'primary', 'preparatory', 'secondary'
 * 3. استدعاء الدوال:
 *    - print_header_html($title) — يولد HTML الهيدر (يظهر فقط عند الطباعة)
 *    - print_footer_html() — يولد HTML الفوتر
 *    - print_template_css() — يولد CSS الطباعة
 */

// جلب إعدادات الطباعة من قاعدة البيانات
function _get_print_settings($db = null)
{
    static $cache = null;
    if ($cache !== null)
        return $cache;

    try {
        if (!$db) {
            require_once __DIR__ . '/../config/database.php';
            $dbInstance = new Database();
            $db = $dbInstance->getConnection();
        }
        $keys = [
            'school_name',
            'school_logo',
            'educational_directorate',
            'educational_administration',
            'academic_year',
            'school_director',
            'kg_director',
            'primary_director',
            'prep_sec_director',
            'school_name_en',
            'educational_directorate_en',
            'educational_administration_en',
            'school_director_en',
            'kg_director_en',
            'primary_director_en',
            'prep_sec_director_en',
            'student_affairs_officer_en',
            'transport_movement_officer_en',
            'general_secretary_en',
            'accounts_manager_en'
        ];
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($placeholders)");
        $stmt->execute($keys);
        $cache = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Exception $e) {
        $cache = [];
    }
    return $cache;
}

/**
 * تحديد مدير المرحلة بناءً على stage_id أو stage_code
 */
function get_stage_director_name($stageId = null, $stageCode = null, $db = null, $lang = 'ar')
{
    $settings = _get_print_settings($db);

    $key = '';
    // تحديد من stage_code مباشرة
    if ($stageCode) {
        $code = strtolower(trim($stageCode));
        if ($code === 'kg' || $code === 'kindergarten') {
            $key = 'kg_director';
        } elseif ($code === 'primary') {
            $key = 'primary_director';
        } elseif (in_array($code, ['preparatory', 'prep', 'secondary', 'sec'])) {
            $key = 'prep_sec_director';
        }
    }

    // تحديد من stage_id عبر قاعدة البيانات
    if ($stageId && empty($key)) {
        try {
            if (!$db) {
                require_once __DIR__ . '/../config/database.php';
                $dbInstance = new Database();
                $db = $dbInstance->getConnection();
            }
            $stmt = $db->prepare("SELECT stage_code FROM stages WHERE id = ? LIMIT 1");
            $stmt->execute([$stageId]);
            $code = $stmt->fetchColumn();
            if ($code) {
                return get_stage_director_name(null, $code, $db, $lang);
            }
        } catch (Exception $e) {
            // fallback
        }
    }

    if ($key) {
        if ($lang === 'en') {
            $enKey = $key . '_en';
            if (!empty($settings[$enKey])) {
                return $settings[$enKey];
            }
            return translate_setting_to_en($key, $settings[$key] ?? '');
        }
        return $settings[$key] ?? '';
    }

    return '';
}

/**
 * دالة ذكية لترجمة إعدادات المطبوعات إلى اللغة الإنجليزية
 */
function translate_setting_to_en($key, $value)
{
    if (empty($value))
        return '';

    // خريطة ترجمة مطابقة تامة للقيم الافتراضية
    $exact_map = [
        'مديرة التربية والتعليم بالدقهلية' => 'Dakahlia Directorate of Education',
        'مديرية التربية والتعليم بالدقهلية' => 'Dakahlia Directorate of Education',
        'إدارة طلخا التعليمية' => 'Talkha Educational Administration',
        'مدرسة الدلتا الحديثة للغات' => 'Delta Modern Language School',
        'د. حسن قمح' => 'Dr. Hassan Qamh',
        'أ. منال محمد' => 'Mrs. Manal Mohamed',
        'أ. نيرفين أحمد' => 'Mrs. Nervin Ahmed',
        'أ. هالة هلال' => 'Mrs. Hala Helal',
        'أ. أماني محمد' => 'Mrs. Amani Mohamed',
        'أ. محمد مجدي' => 'Mr. Mohamed Magdy',
        'أ. محمد الهواري' => 'Mr. Mohamed El-Hawary',
        'أ. ميرفت خليفه' => 'Mrs. Mervat Khalifa',
        'أ. عمر عوض' => 'Mr. Omar Awad',
        'أ. عمر عبد الرحمن' => 'Mr. Omar Abdel-Rahman'
    ];

    if (isset($exact_map[$value])) {
        return $exact_map[$value];
    }

    // استبدال الأنماط الشائعة إذا لم تكن مطابقة تامة
    $translated = $value;

    // ترجمة الألقاب والأسماء الشائعة
    $translated = str_replace(
        ['أ. ', 'د. ', 'م. ', 'أحمد', 'محمد', 'علي', 'محمود', 'مصطفى', 'حسن', 'خالد', 'سعيد', 'فاطمة', 'زينب', 'منى', 'رانيا', 'هناء'],
        ['Mr. ', 'Dr. ', 'Eng. ', 'Ahmed', 'Mohamed', 'Ali', 'Mahmoud', 'Mustafa', 'Hassan', 'Khaled', 'Said', 'Fatma', 'Zainab', 'Mona', 'Rania', 'Hanaa'],
        $translated
    );

    // ترجمة الإدارات التعليمية
    if (strpos($value, 'إدارة') !== false && strpos($value, 'التعليمية') !== false) {
        $name = str_replace(['إدارة ', ' التعليمية'], '', $value);
        $admin_names = [
            'طلخا' => 'Talkha',
            'المعادي' => 'Maadi',
            'شرق' => 'East',
            'غرب' => 'West',
            'وسط' => 'Center',
            'المنصورة' => 'Mansoura'
        ];
        $translatedName = $admin_names[$name] ?? $name;
        return $translatedName . ' Educational Administration';
    }

    // ترجمة المديريات التعليمية
    if (strpos($value, 'مديرية') !== false || strpos($value, 'مديرة') !== false) {
        $prov = str_replace(['مديرية التربية والتعليم بـ', 'مديرية التربية والتعليم ', 'مديرة التربية والتعليم بال', 'مديرية التربية والتعليم بال', 'مديرة التربية والتعليم ', ' بال'], '', $value);
        $prov_names = [
            'دقهلية' => 'Dakahlia',
            'الدقهلية' => 'Dakahlia',
            'قاهرة' => 'Cairo',
            'القاهرة' => 'Cairo',
            'جيزة' => 'Giza',
            'الجيزة' => 'Giza',
            'إسكندرية' => 'Alexandria',
            'الإسكندرية' => 'Alexandria'
        ];
        $translatedProv = $prov_names[$prov] ?? $prov;
        return $translatedProv . ' Directorate of Education';
    }

    // ترجمة أسماء المدارس
    if (strpos($value, 'مدرسة') !== false) {
        $sName = str_replace(['مدرسة ', ' الخاصة', ' للغات', ' اللغات', 'الحديثة'], '', $value);
        $school_names = [
            'الدلتا' => 'Delta',
            'إديوكور' => 'EduCore'
        ];
        $translatedSName = $school_names[trim($sName)] ?? trim($sName);

        $suffix = '';
        if (strpos($value, 'للغات') !== false || strpos($value, 'اللغات') !== false)
            $suffix .= ' Language';
        if (strpos($value, 'الخاصة') !== false)
            $suffix .= ' Private';
        if (strpos($value, 'الحديثة') !== false)
            $translatedSName .= ' Modern';

        return $translatedSName . $suffix . ' School';
    }

    return $translated;
}

/**
 * دالة لترجمة نصوص المراحل والصفوف الدراسية إلى الإنجليزية للطباعة
 */
function translate_text_to_en($text)
{
    if (empty($text))
        return '';

    $translations = [
        // Stages
        'المرحلة الابتدائية' => 'Primary Stage',
        'المرحلة الاعدادية' => 'Preparatory Stage',
        'المرحلة الإعدادية' => 'Preparatory Stage',
        'المرحلة الثانوية' => 'Secondary Stage',
        'رياض الأطفال' => 'Kindergarten',
        'المرحلة التمهيدية' => 'KG Stage',

        // Grades
        'الصف الأول الابتدائي' => 'Grade: Primary 1',
        'الصف الثاني الابتدائي' => 'Grade: Primary 2',
        'الصف الثالث الابتدائي' => 'Grade: Primary 3',
        'الصف الرابع الابتدائي' => 'Grade: Primary 4',
        'الصف الخامس الابتدائي' => 'Grade: Primary 5',
        'الصف السادس الابتدائي' => 'Grade: Primary 6',
        'الصف الأول الاعدادي' => 'Grade: Preparatory 1',
        'الصف الأول الإعدادي' => 'Grade: Preparatory 1',
        'الصف الثاني الاعدادي' => 'Grade: Preparatory 2',
        'الصف الثاني الإعدادي' => 'Grade: Preparatory 2',
        'الصف الثالث الاعدادي' => 'Grade: Preparatory 3',
        'الصف الثالث الإعدادي' => 'Grade: Preparatory 3',
        'الصف الأول الثانوي' => 'Grade: Secondary 1',
        'الصف الثاني الثانوي' => 'Grade: Secondary 2',
        'الصف الثالث الثانوي' => 'Grade: Secondary 3',
        'تمهيدي 1' => 'KG 1',
        'تمهيدي 2' => 'KG 2',
        'كي جي 1' => 'KG 1',
        'كي جي 2' => 'KG 2',
        'الروضة الأولى' => 'KG 1',
        'الروضة الثانية' => 'KG 2'
    ];

    $text = trim($text);
    if (isset($translations[$text])) {
        return $translations[$text];
    }

    if (strpos($text, ' - ') !== false) {
        $parts = explode(' - ', $text);
        $translated_parts = [];
        foreach ($parts as $part) {
            $part_trim = trim($part);
            $translated_parts[] = $translations[$part_trim] ?? $part_trim;
        }
        return implode(' - ', $translated_parts);
    }

    return $text;
}

/**
 * طباعة HTML الهيدر
 * @param string $title عنوان المطبوعة
 * @param string $subtitle عنوان فرعي اختياري
 */
function print_header_html($title = '', $subtitle = '', $lang = 'ar', $showPrintDate = true)
{
    $s = _get_print_settings();
    $schoolName = $s['school_name'] ?? 'المدرسة';
    $directorate = $s['educational_directorate'] ?? '';
    $administration = $s['educational_administration'] ?? '';
    $academicYear = $s['academic_year'] ?? '';

    if ($lang === 'en') {
        $schoolName = !empty($s['school_name_en']) ? $s['school_name_en'] : translate_setting_to_en('school_name', $schoolName);
        $directorate = !empty($s['educational_directorate_en']) ? $s['educational_directorate_en'] : translate_setting_to_en('educational_directorate', $directorate);
        $administration = !empty($s['educational_administration_en']) ? $s['educational_administration_en'] : translate_setting_to_en('educational_administration', $administration);
        $subtitle = translate_text_to_en($subtitle);
    }

    $schoolName = htmlspecialchars($schoolName);
    $directorate = htmlspecialchars($directorate);
    $administration = htmlspecialchars($administration);
    $academicYear = htmlspecialchars($academicYear);

    // Logo path
    $logoPath = '../assets/img/logo.png';
    if (!empty($s['school_logo']) && file_exists(__DIR__ . '/../uploads/' . $s['school_logo'])) {
        $logoPath = '../uploads/' . htmlspecialchars($s['school_logo']);
    }

    $yearLabel = $lang === 'en' ? 'Academic Year: ' : 'العام الدراسي: ';
    $dateLabel = $lang === 'en' ? 'Date: ' : 'التاريخ: ';

    // Construct the school/admin block html (Symmetrical line by line)
    $adminSchoolBlock = '<div style="display: inline-block; text-align: center;">';
    if ($directorate)
        $adminSchoolBlock .= '<div style="font-size: 12px; font-weight: bold; color: #000; margin-bottom: 3px;">' . $directorate . '</div>';
    if ($administration)
        $adminSchoolBlock .= '<div style="font-size: 12px; font-weight: bold; color: #000; margin-bottom: 3px;">' . $administration . '</div>';
    if ($schoolName)
        $adminSchoolBlock .= '<div style="font-size: 12px; font-weight: bold; color: #000; margin-bottom: 3px;">' . $schoolName . '</div>';
    $adminSchoolBlock .= '</div>';

    // Construct the title/stage/year block html (Symmetrical line by line: Stage, then Class, then Academic Year, then marginalized Date)
    $titleYearBlock = '<div style="display: inline-block; text-align: center;">';
    if ($subtitle)
        $titleYearBlock .= '<div style="font-size: 12px; font-weight: bold; color: #000; margin-bottom: 3px;">' . htmlspecialchars($subtitle) . '</div>';
    if ($title)
        $titleYearBlock .= '<div style="font-size: 12px; font-weight: bold; color: #000; margin-bottom: 3px;">' . htmlspecialchars($title) . '</div>';
    if ($academicYear)
        $titleYearBlock .= '<div style="font-size: 12px; font-weight: bold; color: #000; margin-bottom: 3px;">' . $yearLabel . $academicYear . '</div>';
    if ($showPrintDate) {
        $titleYearBlock .= '<div style="font-size: 10px; font-weight: normal; color: #666; margin-top: 5px; font-family: sans-serif;">' . $dateLabel . date('Y/m/d') . '</div>';
    }
    $titleYearBlock .= '</div>';

    $html = '<div class="print-only-header">';
    $html .= '<table class="print-header-table"><tr>';

    if ($lang === 'en') {
        // Swap positions in English: Title/Stage/Year on the right (1st td in RTL), Admin/School on the left (3rd td in RTL)
        $html .= '<td class="print-header-right">';
        $html .= $titleYearBlock;
        $html .= '</td>';

        $html .= '<td class="print-header-center" style="vertical-align: top;">';
        $html .= '<img src="' . $logoPath . '" alt="شعار المدرسة" class="print-logo-img" style="width: 75px; height: 75px; object-fit: contain; margin-top: -10px; margin-bottom: 3px;">';
        $html .= '</td>';

        $html .= '<td class="print-header-left">';
        $html .= $adminSchoolBlock;
        $html .= '</td>';
    } else {
        // Normal positions in Arabic: Admin/School on the right, Title/Stage/Year on the left
        $html .= '<td class="print-header-right">';
        $html .= $adminSchoolBlock;
        $html .= '</td>';

        $html .= '<td class="print-header-center" style="vertical-align: top;">';
        $html .= '<img src="' . $logoPath . '" alt="شعار المدرسة" class="print-logo-img" style="width: 75px; height: 75px; object-fit: contain; margin-top: -10px; margin-bottom: 3px;">';
        $html .= '</td>';

        $html .= '<td class="print-header-left">';
        $html .= $titleYearBlock;
        $html .= '</td>';
    }

    $html .= '</tr></table>';
    $html .= '<div class="print-header-line"></div>';
    $html .= '</div>';

    return $html;
}

/**
 * طباعة HTML الفوتر
 * @param int|null $stageId رقم المرحلة
 * @param string|null $stageCode كود المرحلة
 * @param string|null $stageName اسم المرحلة للعرض
 */
function print_footer_html($stageId = null, $stageCode = null, $stageName = null, $lang = 'ar', $showPrintDate = true)
{
    $s = _get_print_settings();
    $schoolDirector = $s['school_director'] ?? '';
    $stageDirector = get_stage_director_name($stageId, $stageCode, null, $lang);

    if ($lang === 'en') {
        $schoolDirector = !empty($s['school_director_en']) ? $s['school_director_en'] : translate_setting_to_en('school_director', $schoolDirector);
    }

    $schoolDirector = htmlspecialchars($schoolDirector);
    $stageDirector = htmlspecialchars($stageDirector);

    if ($lang === 'en') {
        $stageLabel = 'Headmistress';
        $schoolDirectorLabel = 'School Principal';
    } else {
        $stageLabel = 'مدير المرحلة';
        if ($stageName) {
            $stageLabel = 'مديرة ' . htmlspecialchars($stageName);
        }
        $schoolDirectorLabel = 'مدير المدرسة';
    }

    $html = '<div class="print-only-footer">';
    $html .= '<div class="print-footer-line"></div>';
    $html .= '<table class="print-footer-table"><tr>';

    if ($lang === 'en') {
        // Swap positions in English: School Principal on the right (1st td in RTL), Stage Headmistress/Director on the left (2nd td in RTL)
        if ($schoolDirector) {
            $html .= '<td class="print-footer-cell">';
            $html .= '<div class="print-footer-label">' . $schoolDirectorLabel . '</div>';
            $html .= '<div class="print-footer-name">' . $schoolDirector . '</div>';
            $html .= '</td>';
        }
        if ($stageDirector) {
            $html .= '<td class="print-footer-cell">';
            $html .= '<div class="print-footer-label">' . $stageLabel . '</div>';
            $html .= '<div class="print-footer-name">' . $stageDirector . '</div>';
            $html .= '</td>';
        }
    } else {
        // Normal positions in Arabic: Stage Director on the right, School Director on the left
        if ($stageDirector) {
            $html .= '<td class="print-footer-cell">';
            $html .= '<div class="print-footer-label">' . $stageLabel . '</div>';
            $html .= '<div class="print-footer-name">' . $stageDirector . '</div>';
            $html .= '</td>';
        }
        if ($schoolDirector) {
            $html .= '<td class="print-footer-cell">';
            $html .= '<div class="print-footer-label">' . $schoolDirectorLabel . '</div>';
            $html .= '<div class="print-footer-name">' . $schoolDirector . '</div>';
            $html .= '</td>';
        }
    }

    $html .= '</tr></table>';
    $html .= '</div>';

    return $html;
}

/**
 * CSS الطباعة الموحد
 */
function print_template_css()
{
    return '
<style>
/* === Print Template: Hidden on screen === */
.print-only-header,
.print-only-footer { display: none; }

@media print {
    /* Show print header/footer */
    .print-only-header,
    .print-only-footer { display: block !important; }

    /* Header Table Layout */
    .print-header-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 5px;
    }
    .print-header-table td {
        vertical-align: top;
        padding: 0 5px;
        line-height: 1.5;
    }
    .print-header-right {
        text-align: right;
        width: 30%;
    }
    .print-header-center {
        text-align: center;
        width: 40%;
    }
    .print-header-left {
        text-align: left;
        width: 30%;
    }
    .print-logo-img {
        width: 75px;
        height: 75px;
        object-fit: contain;
        margin-bottom: 3px;
    }
    .print-directorate {
        font-size: 12px;
        font-weight: bold;
        color: #333;
        margin-bottom: 2px;
    }
    .print-administration {
        font-size: 11px;
        font-weight: 600;
        color: #555;
    }
    .print-school-name {
        font-size: 16px;
        font-weight: 800;
        color: #000;
        margin-bottom: 2px;
    }
    .print-academic-year {
        font-size: 10px;
        color: #666;
    }
    .print-doc-title {
        font-size: 13px;
        font-weight: bold;
        color: #333;
        margin-bottom: 2px;
    }
    .print-doc-subtitle {
        font-size: 11px;
        color: #555;
        margin-bottom: 2px;
    }
    .print-date {
        font-size: 10px;
        color: #888;
    }
    .print-header-line {
        border-top: 1.5px solid #475569;
        margin: 8px 0 12px 0;
    }

    /* Footer */
    .print-footer-line {
        border-top: 1px solid #999;
        margin: 20px 0 10px 0;
    }
    .print-footer-table {
        width: 100%;
        border-collapse: collapse;
    }
    .print-footer-cell {
        width: 50%;
        text-align: center;
        padding: 5px 20px;
    }
    .print-footer-label {
        font-size: 13px;
        color: #555;
        font-weight: 600;
        margin-bottom: 3px;
    }
    .print-footer-name {
        font-size: 13px;
        font-weight: bold;
        color: #000;
        padding-top: 0;
        margin-top: 3px;
    }
}
</style>';
}
