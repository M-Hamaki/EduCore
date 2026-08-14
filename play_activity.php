<?php
/**
 * صفحة تشغيل الأنشطة التفاعلية — WordWall Style (Professional)
 * play_activity.php
 */
require_once 'config/database.php';
require_once 'includes/session_config.php';
require_once 'includes/csrf.php';

$database = new Database();
$db = $database->getConnection();

$isPreview = isset($_GET['preview']);
$activity = null;
$error = '';

if (!$isPreview) {
    $code = trim($_GET['code'] ?? '');
    if (empty($code)) {
        $error = 'لم يتم تحديد رمز النشاط';
    } else {
        $stmt = $db->prepare("SELECT a.*, u.name as teacher_name, s.name as subject_name FROM activities a LEFT JOIN users u ON a.teacher_id = u.id LEFT JOIN subjects s ON a.subject_id = s.id WHERE a.code = ? AND a.status = 'active'");
        $stmt->execute([$code]);
        $activity = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$activity) $error = 'النشاط غير موجود أو غير متاح';
        else {
            $db->prepare("UPDATE activities SET play_count = play_count + 1 WHERE id = ?")->execute([$activity['id']]);
        }
    }
}

// AJAX: Submit result
if (isset($_GET['submit_result']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $data = json_decode(file_get_contents('php://input'), true);
    requireCsrfToken(is_array($data) ? ($data['csrf_token'] ?? '') : '');
    if ($data && $activity && (int)($data['activity_id'] ?? 0) === (int)$activity['id']) {
        $stmt = $db->prepare("INSERT INTO activity_results (activity_id, player_name, score, max_score, percentage, time_spent, answers_data) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $pct = $data['max_score'] > 0 ? round(($data['score'] / $data['max_score']) * 100, 2) : 0;
        $stmt->execute([
            intval($data['activity_id']),
            htmlspecialchars(substr($data['player_name'] ?? 'مجهول', 0, 100), ENT_QUOTES, 'UTF-8'),
            intval($data['score']),
            intval($data['max_score']),
            $pct,
            intval($data['time_spent']),
            json_encode($data['answers'] ?? [], JSON_UNESCAPED_UNICODE)
        ]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $activity ? htmlspecialchars($activity['title']) : 'نشاط تفاعلي' ?> — EduCore</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    <style>
    * { font-family: 'Tajawal', sans-serif; margin: 0; padding: 0; box-sizing: border-box; }
    body { background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%); min-height: 100vh; color: #fff; overflow-x: hidden; }

    /* Floating particles */
    .particles { position: fixed; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 0; }
    .particle { position: absolute; border-radius: 50%; opacity: .12; animation: floatUp linear infinite; }
    @keyframes floatUp { 0% { transform: translateY(100vh) rotate(0deg); opacity:0; } 10% { opacity:.12; } 90% { opacity:.12; } 100% { transform: translateY(-10vh) rotate(360deg); opacity:0; } }

    .game-container { position: relative; z-index: 1; max-width: 900px; margin: 0 auto; padding: 20px; min-height: 100vh; display: flex; flex-direction: column; }

    /* Header */
    .game-header { background: rgba(255,255,255,.07); backdrop-filter: blur(16px); border-radius: 24px; padding: 28px; margin-bottom: 20px; border: 1px solid rgba(255,255,255,.1); text-align: center; box-shadow: 0 8px 32px rgba(0,0,0,.2); }
    .game-header h1 { font-size: 1.8rem; font-weight: 900; margin-bottom: 8px; background: linear-gradient(135deg, #fff, #e2e8f0); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
    .game-header .meta { font-size: .88rem; opacity: .65; }
    .game-header .type-badge { display: inline-block; padding: 6px 18px; border-radius: 20px; font-weight: 700; font-size: .85rem; margin-top: 10px; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.15); }

    /* Game area */
    .game-area { background: rgba(255,255,255,.05); backdrop-filter: blur(10px); border-radius: 24px; padding: 32px; border: 1px solid rgba(255,255,255,.08); min-height: 300px; box-shadow: 0 4px 24px rgba(0,0,0,.15); flex: 1; }

    /* HUD */
    .game-hud { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
    .hud-item { background: rgba(255,255,255,.08); padding: 10px 20px; border-radius: 14px; font-weight: 700; font-size: .95rem; border: 1px solid rgba(255,255,255,.06); }
    .hud-item i { margin-left: 6px; }

    /* Progress bar */
    .progress-bar-custom { height: 8px; background: rgba(255,255,255,.1); border-radius: 4px; margin-bottom: 24px; overflow: hidden; }
    .progress-bar-custom .fill { height: 100%; background: linear-gradient(90deg, #3b82f6, #8b5cf6); border-radius: 4px; transition: width .4s ease; }

    /* ============ QUIZ ============ */
    .quiz-question { font-size: 1.5rem; font-weight: 800; text-align: center; margin-bottom: 28px; padding: 24px; background: rgba(255,255,255,.04); border-radius: 20px; border: 1px solid rgba(255,255,255,.06); line-height: 1.8; }
    .quiz-counter { text-align: center; font-size: .9rem; opacity: .5; margin-bottom: 16px; }
    .quiz-options { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    @media(max-width:600px) { .quiz-options { grid-template-columns: 1fr; } }
    .quiz-option { background: rgba(255,255,255,.07); border: 2px solid rgba(255,255,255,.12); border-radius: 16px; padding: 18px 22px; cursor: pointer; font-size: 1.1rem; font-weight: 600; transition: all .25s ease; text-align: center; position: relative; overflow: hidden; }
    .quiz-option:hover { background: rgba(59,130,246,.15); border-color: rgba(59,130,246,.4); transform: translateY(-2px); box-shadow: 0 4px 20px rgba(59,130,246,.15); }
    .quiz-option.correct { background: rgba(16,185,129,.25); border-color: #10b981; animation: correctPulse .5s ease; }
    .quiz-option.correct::after { content: '✓'; position: absolute; left: 14px; top: 50%; transform: translateY(-50%); font-size: 1.4rem; color: #10b981; }
    .quiz-option.wrong { background: rgba(239,68,68,.25); border-color: #ef4444; animation: shake .4s ease; }
    .quiz-option.wrong::after { content: '✗'; position: absolute; left: 14px; top: 50%; transform: translateY(-50%); font-size: 1.4rem; color: #ef4444; }
    .quiz-option.disabled { pointer-events: none; opacity: .5; }
    @keyframes correctPulse { 0% { transform: scale(1); } 50% { transform: scale(1.03); } 100% { transform: scale(1); } }

    /* ============ TRUE/FALSE ============ */
    .tf-statement { font-size: 1.35rem; font-weight: 700; text-align: center; padding: 28px; background: rgba(255,255,255,.04); border-radius: 20px; margin-bottom: 24px; line-height: 1.8; border: 1px solid rgba(255,255,255,.06); }
    .tf-buttons { display: flex; gap: 20px; justify-content: center; }
    .tf-btn { padding: 18px 48px; border-radius: 16px; font-size: 1.25rem; font-weight: 800; cursor: pointer; border: 3px solid transparent; transition: all .25s ease; display: flex; align-items: center; gap: 10px; }
    .tf-btn.true-btn { background: rgba(16,185,129,.12); border-color: rgba(16,185,129,.4); color: #34d399; }
    .tf-btn.false-btn { background: rgba(239,68,68,.12); border-color: rgba(239,68,68,.4); color: #f87171; }
    .tf-btn:hover { transform: translateY(-3px); box-shadow: 0 6px 24px rgba(0,0,0,.2); }
    .tf-btn.selected-correct { background: #10b981 !important; color: #fff !important; border-color: #10b981 !important; }
    .tf-btn.selected-wrong { background: #ef4444 !important; color: #fff !important; border-color: #ef4444 !important; }

    /* ============ MATCH ============ */
    .match-container { display: flex; gap: 40px; justify-content: center; flex-wrap: wrap; align-items: flex-start; }
    .match-column { display: flex; flex-direction: column; gap: 12px; min-width: 160px; flex: 1; max-width: 300px; }
    .match-col-title { text-align: center; font-weight: 700; opacity: .5; font-size: .85rem; margin-bottom: 4px; }
    .match-item { background: rgba(255,255,255,.07); border: 2px solid rgba(255,255,255,.15); border-radius: 14px; padding: 16px 20px; cursor: pointer; text-align: center; font-weight: 600; font-size: 1.05rem; transition: all .25s ease; user-select: none; }
    .match-item:hover { border-color: rgba(59,130,246,.5); transform: translateY(-1px); }
    .match-item.selected { border-color: #f59e0b; background: rgba(245,158,11,.15); box-shadow: 0 0 20px rgba(245,158,11,.2); transform: scale(1.02); }
    .match-item.matched { border-color: #10b981; background: rgba(16,185,129,.15); pointer-events: none; opacity: .6; }
    .match-item.wrong-flash { animation: shake .4s ease; border-color: #ef4444; background: rgba(239,68,68,.15); }
    .match-divider { display: flex; align-items: center; justify-content: center; opacity: .2; font-size: 1.5rem; }
    .match-score-text { text-align: center; margin-top: 16px; font-size: .9rem; opacity: .5; }

    /* ============ FLASHCARDS ============ */
    .flashcard-wrapper { text-align: center; }
    .flashcard-counter { font-size: .9rem; opacity: .5; margin-bottom: 12px; }
    .flashcard-container { perspective: 1200px; width: 100%; max-width: 480px; margin: 0 auto; cursor: pointer; }
    .flashcard { width: 100%; min-height: 260px; position: relative; transform-style: preserve-3d; transition: transform .7s cubic-bezier(.4,0,.2,1); }
    .flashcard.flipped { transform: rotateY(180deg); }
    .flashcard-face { position: absolute; width: 100%; height: 100%; min-height: 260px; backface-visibility: hidden; border-radius: 24px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; font-weight: 700; padding: 32px; text-align: center; line-height: 1.6; border: 1px solid rgba(255,255,255,.1); }
    .flashcard-front { background: linear-gradient(135deg, #3b82f6, #1d4ed8); box-shadow: 0 8px 32px rgba(59,130,246,.3); }
    .flashcard-back { background: linear-gradient(135deg, #10b981, #047857); transform: rotateY(180deg); box-shadow: 0 8px 32px rgba(16,185,129,.3); }
    .flashcard-icon { font-size: 1.8rem; opacity: .4; margin-bottom: 12px; }
    .flashcard-tip { text-align: center; margin-top: 14px; font-size: .85rem; opacity: .4; }
    .flashcard-nav { display: flex; justify-content: center; gap: 12px; margin-top: 24px; flex-wrap: wrap; }
    .fc-btn { padding: 10px 24px; border-radius: 12px; font-weight: 700; border: none; cursor: pointer; font-size: .95rem; transition: all .2s; display: inline-flex; align-items: center; gap: 8px; }
    .fc-btn:hover { transform: translateY(-2px); }
    .fc-btn-knew { background: rgba(16,185,129,.25); color: #34d399; border: 1px solid rgba(16,185,129,.3); }
    .fc-btn-didnt { background: rgba(239,68,68,.2); color: #f87171; border: 1px solid rgba(239,68,68,.3); }
    .fc-btn-prev { background: rgba(255,255,255,.08); color: #fff; border: 1px solid rgba(255,255,255,.1); }

    /* ============ WHEEL ============ */
    .wheel-container { text-align: center; }
    .wheel-canvas-wrap { position: relative; display: inline-block; margin: 0 auto; }
    #wheelCanvas { max-width: 100%; display: block; }
    .wheel-pointer { position: absolute; top: -8px; left: 50%; transform: translateX(-50%); font-size: 2.5rem; color: #f59e0b; z-index: 2; text-shadow: 0 3px 12px rgba(0,0,0,.5); filter: drop-shadow(0 0 8px rgba(245,158,11,.5)); }
    .wheel-result { font-size: 1.6rem; font-weight: 900; margin-top: 24px; padding: 20px; background: rgba(245,158,11,.15); border-radius: 20px; display: none; border: 1px solid rgba(245,158,11,.25); animation: resultPopIn .4s ease; }
    @keyframes resultPopIn { from { transform: scale(.8); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    .spin-btn { padding: 16px 48px; font-size: 1.2rem; font-weight: 800; border-radius: 18px; background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; border: none; cursor: pointer; margin-top: 20px; transition: all .25s ease; box-shadow: 0 4px 20px rgba(245,158,11,.3); }
    .spin-btn:hover { transform: translateY(-3px) scale(1.02); box-shadow: 0 8px 30px rgba(245,158,11,.4); }
    .spin-btn:disabled { opacity: .5; cursor: not-allowed; transform: none; }

    /* ============ GROUP SORT ============ */
    .groups-area { display: flex; gap: 16px; flex-wrap: wrap; justify-content: center; margin-bottom: 24px; }
    .sort-group { background: rgba(255,255,255,.04); border: 2px dashed rgba(255,255,255,.15); border-radius: 18px; padding: 16px; min-width: 180px; min-height: 150px; flex: 1; max-width: 300px; transition: all .2s; }
    .sort-group.drag-over { border-color: #3b82f6; background: rgba(59,130,246,.08); }
    .sort-group-title { font-weight: 800; text-align: center; padding: 10px; border-radius: 12px; margin-bottom: 12px; font-size: 1.05rem; }
    .sort-group-items { min-height: 60px; display: flex; flex-wrap: wrap; gap: 6px; }
    .sort-items-pool { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; padding: 20px; background: rgba(255,255,255,.03); border-radius: 18px; border: 1px solid rgba(255,255,255,.06); margin-bottom: 20px; }
    .sort-item { background: rgba(255,255,255,.1); border: 2px solid rgba(255,255,255,.15); border-radius: 12px; padding: 10px 18px; cursor: pointer; font-weight: 600; transition: all .2s; user-select: none; font-size: .95rem; }
    .sort-item:hover { border-color: #3b82f6; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,.2); }
    .sort-item.selected-item { border-color: #f59e0b; background: rgba(245,158,11,.15); box-shadow: 0 0 16px rgba(245,158,11,.2); }
    .sort-item.placed { opacity: .7; cursor: default; font-size: .85rem; padding: 6px 12px; }
    .sort-item.correct-place { border-color: #10b981; background: rgba(16,185,129,.15); }
    .sort-item.wrong-place { border-color: #ef4444; background: rgba(239,68,68,.15); animation: shake .3s; }
    .sort-hint { text-align: center; font-size: .85rem; opacity: .4; margin-bottom: 16px; }

    /* ============ ORDER ============ */
    .order-list { max-width: 500px; margin: 0 auto; }
    .order-hint { text-align: center; font-size: .9rem; opacity: .4; margin-bottom: 16px; }
    .order-item { background: rgba(255,255,255,.07); border: 2px solid rgba(255,255,255,.12); border-radius: 14px; padding: 16px 20px; margin-bottom: 10px; cursor: grab; font-weight: 600; display: flex; align-items: center; gap: 12px; transition: all .15s ease; user-select: none; font-size: 1.05rem; }
    .order-item:hover { border-color: rgba(59,130,246,.4); transform: translateX(-4px); }
    .order-item:active { cursor: grabbing; }
    .order-item .order-num { background: rgba(59,130,246,.2); border: 1px solid rgba(59,130,246,.3); min-width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: .9rem; font-weight: 800; flex-shrink: 0; }
    .order-item.dragging { opacity: .4; border-style: dashed; }
    .order-item.drag-over-item { border-color: #3b82f6; background: rgba(59,130,246,.1); }
    .order-item.correct-pos { border-color: #10b981; background: rgba(16,185,129,.12); }
    .order-item.correct-pos .order-num { background: rgba(16,185,129,.3); border-color: #10b981; }
    .order-item.wrong-pos { border-color: #ef4444; background: rgba(239,68,68,.1); animation: shake .3s; }
    .order-item.wrong-pos .order-num { background: rgba(239,68,68,.3); border-color: #ef4444; }
    .order-check-btn { display: block; margin: 20px auto 0; padding: 12px 36px; font-size: 1.05rem; font-weight: 700; border-radius: 14px; background: linear-gradient(135deg, #10b981, #059669); color: #fff; border: none; cursor: pointer; transition: all .2s; box-shadow: 0 4px 16px rgba(16,185,129,.3); }
    .order-check-btn:hover { transform: translateY(-2px); }

    /* ============ OPEN BOX ============ */
    .boxes-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 14px; }
    .game-box { background: linear-gradient(135deg, #8b5cf6, #6d28d9); border-radius: 18px; min-height: 130px; display: flex; flex-direction: column; align-items: center; justify-content: center; cursor: pointer; font-size: 2.8rem; transition: all .4s cubic-bezier(.4,0,.2,1); position: relative; overflow: hidden; border: 2px solid rgba(255,255,255,.1); box-shadow: 0 4px 20px rgba(139,92,246,.2); }
    .game-box:hover { transform: translateY(-4px) scale(1.02); box-shadow: 0 8px 30px rgba(139,92,246,.35); }
    .game-box .box-question-label { position: absolute; bottom: 0; left: 0; right: 0; background: rgba(0,0,0,.4); backdrop-filter: blur(4px); padding: 8px; font-size: .8rem; text-align: center; opacity: 0; transition: .2s; font-weight: 600; }
    .game-box:hover .box-question-label { opacity: 1; }
    .game-box.opened { background: linear-gradient(135deg, #3b82f6, #1d4ed8); font-size: 1rem; padding: 16px; cursor: default; transform: rotateY(360deg); border-color: rgba(59,130,246,.3); }
    .game-box.opened .box-question-label { display: none; }
    .game-box.opened .box-icon { display: none; }
    .open-box-q { font-size: .85rem; opacity: .6; margin-top: 8px; font-weight: 600; }
    .open-box-a { font-size: 1.15rem; font-weight: 800; }

    /* ============ MISSING WORD ============ */
    .missing-text { font-size: 1.3rem; line-height: 2.4; text-align: center; padding: 24px; background: rgba(255,255,255,.03); border-radius: 18px; border: 1px solid rgba(255,255,255,.06); }
    .missing-blank { display: inline-block; border-bottom: 3px solid rgba(59,130,246,.5); min-width: 80px; padding: 4px 10px; margin: 0 4px; cursor: pointer; transition: all .2s; border-radius: 4px; }
    .missing-blank.active-blank { border-color: #f59e0b; background: rgba(245,158,11,.1); box-shadow: 0 0 12px rgba(245,158,11,.2); }
    .missing-blank.filled { border-color: #10b981; color: #10b981; font-weight: 800; background: rgba(16,185,129,.08); pointer-events: none; }
    .missing-blank.wrong { border-color: #ef4444; color: #ef4444; animation: shake .3s; background: rgba(239,68,68,.08); }
    .missing-words-pool { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; margin-top: 24px; padding: 16px; background: rgba(255,255,255,.03); border-radius: 14px; }
    .missing-word-chip { background: rgba(59,130,246,.12); border: 2px solid rgba(59,130,246,.3); border-radius: 12px; padding: 10px 18px; cursor: pointer; font-weight: 700; transition: all .2s; }
    .missing-word-chip:hover { background: rgba(59,130,246,.25); transform: translateY(-2px); }
    .missing-word-chip.used { opacity: .2; pointer-events: none; transform: scale(.9); }

    /* ============ ANAGRAM ============ */
    .anagram-area { text-align: center; }
    .anagram-counter { font-size: .9rem; opacity: .5; margin-bottom: 12px; }
    .anagram-hint { font-size: 1rem; opacity: .6; margin-bottom: 20px; padding: 10px 20px; background: rgba(255,255,255,.04); border-radius: 12px; display: inline-block; }
    .anagram-answer { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; min-height: 64px; padding: 14px; background: rgba(255,255,255,.04); border-radius: 16px; border: 2px dashed rgba(255,255,255,.15); margin-bottom: 20px; align-items: center; transition: border-color .3s; }
    .anagram-answer.wrong-answer { border-color: #ef4444; }
    .anagram-answer.correct-answer { border-color: #10b981; background: rgba(16,185,129,.1); }
    .anagram-answer-letter { width: 48px; height: 48px; background: rgba(59,130,246,.2); border: 2px solid rgba(59,130,246,.4); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; font-weight: 800; cursor: pointer; animation: popIn .25s cubic-bezier(.4,0,.2,1); transition: all .15s; }
    .anagram-answer-letter:hover { background: rgba(239,68,68,.2); border-color: #ef4444; }
    @keyframes popIn { from { transform: scale(0) rotate(-10deg); } to { transform: scale(1) rotate(0); } }
    .anagram-letters { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; margin-bottom: 20px; }
    .anagram-letter { width: 52px; height: 52px; background: rgba(255,255,255,.08); border: 2px solid rgba(255,255,255,.15); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; font-weight: 800; cursor: pointer; transition: all .2s; user-select: none; }
    .anagram-letter:hover { border-color: #3b82f6; background: rgba(59,130,246,.15); transform: translateY(-3px); }
    .anagram-letter.used { opacity: .15; pointer-events: none; transform: scale(.85); }
    .anagram-clear-btn { padding: 8px 20px; border-radius: 10px; background: rgba(255,255,255,.08); color: #fff; border: 1px solid rgba(255,255,255,.1); cursor: pointer; font-weight: 600; font-size: .9rem; transition: all .2s; }
    .anagram-clear-btn:hover { background: rgba(239,68,68,.2); border-color: #ef4444; }

    /* ============ BALLOON POP ============ */
    .balloon-question { font-size: 1.35rem; font-weight: 800; text-align: center; margin-bottom: 24px; padding: 20px; background: rgba(255,255,255,.04); border-radius: 18px; border: 1px solid rgba(255,255,255,.06); }
    .balloons-area { display: flex; flex-wrap: wrap; gap: 20px; justify-content: center; min-height: 200px; padding: 20px; }
    .balloon { width: 100px; height: 120px; border-radius: 50% 50% 50% 50% / 40% 40% 60% 60%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.05rem; cursor: pointer; animation: floatBalloon 3s ease-in-out infinite; transition: all .2s; position: relative; text-align: center; padding: 10px; word-break: break-word; box-shadow: inset -8px -8px 20px rgba(0,0,0,.15), 0 4px 16px rgba(0,0,0,.2); }
    .balloon::before { content: ''; position: absolute; top: 12px; right: 18px; width: 14px; height: 18px; background: rgba(255,255,255,.3); border-radius: 50%; transform: rotate(-30deg); }
    .balloon::after { content: ''; position: absolute; bottom: -16px; left: 50%; transform: translateX(-50%); width: 2px; height: 24px; background: rgba(255,255,255,.25); }
    .balloon:hover { transform: scale(1.12) translateY(-5px); }
    .balloon.popped { animation: pop .4s forwards; pointer-events: none; }
    .balloon.wrong-pop { animation: wrongPop .5s forwards; pointer-events: none; }
    @keyframes floatBalloon { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-12px); } }
    @keyframes pop { 0% { transform: scale(1); } 50% { transform: scale(1.3); opacity: .5; } 100% { transform: scale(0); opacity: 0; } }
    @keyframes wrongPop { 0% { opacity: 1; } 100% { opacity: .15; transform: translateY(20px); } }

    /* ============ MEMORY GAME ============ */
    .memory-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; max-width: 550px; margin: 0 auto; }
    @media(max-width:500px) { .memory-grid { grid-template-columns: repeat(3, 1fr); } }
    .memory-card { aspect-ratio: 1; border-radius: 14px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 1rem; font-weight: 700; transition: all .35s ease; position: relative; padding: 8px; text-align: center; word-break: break-word; perspective: 600px; border: 2px solid transparent; }
    .memory-card .card-inner { width: 100%; height: 100%; position: relative; transform-style: preserve-3d; transition: transform .5s cubic-bezier(.4,0,.2,1); display: flex; align-items: center; justify-content: center; }
    .memory-card.flipped .card-inner { transform: rotateY(180deg); }
    .memory-card .card-front, .memory-card .card-back-face { position: absolute; width: 100%; height: 100%; backface-visibility: hidden; border-radius: 12px; display: flex; align-items: center; justify-content: center; padding: 8px; }
    .memory-card .card-front { background: linear-gradient(135deg, #6366f1, #4338ca); }
    .memory-card .card-back-face { background: linear-gradient(135deg, #3b82f6, #1d4ed8); transform: rotateY(180deg); }
    .memory-card:hover { transform: translateY(-3px); box-shadow: 0 6px 20px rgba(99,102,241,.3); }
    .memory-card.matched { border-color: #10b981; }
    .memory-card.matched .card-back-face { background: linear-gradient(135deg, #10b981, #047857); }
    .memory-card.matched { pointer-events: none; }
    .memory-card .card-back-icon { font-size: 1.8rem; opacity: .4; color: #fff; }
    .memory-attempts { text-align: center; margin-top: 16px; font-size: .9rem; opacity: .5; }

    /* ============ RESULTS ============ */
    .results-screen { text-align: center; padding: 40px 20px; }
    .results-screen h2 { font-size: 1.6rem; font-weight: 900; margin-bottom: 24px; }
    .score-circle { width: 160px; height: 160px; border-radius: 50%; display: flex; flex-direction: column; align-items: center; justify-content: center; margin: 0 auto 24px; font-size: 2.8rem; font-weight: 900; box-shadow: 0 8px 32px rgba(0,0,0,.2); border: 3px solid rgba(255,255,255,.1); }
    .score-label { font-size: .95rem; font-weight: 600; opacity: .85; }
    .results-details { display: flex; gap: 16px; justify-content: center; flex-wrap: wrap; margin-bottom: 24px; }
    .result-detail { background: rgba(255,255,255,.06); padding: 12px 20px; border-radius: 12px; font-weight: 600; }
    .result-msg { font-size: 1.2rem; font-weight: 700; margin-bottom: 24px; }
    .result-stars { font-size: 2rem; margin-bottom: 16px; }
    .result-btns { display: flex; gap: 14px; justify-content: center; flex-wrap: wrap; }
    .result-btn { padding: 14px 32px; border-radius: 14px; font-weight: 700; border: none; cursor: pointer; font-size: 1rem; transition: all .2s; display: inline-flex; align-items: center; gap: 8px; }
    .result-btn:hover { transform: translateY(-2px); }
    .result-btn-primary { background: linear-gradient(135deg, #3b82f6, #8b5cf6); color: #fff; box-shadow: 0 4px 16px rgba(59,130,246,.3); }
    .result-btn-secondary { background: rgba(255,255,255,.08); color: #fff; border: 1px solid rgba(255,255,255,.15); }

    /* Name input */
    .name-input-area { max-width: 400px; margin: 0 auto 24px; }
    .name-input { background: rgba(255,255,255,.08); border: 2px solid rgba(255,255,255,.15); color: #fff; border-radius: 14px; padding: 14px 20px; font-size: 1.1rem; font-weight: 600; text-align: center; width: 100%; transition: all .2s; }
    .name-input:focus { border-color: #3b82f6; outline: none; box-shadow: 0 0 16px rgba(59,130,246,.2); }
    .name-input::placeholder { color: rgba(255,255,255,.3); }
    .start-btn { padding: 14px 48px; border-radius: 16px; font-weight: 800; font-size: 1.15rem; border: none; cursor: pointer; background: linear-gradient(135deg, #3b82f6, #8b5cf6); color: #fff; transition: all .25s; box-shadow: 0 4px 20px rgba(59,130,246,.3); margin-top: 16px; }
    .start-btn:hover { transform: translateY(-3px) scale(1.02); box-shadow: 0 8px 30px rgba(59,130,246,.4); }

    /* Confetti */
    .confetti-piece { position: fixed; width: 10px; height: 10px; z-index: 100; pointer-events: none; animation: confettiFall linear forwards; }
    @keyframes confettiFall { 0% { opacity: 1; transform: translateY(-20vh) rotate(0deg); } 100% { opacity: 0; transform: translateY(110vh) rotate(720deg); } }

    /* Animations */
    @keyframes shake { 0%,100% { transform: translateX(0); } 20% { transform: translateX(-8px); } 40% { transform: translateX(8px); } 60% { transform: translateX(-6px); } 80% { transform: translateX(6px); } }

    /* Responsive */
    @media(max-width:576px) {
        .game-header h1 { font-size: 1.3rem; }
        .game-area { padding: 18px; border-radius: 18px; }
        .quiz-question { font-size: 1.15rem; padding: 16px; }
        .tf-btn { padding: 14px 30px; font-size: 1rem; }
        .balloon { width: 80px; height: 100px; font-size: .9rem; }
        .anagram-letter { width: 44px; height: 44px; font-size: 1.2rem; }
    }
    </style>
</head>
<body>

<!-- Particles background -->
<div class="particles" id="particles"></div>

<div class="game-container">
<?php if ($error): ?>
    <div class="game-header">
        <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3" style="display:block"></i>
        <h1 style="-webkit-text-fill-color:#fff"><?= htmlspecialchars($error) ?></h1>
        <p class="meta">تحقق من الرابط وحاول مجدداً</p>
    </div>
<?php elseif ($activity || $isPreview): ?>

    <div class="game-header">
        <h1 id="gameTitle"><?= $activity ? htmlspecialchars($activity['title']) : 'معاينة النشاط' ?></h1>
        <div class="meta">
            <?php if ($activity): ?>
            <span><i class="fas fa-user me-1"></i><?= htmlspecialchars($activity['teacher_name'] ?? '') ?></span>
            <?php if ($activity['subject_name']): ?> · <span><i class="fas fa-book me-1"></i><?= htmlspecialchars($activity['subject_name']) ?></span><?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="type-badge" id="typeBadge"></div>
    </div>

    <!-- Name input screen -->
    <div id="nameScreen" class="game-area" style="text-align:center">
        <h3 class="mb-4" style="font-weight:800"><i class="fas fa-user-circle me-2" style="opacity:.5"></i>أدخل اسمك للبدء</h3>
        <div class="name-input-area">
            <input type="text" id="playerName" class="name-input" placeholder="اكتب اسمك هنا..." maxlength="50" autocomplete="off">
        </div>
        <button class="start-btn" onclick="startGame()">
            <i class="fas fa-play me-2"></i>ابدأ اللعب
        </button>
    </div>

    <!-- Game area -->
    <div id="gameScreen" class="game-area" style="display:none">
        <div class="game-hud">
            <div class="hud-item" id="hudScore"><i class="fas fa-star" style="color:#f59e0b"></i> <span id="scoreVal">0</span></div>
            <div class="hud-item" id="hudProgress"><span id="progressText">0/0</span></div>
            <div class="hud-item" id="hudTimer"><i class="fas fa-clock" style="color:#3b82f6"></i> <span id="timerVal">0:00</span></div>
        </div>
        <div class="progress-bar-custom"><div class="fill" id="progressBar" style="width:0%"></div></div>
        <div id="gameContent"></div>
    </div>

    <!-- Results screen -->
    <div id="resultsScreen" class="game-area" style="display:none">
        <div class="results-screen">
            <div class="result-stars" id="resultStars"></div>
            <h2><i class="fas fa-trophy me-2" style="color:#f59e0b"></i>انتهت اللعبة!</h2>
            <div class="score-circle" id="scoreCircle">
                <span id="finalScore">0%</span>
                <span class="score-label" id="finalScoreLabel">0/0</span>
            </div>
            <div class="results-details">
                <div class="result-detail" id="finalTime"><i class="fas fa-clock me-1"></i>0:00</div>
                <div class="result-detail" id="finalCorrect"><i class="fas fa-check me-1"></i>0 صحيحة</div>
            </div>
            <p class="result-msg" id="finalMessage"></p>
            <div class="result-btns">
                <button class="result-btn result-btn-primary" onclick="restartGame()"><i class="fas fa-redo"></i>إعادة اللعب</button>
                <button class="result-btn result-btn-secondary" onclick="window.close()"><i class="fas fa-times"></i>إغلاق</button>
            </div>
        </div>
    </div>

<?php endif; ?>
</div>

<script>
// ============ CORE STATE ============
var activityData = <?= $activity ? json_encode($activity) : 'null' ?>;
var isPreview = <?= $isPreview ? 'true' : 'false' ?>;
var items = [];
var currentIndex = 0;
var score = 0;
var maxScore = 0;
var startTime = 0;
var timerInterval = null;
var playerName = '';
var gameEnded = false;

// Init particles
(function() {
    var pc = document.getElementById('particles');
    if (!pc) return;
    var colors = ['#3b82f6','#8b5cf6','#10b981','#f59e0b','#ef4444','#ec4899'];
    for (var i = 0; i < 25; i++) {
        var p = document.createElement('div');
        p.className = 'particle';
        var size = Math.random() * 8 + 3;
        p.style.cssText = 'width:'+size+'px;height:'+size+'px;left:'+Math.random()*100+'%;background:'+colors[i%colors.length]+';animation-duration:'+(Math.random()*18+12)+'s;animation-delay:'+(Math.random()*12)+'s';
        pc.appendChild(p);
    }
})();

// Preview mode
if (isPreview) {
    try {
        var pd = JSON.parse(sessionStorage.getItem('preview_activity') || '{}');
        activityData = pd;
        activityData.items_data = pd.items_data || '[]';
        var gt = document.getElementById('gameTitle');
        if (gt) gt.textContent = pd.title || 'معاينة';
    } catch(e) {}
}

if (activityData) {
    try { items = JSON.parse(activityData.items_data); } catch(e) { items = []; }
    var typeNames = {quiz:'اختبار سريع',true_false:'صح أو خطأ',match:'المطابقة',group_sort:'تصنيف المجموعات',order:'الترتيب',flashcards:'بطاقات تعليمية',wheel:'العجلة العشوائية',open_box:'افتح الصندوق',missing_word:'الكلمة المفقودة',anagram:'إعادة ترتيب الحروف',balloon_pop:'فرقعة البالونات',memory_game:'لعبة الذاكرة'};
    var tb = document.getElementById('typeBadge');
    if (tb) tb.textContent = typeNames[activityData.activity_type] || activityData.activity_type;
}

// ============ GAME START ============
function startGame() {
    playerName = document.getElementById('playerName').value.trim() || 'لاعب';
    document.getElementById('nameScreen').style.display = 'none';
    document.getElementById('gameScreen').style.display = 'block';

    score = 0;
    maxScore = 0;
    currentIndex = 0;
    gameEnded = false;
    startTime = Date.now();
    timerInterval = setInterval(updateTimer, 1000);
    document.getElementById('scoreVal').textContent = '0';

    var type = activityData.activity_type;
    // Hide progress bar for non-sequential games
    var hideProgress = ['wheel','flashcards','match','group_sort','order','memory_game'];
    if (hideProgress.indexOf(type) > -1) {
        document.getElementById('hudProgress').style.display = 'none';
        document.querySelector('.progress-bar-custom').style.display = 'none';
    }
    if (type === 'wheel') {
        document.getElementById('hudScore').style.display = 'none';
    }

    switch (type) {
        case 'quiz': initQuiz(); break;
        case 'true_false': initTrueFalse(); break;
        case 'match': initMatch(); break;
        case 'flashcards': initFlashcards(); break;
        case 'wheel': initWheel(); break;
        case 'group_sort': initGroupSort(); break;
        case 'order': initOrder(); break;
        case 'open_box': initOpenBox(); break;
        case 'missing_word': initMissingWord(); break;
        case 'anagram': initAnagram(); break;
        case 'balloon_pop': initBalloonPop(); break;
        case 'memory_game': initMemoryGame(); break;
    }
}

// ============ UTILITY FUNCTIONS ============
function updateTimer() {
    var elapsed = Math.floor((Date.now() - startTime) / 1000);
    var m = Math.floor(elapsed / 60);
    var s = elapsed % 60;
    document.getElementById('timerVal').textContent = m + ':' + (s < 10 ? '0' : '') + s;
}

function updateProgress(current, total) {
    document.getElementById('progressText').textContent = current + '/' + total;
    document.getElementById('progressBar').style.width = (total > 0 ? (current / total * 100) : 0) + '%';
}

function addScore(points) {
    score += points;
    document.getElementById('scoreVal').textContent = score;
}

function shuffle(arr) {
    var a = arr.slice();
    for (var i = a.length - 1; i > 0; i--) {
        var j = Math.floor(Math.random() * (i + 1));
        var t = a[i]; a[i] = a[j]; a[j] = t;
    }
    return a;
}

function escHtml(t) {
    if (!t && t !== 0) return '';
    var d = document.createElement('div');
    d.textContent = String(t);
    return d.innerHTML;
}

function escAttr(t) {
    if (!t) return '';
    return String(t).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Split Arabic text into proper characters (handles diacritics)
function splitArabicChars(text) {
    // Use Array.from to properly handle Unicode, then group diacritics with their base char
    var chars = Array.from(text);
    var result = [];
    var current = '';
    for (var i = 0; i < chars.length; i++) {
        var code = chars[i].charCodeAt(0);
        // Arabic combining marks: 0x0610-0x061A, 0x064B-0x065F, 0x0670, 0x06D6-0x06DC, 0x06DF-0x06E4, 0x06E7-0x06E8, 0x06EA-0x06ED
        var isCombining = (code >= 0x064B && code <= 0x065F) || (code >= 0x0610 && code <= 0x061A) || code === 0x0670 || (code >= 0x06D6 && code <= 0x06ED) || (code >= 0x0300 && code <= 0x036F);
        if (isCombining && current) {
            current += chars[i];
        } else {
            if (current) result.push(current);
            current = chars[i];
        }
    }
    if (current) result.push(current);
    return result;
}

// ============ QUIZ ============
function initQuiz() {
    maxScore = items.length;
    showQuizQuestion(0);
}

function showQuizQuestion(idx) {
    if (idx >= items.length) { endGame(); return; }
    currentIndex = idx;
    updateProgress(idx + 1, items.length);
    var q = items[idx];
    var gc = document.getElementById('gameContent');
    // Filter non-empty options and track original indices
    var validOptions = [];
    q.options.forEach(function(opt, i) {
        if (opt && opt.trim()) validOptions.push({text: opt, origIdx: i});
    });
    var correctOrigIdx = q.correct;

    var html = '<div class="quiz-counter">السؤال ' + (idx + 1) + ' من ' + items.length + '</div>';
    html += '<div class="quiz-question">' + escHtml(q.question) + '</div><div class="quiz-options">';
    validOptions.forEach(function(opt) {
        html += '<div class="quiz-option" data-idx="' + opt.origIdx + '">' + escHtml(opt.text) + '</div>';
    });
    html += '</div>';
    gc.innerHTML = html;

    gc.querySelectorAll('.quiz-option').forEach(function(el) {
        el.addEventListener('click', function() {
            if (this.classList.contains('disabled')) return;
            var chosen = parseInt(this.dataset.idx);
            var allOpts = gc.querySelectorAll('.quiz-option');
            allOpts.forEach(function(o) { o.classList.add('disabled'); });

            if (chosen === correctOrigIdx) {
                this.classList.add('correct');
                addScore(1);
            } else {
                this.classList.add('wrong');
                // Highlight correct answer
                allOpts.forEach(function(o) {
                    if (parseInt(o.dataset.idx) === correctOrigIdx) o.classList.add('correct');
                });
            }
            setTimeout(function() { showQuizQuestion(currentIndex + 1); }, 1200);
        });
    });
}

// ============ TRUE/FALSE ============
function initTrueFalse() {
    maxScore = items.length;
    showTFQuestion(0);
}

function showTFQuestion(idx) {
    if (idx >= items.length) { endGame(); return; }
    currentIndex = idx;
    updateProgress(idx + 1, items.length);
    var q = items[idx];
    var gc = document.getElementById('gameContent');
    gc.innerHTML = '<div class="quiz-counter">العبارة ' + (idx + 1) + ' من ' + items.length + '</div>' +
        '<div class="tf-statement">' + escHtml(q.statement) + '</div>' +
        '<div class="tf-buttons">' +
        '<div class="tf-btn true-btn" data-val="true"><i class="fas fa-check"></i>صح</div>' +
        '<div class="tf-btn false-btn" data-val="false"><i class="fas fa-times"></i>خطأ</div>' +
        '</div>';

    gc.querySelectorAll('.tf-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var chosen = this.dataset.val === 'true';
            var correct = q.answer === true || q.answer === 'true';
            gc.querySelectorAll('.tf-btn').forEach(function(b) { b.style.pointerEvents = 'none'; });
            if (chosen === correct) {
                this.classList.add('selected-correct');
                addScore(1);
            } else {
                this.classList.add('selected-wrong');
                gc.querySelector('.tf-btn.' + (correct ? 'true' : 'false') + '-btn').classList.add('selected-correct');
            }
            setTimeout(function() { showTFQuestion(currentIndex + 1); }, 1200);
        });
    });
}

// ============ MATCH ============
function initMatch() {
    maxScore = items.length;
    var lefts = shuffle(items.map(function(p, i) { return {text: p.left, idx: i}; }));
    var rights = shuffle(items.map(function(p, i) { return {text: p.right, idx: i}; }));
    var matchedCount = 0;
    var selectedLeft = null;

    var gc = document.getElementById('gameContent');
    var html = '<div class="match-container">' +
        '<div class="match-column"><div class="match-col-title">العمود الأول</div><div id="matchLeft">';
    lefts.forEach(function(l) {
        html += '<div class="match-item match-left" data-idx="' + l.idx + '">' + escHtml(l.text) + '</div>';
    });
    html += '</div></div><div class="match-divider"><i class="fas fa-arrows-alt-h"></i></div>' +
        '<div class="match-column"><div class="match-col-title">العمود الثاني</div><div id="matchRight">';
    rights.forEach(function(r) {
        html += '<div class="match-item match-right" data-idx="' + r.idx + '">' + escHtml(r.text) + '</div>';
    });
    html += '</div></div></div>' +
        '<div class="match-score-text"><i class="fas fa-link me-1"></i>وصّل كل عنصر من العمود الأول بما يناسبه في العمود الثاني</div>';
    gc.innerHTML = html;

    gc.querySelectorAll('.match-item').forEach(function(el) {
        el.addEventListener('click', function() {
            if (this.classList.contains('matched')) return;
            var isLeft = this.classList.contains('match-left');

            if (!selectedLeft && isLeft) {
                gc.querySelectorAll('.match-left').forEach(function(l) { l.classList.remove('selected'); });
                this.classList.add('selected');
                selectedLeft = this;
            } else if (selectedLeft && !isLeft) {
                var lIdx = parseInt(selectedLeft.dataset.idx);
                var rIdx = parseInt(this.dataset.idx);
                if (lIdx === rIdx) {
                    selectedLeft.classList.remove('selected');
                    selectedLeft.classList.add('matched');
                    this.classList.add('matched');
                    addScore(1);
                    matchedCount++;
                    if (matchedCount === items.length) setTimeout(endGame, 700);
                } else {
                    this.classList.add('wrong-flash');
                    selectedLeft.classList.add('wrong-flash');
                    var sl = selectedLeft;
                    var th = this;
                    setTimeout(function() { sl.classList.remove('wrong-flash', 'selected'); th.classList.remove('wrong-flash'); }, 600);
                }
                selectedLeft = null;
            } else if (isLeft) {
                gc.querySelectorAll('.match-left').forEach(function(l) { l.classList.remove('selected'); });
                this.classList.add('selected');
                selectedLeft = this;
            }
        });
    });
}

// ============ FLASHCARDS ============
function initFlashcards() {
    maxScore = items.length;
    var scored = {}; // Track which cards were already scored
    window._fcScored = scored;
    showFlashcard(0);
}

function showFlashcard(idx) {
    if (idx >= items.length) { endGame(); return; }
    if (idx < 0) idx = 0;
    currentIndex = idx;
    var c = items[idx];
    var scored = window._fcScored;
    var gc = document.getElementById('gameContent');
    gc.innerHTML = '<div class="flashcard-wrapper">' +
        '<div class="flashcard-counter">' + (idx + 1) + ' / ' + items.length + '</div>' +
        '<div class="flashcard-container" onclick="document.getElementById(\'flashcard\').classList.toggle(\'flipped\')">' +
        '<div class="flashcard" id="flashcard">' +
        '<div class="flashcard-face flashcard-front"><div><div class="flashcard-icon"><i class="fas fa-question-circle"></i></div>' + escHtml(c.front) + '</div></div>' +
        '<div class="flashcard-face flashcard-back"><div><div class="flashcard-icon"><i class="fas fa-lightbulb"></i></div>' + escHtml(c.back) + '</div></div>' +
        '</div></div>' +
        '<div class="flashcard-tip"><i class="fas fa-hand-pointer me-1"></i>اضغط على البطاقة لقلبها</div>' +
        '<div class="flashcard-nav">' +
        (idx > 0 ? '<button class="fc-btn fc-btn-prev" id="fcPrev"><i class="fas fa-arrow-right"></i>السابقة</button>' : '') +
        (scored[idx] ? '' : '<button class="fc-btn fc-btn-knew" id="fcKnew"><i class="fas fa-check"></i>عرفتها</button>') +
        '<button class="fc-btn fc-btn-didnt" id="fcDidnt"><i class="fas fa-times"></i>' + (scored[idx] ? 'التالية' : 'لم أعرفها') + '</button>' +
        '</div></div>';

    var knewBtn = document.getElementById('fcKnew');
    var didntBtn = document.getElementById('fcDidnt');
    var prevBtn = document.getElementById('fcPrev');

    if (knewBtn) {
        knewBtn.addEventListener('click', function() {
            if (!scored[idx]) { scored[idx] = true; addScore(1); }
            showFlashcard(idx + 1);
        });
    }
    if (didntBtn) {
        didntBtn.addEventListener('click', function() { showFlashcard(idx + 1); });
    }
    if (prevBtn) {
        prevBtn.addEventListener('click', function() { showFlashcard(idx - 1); });
    }
}

// ============ WHEEL ============
var wheelAngle = 0;
var wheelSpinning = false;

function initWheel() {
    maxScore = 0;
    var gc = document.getElementById('gameContent');
    gc.innerHTML = '<div class="wheel-container">' +
        '<div class="wheel-canvas-wrap"><div class="wheel-pointer"><i class="fas fa-caret-down"></i></div>' +
        '<canvas id="wheelCanvas" width="380" height="380"></canvas></div>' +
        '<button class="spin-btn" id="spinBtn"><i class="fas fa-sync-alt me-2"></i>دوّر العجلة</button>' +
        '<div class="wheel-result" id="wheelResult"></div></div>';

    document.getElementById('spinBtn').addEventListener('click', spinWheel);
    wheelAngle = 0;
    drawWheel();
}

function drawWheel() {
    var canvas = document.getElementById('wheelCanvas');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var cx = canvas.width / 2, cy = canvas.height / 2, r = 175;
    var segments = items;
    var n = segments.length;
    var colors = ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#0ea5e9','#f97316','#14b8a6','#6366f1'];
    var angleStep = (2 * Math.PI) / n;

    ctx.clearRect(0, 0, canvas.width, canvas.height);

    // Shadow
    ctx.save();
    ctx.beginPath();
    ctx.arc(cx, cy, r + 4, 0, 2 * Math.PI);
    ctx.fillStyle = 'rgba(0,0,0,.3)';
    ctx.fill();
    ctx.restore();

    ctx.save();
    ctx.translate(cx, cy);
    ctx.rotate(wheelAngle);

    for (var i = 0; i < n; i++) {
        var startA = i * angleStep;
        var endA = startA + angleStep;

        ctx.beginPath();
        ctx.moveTo(0, 0);
        ctx.arc(0, 0, r, startA, endA);
        ctx.closePath();
        ctx.fillStyle = colors[i % colors.length];
        ctx.fill();
        ctx.strokeStyle = 'rgba(255,255,255,.25)';
        ctx.lineWidth = 2;
        ctx.stroke();

        // Text
        ctx.save();
        ctx.rotate(startA + angleStep / 2);
        ctx.fillStyle = '#fff';
        ctx.font = 'bold ' + (n > 8 ? '12' : '14') + 'px Tajawal';
        ctx.textAlign = 'right';
        ctx.textBaseline = 'middle';
        var txt = typeof segments[i] === 'string' ? segments[i] : (segments[i].text || '');
        if (txt.length > 12) txt = txt.substring(0, 11) + '…';
        ctx.fillText(txt, r - 18, 0);
        ctx.restore();
    }

    // Center
    ctx.beginPath();
    ctx.arc(0, 0, 22, 0, 2 * Math.PI);
    ctx.fillStyle = '#1e293b';
    ctx.fill();
    ctx.strokeStyle = '#fff';
    ctx.lineWidth = 3;
    ctx.stroke();

    ctx.restore();
}

function spinWheel() {
    if (wheelSpinning) return;
    wheelSpinning = true;
    var btn = document.getElementById('spinBtn');
    btn.disabled = true;
    document.getElementById('wheelResult').style.display = 'none';

    var extraSpins = 5 + Math.random() * 5;
    var targetAngle = wheelAngle + extraSpins * 2 * Math.PI;
    var duration = 4500;
    var startT = Date.now();
    var startAngle = wheelAngle;

    function animate() {
        var elapsed = Date.now() - startT;
        var t = Math.min(elapsed / duration, 1);
        t = 1 - Math.pow(1 - t, 3); // Ease out cubic
        wheelAngle = startAngle + (targetAngle - startAngle) * t;
        drawWheel();
        if (t < 1) {
            requestAnimationFrame(animate);
        } else {
            wheelSpinning = false;
            btn.disabled = false;
            // Pointer at top = -PI/2 (or 3PI/2)
            var n = items.length;
            var angleStep = (2 * Math.PI) / n;
            var norm = ((wheelAngle % (2 * Math.PI)) + 2 * Math.PI) % (2 * Math.PI);
            // The pointer is at the top, which is angle -PI/2 in standard coords
            // We need to find which segment is at the top
            var pointerAngle = (Math.PI * 1.5 - norm + 2 * Math.PI) % (2 * Math.PI);
            var idx = Math.floor(pointerAngle / angleStep) % n;
            var result = typeof items[idx] === 'string' ? items[idx] : (items[idx].text || '');
            var wr = document.getElementById('wheelResult');
            wr.innerHTML = '<i class="fas fa-star me-2" style="color:#f59e0b"></i>' + escHtml(result);
            wr.style.display = 'block';
        }
    }
    animate();
}

// ============ GROUP SORT ============
function initGroupSort() {
    var allItems = [];
    items.forEach(function(g, gi) {
        g.items.forEach(function(itm) { allItems.push({text: itm, group: gi}); });
    });
    maxScore = allItems.length;
    var shuffled = shuffle(allItems);
    var gc = document.getElementById('gameContent');
    var groupColors = ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#0ea5e9'];

    var html = '<div class="sort-hint"><i class="fas fa-info-circle me-1"></i>اضغط على العنصر ثم اضغط على المجموعة المناسبة</div>';
    html += '<div class="sort-items-pool" id="itemsPool">';
    shuffled.forEach(function(itm, i) {
        html += '<div class="sort-item" data-group="' + itm.group + '" data-id="' + i + '">' + escHtml(itm.text) + '</div>';
    });
    html += '</div><div class="groups-area">';
    items.forEach(function(g, gi) {
        html += '<div class="sort-group" data-group="' + gi + '">' +
            '<div class="sort-group-title" style="background:' + groupColors[gi % groupColors.length] + '">' + escHtml(g.name) + '</div>' +
            '<div class="sort-group-items"></div></div>';
    });
    html += '</div>';
    gc.innerHTML = html;

    var selectedItem = null;
    gc.querySelectorAll('.sort-item').forEach(function(el) {
        el.addEventListener('click', function() {
            if (this.classList.contains('placed')) return;
            gc.querySelectorAll('.sort-item').forEach(function(s) { s.classList.remove('selected-item'); });
            this.classList.add('selected-item');
            selectedItem = this;
        });
    });

    gc.querySelectorAll('.sort-group').forEach(function(grp) {
        grp.addEventListener('click', function() {
            if (!selectedItem) return;
            var correctGroup = parseInt(selectedItem.dataset.group);
            var targetGroup = parseInt(this.dataset.group);
            if (correctGroup === targetGroup) {
                selectedItem.classList.add('placed', 'correct-place');
                selectedItem.classList.remove('selected-item');
                this.querySelector('.sort-group-items').appendChild(selectedItem);
                addScore(1);
                selectedItem = null;
                var remaining = gc.querySelectorAll('.sort-item:not(.placed)').length;
                updateProgress(maxScore - remaining, maxScore);
                if (remaining === 0) setTimeout(endGame, 700);
            } else {
                selectedItem.classList.add('wrong-place');
                var si = selectedItem;
                setTimeout(function() { si.classList.remove('wrong-place'); }, 500);
            }
        });
    });

    updateProgress(0, maxScore);
}

// ============ ORDER ============
function initOrder() {
    maxScore = items.length;
    var shuffled = shuffle(items.map(function(itm, i) { return {text: typeof itm === 'string' ? itm : itm.text, correctPos: i}; }));
    var gc = document.getElementById('gameContent');

    var html = '<div class="order-hint"><i class="fas fa-sort me-1"></i>اسحب العناصر لترتيبها بالترتيب الصحيح ثم اضغط تحقق</div>' +
        '<div class="order-list" id="orderList">';
    shuffled.forEach(function(itm, i) {
        html += '<div class="order-item" data-correct="' + itm.correctPos + '" draggable="true">' +
            '<span class="order-num">' + (i + 1) + '</span><span class="order-text">' + escHtml(itm.text) + '</span></div>';
    });
    html += '</div><button class="order-check-btn" id="checkOrderBtn"><i class="fas fa-check me-2"></i>تحقق من الترتيب</button>';
    gc.innerHTML = html;

    // Drag and drop
    var list = document.getElementById('orderList');
    var dragEl = null;

    list.querySelectorAll('.order-item').forEach(function(item) {
        item.addEventListener('dragstart', function(e) {
            dragEl = this;
            this.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });
        item.addEventListener('dragend', function() {
            this.classList.remove('dragging');
            list.querySelectorAll('.order-item').forEach(function(i) { i.classList.remove('drag-over-item'); });
            updateOrderNums();
        });
        item.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            this.classList.add('drag-over-item');
        });
        item.addEventListener('dragleave', function() { this.classList.remove('drag-over-item'); });
        item.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('drag-over-item');
            if (dragEl && dragEl !== this) {
                var allItems = Array.from(list.children);
                var fromIdx = allItems.indexOf(dragEl);
                var toIdx = allItems.indexOf(this);
                if (fromIdx < toIdx) this.after(dragEl);
                else this.before(dragEl);
                updateOrderNums();
            }
        });

        // Touch support
        var touchStartY = 0, touchItem = null;
        item.addEventListener('touchstart', function(e) {
            touchItem = this;
            touchStartY = e.touches[0].clientY;
            this.classList.add('dragging');
        }, {passive: true});
        item.addEventListener('touchmove', function(e) {
            e.preventDefault();
            var touch = e.touches[0];
            var target = document.elementFromPoint(touch.clientX, touch.clientY);
            list.querySelectorAll('.order-item').forEach(function(i) { i.classList.remove('drag-over-item'); });
            if (target && target.closest && target.closest('.order-item') && target.closest('.order-item') !== touchItem) {
                target.closest('.order-item').classList.add('drag-over-item');
            }
        }, {passive: false});
        item.addEventListener('touchend', function(e) {
            this.classList.remove('dragging');
            var touch = e.changedTouches[0];
            var target = document.elementFromPoint(touch.clientX, touch.clientY);
            if (target) {
                var dropTarget = target.closest ? target.closest('.order-item') : null;
                if (dropTarget && dropTarget !== touchItem) {
                    var allItems = Array.from(list.children);
                    var fromIdx = allItems.indexOf(touchItem);
                    var toIdx = allItems.indexOf(dropTarget);
                    if (fromIdx < toIdx) dropTarget.after(touchItem);
                    else dropTarget.before(touchItem);
                }
            }
            list.querySelectorAll('.order-item').forEach(function(i) { i.classList.remove('drag-over-item'); });
            updateOrderNums();
        });
    });

    document.getElementById('checkOrderBtn').addEventListener('click', function() {
        var orderItems = list.querySelectorAll('.order-item');
        score = 0;
        var allCorrect = true;
        orderItems.forEach(function(item, i) {
            var correctPos = parseInt(item.dataset.correct);
            item.classList.remove('correct-pos', 'wrong-pos');
            if (i === correctPos) {
                item.classList.add('correct-pos');
                score++;
            } else {
                item.classList.add('wrong-pos');
                allCorrect = false;
            }
        });
        document.getElementById('scoreVal').textContent = score;
        if (allCorrect) setTimeout(endGame, 800);
    });

    function updateOrderNums() {
        list.querySelectorAll('.order-item').forEach(function(item, i) {
            item.querySelector('.order-num').textContent = i + 1;
            item.classList.remove('correct-pos', 'wrong-pos');
        });
    }
}

// ============ OPEN BOX ============
function initOpenBox() {
    maxScore = items.length;
    var shuffled = shuffle(items.slice());
    var gc = document.getElementById('gameContent');
    var icons = ['🎁','📦','🎀','🎊','💝','🎈','⭐','🌟','🎯','🏆','💎','🔮'];
    var openedBoxes = 0;

    var html = '<div class="boxes-grid">';
    shuffled.forEach(function(box, i) {
        html += '<div class="game-box" data-idx="' + i + '">' +
            '<span class="box-icon">' + icons[i % icons.length] + '</span>' +
            '<div class="box-question-label">' + escHtml(box.question) + '</div></div>';
    });
    html += '</div>';
    gc.innerHTML = html;
    updateProgress(0, items.length);

    gc.querySelectorAll('.game-box').forEach(function(box, i) {
        box.addEventListener('click', function() {
            if (this.classList.contains('opened')) return;
            this.classList.add('opened');
            var boxData = shuffled[parseInt(this.dataset.idx)];
            this.innerHTML = '<div class="open-box-q">' + escHtml(boxData.question) + '</div>' +
                '<div class="open-box-a">' + escHtml(boxData.answer) + '</div>';
            openedBoxes++;
            addScore(1);
            updateProgress(openedBoxes, items.length);
            if (openedBoxes >= items.length) setTimeout(endGame, 700);
        });
    });
}

// ============ MISSING WORD ============
function initMissingWord() {
    var allBlanks = [];
    var sentences = [];

    items.forEach(function(text, sIdx) {
        var parts = String(text).split(/\[([^\]]+)\]/);
        var sentence = {parts: [], blanks: []};
        parts.forEach(function(part, i) {
            if (i % 2 === 0) {
                sentence.parts.push({type: 'text', value: part});
            } else {
                sentence.blanks.push(part);
                sentence.parts.push({type: 'blank', blankIdx: sentence.blanks.length - 1, sentenceIdx: sIdx});
                allBlanks.push(part);
            }
        });
        sentences.push(sentence);
    });

    maxScore = allBlanks.length;
    var shuffledWords = shuffle(allBlanks);
    var gc = document.getElementById('gameContent');
    var filledCount = 0;

    var html = '<div class="missing-text">';
    sentences.forEach(function(s, sIdx) {
        s.parts.forEach(function(p) {
            if (p.type === 'text') html += escHtml(p.value);
            else html += '<span class="missing-blank" data-sentence="' + sIdx + '" data-blank="' + p.blankIdx + '" data-answer="' + escAttr(s.blanks[p.blankIdx]) + '"></span>';
        });
        if (sIdx < sentences.length - 1) html += '<br>';
    });
    html += '</div><div class="missing-words-pool">';
    shuffledWords.forEach(function(w) {
        html += '<div class="missing-word-chip" data-word="' + escAttr(w) + '">' + escHtml(w) + '</div>';
    });
    html += '</div>';
    gc.innerHTML = html;

    var selectedBlank = null;

    gc.querySelectorAll('.missing-blank').forEach(function(bl) {
        bl.addEventListener('click', function() {
            if (this.classList.contains('filled')) return;
            gc.querySelectorAll('.missing-blank').forEach(function(b) { b.classList.remove('active-blank'); });
            this.classList.add('active-blank');
            selectedBlank = this;
        });
    });

    gc.querySelectorAll('.missing-word-chip').forEach(function(chip) {
        chip.addEventListener('click', function() {
            if (!selectedBlank || this.classList.contains('used')) return;
            var word = this.dataset.word;
            var answer = selectedBlank.dataset.answer;
            var thisBlank = selectedBlank; // Capture reference to avoid race condition
            thisBlank.textContent = word;
            if (word === answer) {
                thisBlank.classList.add('filled');
                thisBlank.classList.remove('active-blank');
                this.classList.add('used');
                addScore(1);
                filledCount++;
                selectedBlank = null;
                updateProgress(filledCount, maxScore);
                if (filledCount >= maxScore) setTimeout(endGame, 700);
            } else {
                thisBlank.classList.add('wrong');
                setTimeout(function() {
                    thisBlank.classList.remove('wrong');
                    thisBlank.textContent = '';
                }, 700);
            }
        });
    });

    updateProgress(0, maxScore);
}

// ============ ANAGRAM ============
function initAnagram() {
    maxScore = items.length;
    showAnagram(0);
}

function showAnagram(idx) {
    if (idx >= items.length) { endGame(); return; }
    currentIndex = idx;
    updateProgress(idx + 1, items.length);
    var item = items[idx];
    var word = item.word;
    var chars = splitArabicChars(word);
    var shuffledChars = shuffle(chars);
    var answer = [];

    var gc = document.getElementById('gameContent');
    var html = '<div class="anagram-area">';
    html += '<div class="anagram-counter">الكلمة ' + (idx + 1) + ' من ' + items.length + '</div>';
    if (item.hint) html += '<div class="anagram-hint"><i class="fas fa-lightbulb me-1"></i>' + escHtml(item.hint) + '</div>';
    html += '<div class="anagram-answer" id="anagramAnswer"></div>';
    html += '<div class="anagram-letters" id="anagramLetters">';
    shuffledChars.forEach(function(ch, i) {
        html += '<div class="anagram-letter" data-idx="' + i + '">' + escHtml(ch) + '</div>';
    });
    html += '</div>';
    html += '<button class="anagram-clear-btn" id="anaClearBtn"><i class="fas fa-eraser me-1"></i>مسح</button>';
    html += '</div>';
    gc.innerHTML = html;

    // Letter click
    gc.querySelectorAll('.anagram-letter').forEach(function(el) {
        el.addEventListener('click', function() {
            if (this.classList.contains('used')) return;
            this.classList.add('used');
            var charIdx = parseInt(this.dataset.idx);
            answer.push({ch: shuffledChars[charIdx], idx: charIdx});
            renderAnswer();

            if (answer.length === chars.length) {
                var attempt = answer.map(function(a) { return a.ch; }).join('');
                var answerEl = document.getElementById('anagramAnswer');
                if (attempt === word) {
                    answerEl.classList.add('correct-answer');
                    addScore(1);
                    setTimeout(function() { showAnagram(currentIndex + 1); }, 1000);
                } else {
                    answerEl.classList.add('wrong-answer');
                    setTimeout(function() {
                        answer = [];
                        gc.querySelectorAll('.anagram-letter').forEach(function(l) { l.classList.remove('used'); });
                        answerEl.innerHTML = '';
                        answerEl.classList.remove('wrong-answer');
                    }, 900);
                }
            }
        });
    });

    // Answer letter click (remove)
    function renderAnswer() {
        var area = document.getElementById('anagramAnswer');
        area.innerHTML = '';
        area.classList.remove('wrong-answer', 'correct-answer');
        answer.forEach(function(a, ai) {
            var div = document.createElement('div');
            div.className = 'anagram-answer-letter';
            div.textContent = a.ch;
            div.addEventListener('click', function() {
                answer.splice(ai, 1);
                gc.querySelector('.anagram-letter[data-idx="' + a.idx + '"]').classList.remove('used');
                renderAnswer();
            });
            area.appendChild(div);
        });
    }

    // Clear button
    document.getElementById('anaClearBtn').addEventListener('click', function() {
        answer = [];
        gc.querySelectorAll('.anagram-letter').forEach(function(l) { l.classList.remove('used'); });
        var area = document.getElementById('anagramAnswer');
        if (area) { area.innerHTML = ''; area.classList.remove('wrong-answer', 'correct-answer'); }
    });
}

// ============ BALLOON POP ============
function initBalloonPop() {
    maxScore = 0;
    items.forEach(function(q) { maxScore += q.correct.length; });
    showBalloonQuestion(0);
}

function showBalloonQuestion(idx) {
    if (idx >= items.length) { endGame(); return; }
    currentIndex = idx;
    updateProgress(idx + 1, items.length);
    var q = items[idx];
    var allOptions = shuffle(q.correct.concat(q.wrong));
    var balloonColors = ['#ef4444','#3b82f6','#10b981','#f59e0b','#8b5cf6','#ec4899','#0ea5e9','#f97316','#14b8a6','#6366f1'];
    var correctRemaining = q.correct.length;

    var gc = document.getElementById('gameContent');
    var html = '<div class="quiz-counter">السؤال ' + (idx + 1) + ' من ' + items.length + '</div>';
    html += '<div class="balloon-question">' + escHtml(q.question) + '</div><div class="balloons-area">';
    allOptions.forEach(function(opt, i) {
        var isCorrect = q.correct.indexOf(opt) > -1;
        html += '<div class="balloon" data-correct="' + isCorrect + '" style="background:' + balloonColors[i % balloonColors.length] + ';animation-delay:' + (i * 0.15) + 's">' + escHtml(opt) + '</div>';
    });
    html += '</div>';
    gc.innerHTML = html;

    gc.querySelectorAll('.balloon').forEach(function(b) {
        b.addEventListener('click', function() {
            if (this.classList.contains('popped') || this.classList.contains('wrong-pop')) return;
            if (this.dataset.correct === 'true') {
                this.classList.add('popped');
                addScore(1);
                correctRemaining--;
                if (correctRemaining <= 0) setTimeout(function() { showBalloonQuestion(currentIndex + 1); }, 700);
            } else {
                this.classList.add('wrong-pop');
            }
        });
    });
}

// ============ MEMORY GAME ============
function initMemoryGame() {
    maxScore = items.length;
    var cards = [];
    items.forEach(function(pair, i) {
        cards.push({text: pair.a, pairIdx: i});
        cards.push({text: pair.b, pairIdx: i});
    });
    cards = shuffle(cards);

    var gc = document.getElementById('gameContent');
    var html = '<div class="memory-grid">';
    cards.forEach(function(c, i) {
        html += '<div class="memory-card" data-pair="' + c.pairIdx + '" data-card="' + i + '">' +
            '<div class="card-inner">' +
            '<div class="card-front"><span class="card-back-icon"><i class="fas fa-question"></i></span></div>' +
            '<div class="card-back-face">' + escHtml(c.text) + '</div>' +
            '</div></div>';
    });
    html += '</div><div class="memory-attempts" id="memAttempts"><i class="fas fa-mouse-pointer me-1"></i>المحاولات: 0</div>';
    gc.innerHTML = html;

    var flippedCards = [];
    var matchedPairs = 0;
    var memoryLock = false;
    var attempts = 0;

    gc.querySelectorAll('.memory-card').forEach(function(card) {
        card.addEventListener('click', function() {
            if (memoryLock || this.classList.contains('flipped') || this.classList.contains('matched')) return;
            this.classList.add('flipped');
            flippedCards.push(this);

            if (flippedCards.length === 2) {
                memoryLock = true;
                attempts++;
                document.getElementById('memAttempts').innerHTML = '<i class="fas fa-mouse-pointer me-1"></i>المحاولات: ' + attempts;
                var c1 = flippedCards[0], c2 = flippedCards[1];
                if (c1.dataset.pair === c2.dataset.pair) {
                    c1.classList.add('matched');
                    c2.classList.add('matched');
                    addScore(1);
                    matchedPairs++;
                    flippedCards = [];
                    memoryLock = false;
                    if (matchedPairs === items.length) setTimeout(endGame, 700);
                } else {
                    setTimeout(function() {
                        c1.classList.remove('flipped');
                        c2.classList.remove('flipped');
                        flippedCards = [];
                        memoryLock = false;
                    }, 900);
                }
            }
        });
    });
}

// ============ END GAME ============
function endGame() {
    if (gameEnded) return;
    gameEnded = true;
    clearInterval(timerInterval);
    var elapsed = Math.floor((Date.now() - startTime) / 1000);
    var pct = maxScore > 0 ? Math.round((score / maxScore) * 100) : 100;

    document.getElementById('gameScreen').style.display = 'none';
    document.getElementById('resultsScreen').style.display = 'block';

    var color = pct >= 80 ? '#10b981' : (pct >= 50 ? '#f59e0b' : '#ef4444');
    document.getElementById('scoreCircle').style.background = 'linear-gradient(135deg,' + color + ',' + color + '88)';
    document.getElementById('finalScore').textContent = pct + '%';
    document.getElementById('finalScoreLabel').textContent = score + ' / ' + maxScore;

    var m = Math.floor(elapsed / 60), s = elapsed % 60;
    document.getElementById('finalTime').innerHTML = '<i class="fas fa-clock me-1"></i>' + m + ':' + (s < 10 ? '0' : '') + s;
    document.getElementById('finalCorrect').innerHTML = '<i class="fas fa-check me-1"></i>' + score + ' صحيحة';

    // Stars
    var stars = '';
    var starCount = pct >= 90 ? 3 : (pct >= 60 ? 2 : (pct >= 30 ? 1 : 0));
    for (var i = 0; i < 3; i++) {
        stars += '<i class="fas fa-star" style="color:' + (i < starCount ? '#f59e0b' : 'rgba(255,255,255,.15)') + ';margin:0 4px"></i>';
    }
    document.getElementById('resultStars').innerHTML = stars;

    var messages = {
        high: ['🏆 ممتاز! أداء رائع!', '🌟 مبدع! نتيجة مذهلة!', '💫 عبقري! أداء استثنائي!'],
        mid: ['👏 جيد جداً! استمر!', '💪 أحسنت! حاول تحسين نتيجتك!', '👍 عمل جيد!'],
        low: ['📚 لا بأس، حاول مرة أخرى!', '💡 يمكنك التحسن، حاول مجدداً!', '🎯 ركز أكثر في المرة القادمة!']
    };
    var msgArr = pct >= 80 ? messages.high : (pct >= 50 ? messages.mid : messages.low);
    document.getElementById('finalMessage').textContent = msgArr[Math.floor(Math.random() * msgArr.length)];

    // Confetti for high scores
    if (pct >= 70) spawnConfetti();

    // Submit result
    if (activityData && activityData.id && !isPreview) {
        fetch('play_activity.php?code=<?php echo rawurlencode((string)($activity['code'] ?? '')); ?>&submit_result=1', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                csrf_token: <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>,
                activity_id: activityData.id,
                player_name: playerName,
                score: score,
                max_score: maxScore,
                time_spent: elapsed,
                answers: []
            })
        }).catch(function(){});
    }
}

function spawnConfetti() {
    var colors = ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899'];
    for (var i = 0; i < 40; i++) {
        var el = document.createElement('div');
        el.className = 'confetti-piece';
        el.style.cssText = 'left:' + Math.random()*100 + '%;width:' + (Math.random()*8+6) + 'px;height:' + (Math.random()*8+6) + 'px;background:' + colors[i%colors.length] + ';border-radius:' + (Math.random()>.5?'50%':'2px') + ';animation-duration:' + (Math.random()*2+2) + 's;animation-delay:' + (Math.random()*1.5) + 's';
        document.body.appendChild(el);
        setTimeout(function(e) { e.remove(); }.bind(null, el), 5000);
    }
}

function restartGame() {
    document.getElementById('resultsScreen').style.display = 'none';
    document.getElementById('nameScreen').style.display = 'block';
    document.getElementById('hudProgress').style.display = '';
    document.querySelector('.progress-bar-custom').style.display = '';
    document.getElementById('hudScore').style.display = '';
    score = 0;
    maxScore = 0;
    currentIndex = 0;
    gameEnded = false;
    wheelAngle = 0;
    wheelSpinning = false;
}
</script>
</body>
</html>
