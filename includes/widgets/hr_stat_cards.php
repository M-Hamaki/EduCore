<?php
/**
 * Widget مشترك: بطاقات إحصائيات HR
 * الاستخدام:
 *   $hrStatCards = [
 *       ['value' => 120, 'label' => 'الموظفون', 'icon' => 'fa-users',    'gradient' => '#3b82f6,#2563eb'],
 *       ...
 *   ];
 *   require_once '../includes/widgets/hr_stat_cards.php';
 *   renderHrStatCards($hrStatCards);
 */

if (!function_exists('renderHrStatCards')) {
    function renderHrStatCards(array $cards, string $colClass = 'row-cols-2 row-cols-md-4'): void
    {
        static $renderCount = 0;
        $renderCount++;
        $pageName = preg_replace('/[^a-z0-9_-]+/i', '-', pathinfo($_SERVER['PHP_SELF'] ?? 'admin', PATHINFO_FILENAME));
        $containerId = 'hr-stats-' . ($pageName ?: 'admin') . '-' . $renderCount;

        echo '<div class="dashboard-canvas sortable-dashboard">';
        echo '<div id="' . htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8') . '" class="row ' . htmlspecialchars($colClass, ENT_QUOTES, 'UTF-8') . ' g-3 mb-4 sortable-dashboard">';
        $delay = 1;
        foreach ($cards as $index => $card) {
            $value    = (int)($card['value'] ?? 0);
            $label    = htmlspecialchars((string)($card['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $sub      = isset($card['sub']) ? htmlspecialchars((string)$card['sub'], ENT_QUOTES, 'UTF-8') : '';
            $subIcon  = isset($card['sub_icon']) ? htmlspecialchars((string)$card['sub_icon'], ENT_QUOTES, 'UTF-8') : '';
            $icon     = htmlspecialchars((string)($card['icon'] ?? 'fa-circle'), ENT_QUOTES, 'UTF-8');
            $gradient = htmlspecialchars((string)($card['gradient'] ?? '#3b82f6,#2563eb'), ENT_QUOTES, 'UTF-8');

            $cardId = $containerId . '-card-' . ($index + 1);
            echo '<div id="' . htmlspecialchars($cardId, ENT_QUOTES, 'UTF-8') . '" class="col animate-up delay-' . $delay . '">';
            echo '<div class="stat-card" style="--card-gradient: linear-gradient(135deg,' . $gradient . ');">';
            echo '<div class="stat-card-icon"><i class="fas ' . $icon . '"></i></div>';
            echo '<div class="stat-card-info">';
            echo '<div class="stat-card-number counter" data-target="' . $value . '">0</div>';
            echo '<div class="stat-card-label">' . $label . '</div>';
            if ($sub !== '') {
                echo '<div class="stat-card-sub">';
                if ($subIcon !== '') {
                    echo '<i class="fas ' . $subIcon . '"></i> ';
                }
                echo $sub . '</div>';
            }
            echo '</div></div></div>';
            $delay++;
        }
        echo '</div></div>';
    }
}
?>
