<?php
/**
 * Dynamic Lesson Plan Renderer
 * يعرض عناصر التحضير ديناميكياً بدون الحاجة لبرمجة كل عنصر يدوياً
 * 
 * Usage:
 *   require_once 'includes/lesson_plan_renderer.php';
 *   $renderer = new LessonPlanRenderer($lessonPlan);
 *   $grouped = $renderer->renderGrouped(); // Returns ['objectives'=>html, 'phases'=>html, ...]
 */

class LessonPlanRenderer {
    
    private $data;
    
    /**
     * Element metadata: key => [icon, label, group]
     * Groups: objectives, phases, assessment, resources, closure
     */
    private static $elementMeta = [
        'objectives' => [
            'icon' => 'fa-bullseye',
            'color' => '#10b981',
            'label' => 'أهداف الدرس',
            'group' => 'objectives'
        ],
        'target_competencies' => [
            'icon' => 'fa-award',
            'color' => '#f59e0b',
            'label' => 'الكفايات المستهدفة',
            'group' => 'objectives'
        ],
        'motivational_intro' => [
            'icon' => 'fa-rocket',
            'color' => '#ec4899',
            'label' => 'المقدمة التحفيزية',
            'group' => 'phases'
        ],
        'strategies' => [
            'icon' => 'fa-lightbulb',
            'color' => '#f59e0b',
            'label' => 'الاستراتيجيات التدريسية',
            'group' => 'phases'
        ],
        'lesson_phases' => [
            'icon' => 'fa-tasks',
            'color' => '#3b82f6',
            'label' => 'مراحل الدرس',
            'group' => 'phases',
            'renderer' => 'renderLessonPhases'
        ],
        'total_duration' => [
            'icon' => 'fa-clock',
            'color' => '#ef4444',
            'label' => 'المدة الكلية',
            'group' => 'phases'
        ],
        'introduction' => [
            'icon' => 'fa-play-circle',
            'color' => '#10b981',
            'label' => 'المقدمة / التهيئة',
            'group' => 'phases'
        ],
        'presentation' => [
            'icon' => 'fa-chalkboard-teacher',
            'color' => '#3b82f6',
            'label' => 'العرض / المحتوى الرئيسي',
            'group' => 'phases'
        ],
        'main_content' => [
            'icon' => 'fa-chalkboard-teacher',
            'color' => '#3b82f6',
            'label' => 'المحتوى الرئيسي',
            'group' => 'phases'
        ],
        'activities' => [
            'icon' => 'fa-tasks',
            'color' => '#f59e0b',
            'label' => 'الأنشطة',
            'group' => 'phases'
        ],
        'evaluation' => [
            'icon' => 'fa-clipboard-check',
            'color' => '#8b5cf6',
            'label' => 'التقويم',
            'group' => 'assessment'
        ],
        'assessment' => [
            'icon' => 'fa-clipboard-check',
            'color' => '#8b5cf6',
            'label' => 'التقويم',
            'group' => 'assessment'
        ],
        'formative_assessment' => [
            'icon' => 'fa-clipboard-list',
            'color' => '#f97316',
            'label' => 'التقويم التكويني',
            'group' => 'assessment'
        ],
        'differentiation' => [
            'icon' => 'fa-layer-group',
            'color' => '#14b8a6',
            'label' => 'مراعاة الفروق الفردية',
            'group' => 'assessment'
        ],
        'enrichment' => [
            'icon' => 'fa-gem',
            'color' => '#6366f1',
            'label' => 'الإثراء والتوسع',
            'group' => 'assessment'
        ],
        'resources_needed' => [
            'icon' => 'fa-toolbox',
            'color' => '#06b6d4',
            'label' => 'الموارد والوسائل المطلوبة',
            'group' => 'resources'
        ],
        'new_vocabulary' => [
            'icon' => 'fa-spell-check',
            'color' => '#0ea5e9',
            'label' => 'المفردات والمصطلحات الجديدة',
            'group' => 'resources'
        ],
        'learning_styles' => [
            'icon' => 'fa-users-cog',
            'color' => '#8b5cf6',
            'label' => 'أنماط التعلم',
            'group' => 'resources'
        ],
        'real_life_connections' => [
            'icon' => 'fa-globe',
            'color' => '#059669',
            'label' => 'الربط بالحياة الواقعية',
            'group' => 'resources'
        ],
        'closure_summary' => [
            'icon' => 'fa-flag-checkered',
            'color' => '#6d28d9',
            'label' => 'الغلق والتلخيص',
            'group' => 'closure'
        ],
        'homework' => [
            'icon' => 'fa-home',
            'color' => '#06b6d4',
            'label' => 'الواجب المنزلي',
            'group' => 'closure'
        ],
        'assignment' => [
            'icon' => 'fa-home',
            'color' => '#06b6d4',
            'label' => 'الواجب المنزلي',
            'group' => 'closure'
        ],
        'self_reflection' => [
            'icon' => 'fa-brain',
            'color' => '#be185d',
            'label' => 'التأمل الذاتي للمعلم',
            'group' => 'closure'
        ],
        'post_notes' => [
            'icon' => 'fa-sticky-note',
            'color' => '#ca8a04',
            'label' => 'ملاحظات ما بعد التنفيذ',
            'group' => 'closure'
        ],
    ];
    
    /** Keys to skip entirely (metadata, not content) */
    private static $skipKeys = ['lesson_title', 'duration', 'time_allocation'];
    
    /** Sub-tab group definitions */
    private static $groups = [
        'objectives' => ['icon' => 'fa-bullseye', 'label' => 'الأهداف', 'tabId' => 'lp-objectives'],
        'phases'     => ['icon' => 'fa-tasks',    'label' => 'سير الدرس', 'tabId' => 'lp-phases'],
        'assessment' => ['icon' => 'fa-clipboard-check', 'label' => 'التقويم والتمايز', 'tabId' => 'lp-assessment'],
        'resources'  => ['icon' => 'fa-toolbox',  'label' => 'الموارد والمفردات', 'tabId' => 'lp-resources'],
        'closure'    => ['icon' => 'fa-flag-checkered', 'label' => 'الغلق والتأمل', 'tabId' => 'lp-closure'],
    ];

    public function __construct($lessonPlan) {
        $this->data = $lessonPlan;
    }

    /**
     * Render all elements grouped by sub-tab category
     * @return array ['objectives' => html, 'phases' => html, ...]
     */
    public function renderGrouped() {
        $result = [];
        foreach (array_keys(self::$groups) as $g) {
            $result[$g] = '';
        }
        $result['extra'] = '';

        if (!is_array($this->data)) return $result;

        foreach ($this->data as $key => $value) {
            if (in_array($key, self::$skipKeys) || empty($value)) continue;

            $meta = self::$elementMeta[$key] ?? null;
            $group = $meta['group'] ?? 'extra';
            $html = $this->renderElement($key, $value, $meta);
            
            if (!empty($html)) {
                if (isset($result[$group])) {
                    $result[$group] .= $html;
                } else {
                    $result['extra'] .= $html;
                }
            }
        }

        return $result;
    }

    /**
     * Get group definitions for building sub-tabs
     */
    public static function getGroups() {
        return self::$groups;
    }

    /**
     * Render a single element
     */
    private function renderElement($key, $value, $meta = null) {
        $icon = $meta['icon'] ?? 'fa-circle';
        $color = $meta['color'] ?? '#64748b';
        $label = $meta['label'] ?? $this->humanizeKey($key);
        
        // Check for special renderer
        if (!empty($meta['renderer']) && method_exists($this, $meta['renderer'])) {
            $content = $this->{$meta['renderer']}($value);
        } else {
            $content = $this->renderValue($value);
        }
        
        if (empty(trim(strip_tags($content)))) return '';

        ob_start();
        ?>
        <div class="plan-item">
            <div class="plan-item-title">
                <i class="fas <?php echo $icon; ?>" style="color: <?php echo $color; ?>;"></i>
                <?php echo htmlspecialchars($label); ?>
            </div>
            <div class="plan-item-content"><?php echo $content; ?></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Convert snake_case key to readable Arabic-friendly label
     */
    private function humanizeKey($key) {
        return str_replace('_', ' ', ucfirst($key));
    }

    /**
     * Generic value renderer - handles any data structure
     */
    private function renderValue($value, $depth = 0) {
        if (is_null($value) || $value === '') return '';
        
        // Simple string or number
        if (is_string($value) || is_numeric($value)) {
            return '<div>' . nl2br(htmlspecialchars((string)$value)) . '</div>';
        }
        
        if (!is_array($value)) return '';

        // Indexed array (list)
        if (isset($value[0]) || array_keys($value) === range(0, count($value) - 1)) {
            return $this->renderList($value, $depth);
        }
        
        // Associative array (object)
        return $this->renderObject($value, $depth);
    }

    /**
     * Render an indexed array as a list
     */
    private function renderList($arr, $depth = 0) {
        if (empty($arr)) return '';
        
        // Check if array of simple strings
        $allStrings = true;
        foreach ($arr as $item) {
            if (is_array($item)) { $allStrings = false; break; }
        }

        if ($allStrings) {
            // Simple tag-like display for short items, list for longer ones
            $avgLen = array_sum(array_map('mb_strlen', $arr)) / count($arr);
            if ($avgLen < 40 && count($arr) <= 10) {
                $html = '<div style="display: flex; flex-wrap: wrap; gap: 8px;">';
                foreach ($arr as $item) {
                    $html .= '<span style="background: #f1f5f9; color: #334155; padding: 6px 12px; border-radius: 8px; font-size: 0.9rem;">' 
                           . htmlspecialchars($item) . '</span>';
                }
                $html .= '</div>';
                return $html;
            } else {
                $html = '<ul style="padding-right: 20px;">';
                foreach ($arr as $item) {
                    $html .= '<li style="margin-bottom: 5px;">' . htmlspecialchars($item) . '</li>';
                }
                $html .= '</ul>';
                return $html;
            }
        }

        // Array of objects/mixed
        $html = '';
        foreach ($arr as $i => $item) {
            if (is_array($item)) {
                // Try to get a title/label from the object
                $title = $item['title'] ?? $item['name'] ?? $item['text'] ?? $item['term'] ?? $item['word'] ?? $item['method'] ?? $item['type'] ?? $item['phase'] ?? null;
                $html .= '<div style="margin-bottom: 10px; padding: 10px; background: #f8fafc; border-radius: 8px; border-right: 3px solid #cbd5e1;">';
                if ($title) {
                    $html .= '<strong style="color: #1e293b;">' . htmlspecialchars($title) . '</strong>';
                }
                // Render remaining keys
                foreach ($item as $k => $v) {
                    if (in_array($k, ['title', 'name', 'text', 'term', 'word'])) continue;
                    if (empty($v)) continue;
                    $subLabel = $this->getSubLabel($k);
                    if (is_array($v)) {
                        $html .= '<div style="margin-top: 5px;"><small style="color: #64748b;">' . htmlspecialchars($subLabel) . ':</small> ' . $this->renderValue($v, $depth + 1) . '</div>';
                    } else {
                        $html .= '<div style="margin-top: 3px;"><small style="color: #64748b;">' . htmlspecialchars($subLabel) . ':</small> ' . htmlspecialchars($v) . '</div>';
                    }
                }
                $html .= '</div>';
            } else {
                $html .= '<div style="margin-bottom: 5px;">' . htmlspecialchars(is_string($item) ? $item : json_encode($item, JSON_UNESCAPED_UNICODE)) . '</div>';
            }
        }
        return $html;
    }

    /**
     * Render an associative array as labeled sections
     */
    private function renderObject($obj, $depth = 0) {
        if (empty($obj)) return '';
        
        // Check for 'content' key shortcut
        if (isset($obj['content']) && count($obj) <= 2) {
            return '<div>' . nl2br(htmlspecialchars($obj['content'])) . '</div>';
        }

        $html = '';
        foreach ($obj as $k => $v) {
            if (empty($v)) continue;
            $subLabel = $this->getSubLabel($k);
            
            if (is_array($v)) {
                $html .= '<div style="margin-bottom: 12px;">';
                $html .= '<strong style="color: #475569;">' . htmlspecialchars($subLabel) . ':</strong>';
                $html .= $this->renderValue($v, $depth + 1);
                $html .= '</div>';
            } else {
                $html .= '<div style="margin-bottom: 8px;">';
                $html .= '<strong style="color: #475569;">' . htmlspecialchars($subLabel) . ':</strong> ';
                $html .= nl2br(htmlspecialchars((string)$v));
                $html .= '</div>';
            }
        }
        return $html;
    }

    /**
     * Special renderer for lesson_phases (table format)
     */
    private function renderLessonPhases($phases) {
        if (!is_array($phases) || empty($phases)) return '';
        
        ob_start();
        ?>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                <thead>
                    <tr style="background: linear-gradient(135deg, #10b981, #059669); color: white;">
                        <th style="padding: 12px; text-align: right; border: 1px solid #e2e8f0;">المرحلة</th>
                        <th style="padding: 12px; text-align: center; border: 1px solid #e2e8f0;">الزمن</th>
                        <th style="padding: 12px; text-align: right; border: 1px solid #e2e8f0;">الوصف / الأنشطة</th>
                        <th style="padding: 12px; text-align: right; border: 1px solid #e2e8f0;">دور المعلم</th>
                        <th style="padding: 12px; text-align: right; border: 1px solid #e2e8f0;">دور المتعلم</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($phases as $phase): ?>
                    <tr style="background: #f8fafc;">
                        <td style="padding: 12px; border: 1px solid #e2e8f0; font-weight: 600; color: #1e293b;">
                            <?php echo htmlspecialchars($phase['phase'] ?? $phase['name'] ?? $phase['title'] ?? '-'); ?>
                        </td>
                        <td style="padding: 12px; border: 1px solid #e2e8f0; text-align: center; color: #64748b;">
                            <?php 
                            $dur = $phase['duration_minutes'] ?? $phase['duration'] ?? $phase['time'] ?? '-';
                            echo htmlspecialchars($dur . (is_numeric($dur) ? ' دقيقة' : '')); 
                            ?>
                        </td>
                        <td style="padding: 12px; border: 1px solid #e2e8f0; color: #374151; line-height: 1.6;">
                            <?php 
                            // Try multiple possible content keys
                            $contentKeys = ['description', 'content_points', 'activities', 'key_points', 'homework', 'content', 'details'];
                            $found = false;
                            foreach ($contentKeys as $ck) {
                                if (!empty($phase[$ck])) {
                                    if (is_array($phase[$ck])) {
                                        echo '<ul style="padding-right: 15px; margin: 0;">';
                                        foreach ($phase[$ck] as $point) {
                                            echo '<li>' . htmlspecialchars(is_array($point) ? json_encode($point, JSON_UNESCAPED_UNICODE) : $point) . '</li>';
                                        }
                                        echo '</ul>';
                                    } else {
                                        echo nl2br(htmlspecialchars($phase[$ck]));
                                    }
                                    $found = true;
                                    break;
                                }
                            }
                            if (!$found) echo '-';
                            ?>
                        </td>
                        <td style="padding: 12px; border: 1px solid #e2e8f0; color: #374151;">
                            <?php echo nl2br(htmlspecialchars($phase['teacher_role'] ?? $phase['teacher_activity'] ?? '-')); ?>
                        </td>
                        <td style="padding: 12px; border: 1px solid #e2e8f0; color: #374151;">
                            <?php echo nl2br(htmlspecialchars($phase['student_role'] ?? $phase['student_activity'] ?? '-')); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Get Arabic sub-label for common keys
     */
    private function getSubLabel($key) {
        static $labels = [
            'cognitive' => 'الأهداف المعرفية',
            'affective' => 'الأهداف الوجدانية',
            'psychomotor' => 'الأهداف المهارية',
            'teaching_strategies' => 'استراتيجيات التدريس',
            'active_learning' => 'التعلم النشط',
            'enrichment_activities' => 'أنشطة إثرائية',
            'extension_activities' => 'أنشطة إثرائية',
            'remedial_activities' => 'أنشطة علاجية',
            'additional_resources' => 'مصادر إضافية',
            'challenge_questions' => 'أسئلة تحدي',
            'hook' => 'الجذب',
            'question' => 'سؤال تحفيزي',
            'story' => 'قصة/موقف',
            'content' => 'المحتوى',
            'method' => 'الطريقة',
            'type' => 'النوع',
            'timing' => 'التوقيت',
            'tool' => 'الأداة',
            'description' => 'الوصف',
            'success_criteria' => 'معيار النجاح',
            'closing_activity' => 'النشاط الختامي',
            'summary' => 'الملخص',
            'key_takeaways' => 'النقاط الرئيسية',
            'exit_ticket' => 'بطاقة الخروج',
            'questions' => 'أسئلة تأملية',
            'improvement_areas' => 'مجالات التحسين',
            'what_worked' => 'ما نجح في الدرس',
            'template' => 'النموذج',
            'prompts' => 'موجهات',
            'term' => 'المصطلح',
            'word' => 'الكلمة',
            'definition' => 'التعريف',
            'meaning' => 'المعنى',
            'example' => 'مثال',
            'application' => 'التطبيق',
            'context' => 'السياق',
            'visual' => 'بصري',
            'auditory' => 'سمعي',
            'kinesthetic' => 'حركي',
            'reading' => 'قرائي',
            'reading_writing' => 'قرائي/كتابي',
            'advanced' => 'المتفوقون',
            'intermediate' => 'المتوسطون',
            'beginner' => 'المبتدئون',
            'struggling' => 'ذوو الصعوبات',
            'gifted' => 'الموهوبون',
            'steps' => 'الخطوات',
            'duration' => 'المدة',
            'duration_minutes' => 'المدة بالدقائق',
            'phase' => 'المرحلة',
            'teacher_role' => 'دور المعلم',
            'student_role' => 'دور المتعلم',
        ];
        return $labels[$key] ?? str_replace('_', ' ', ucfirst($key));
    }
}
