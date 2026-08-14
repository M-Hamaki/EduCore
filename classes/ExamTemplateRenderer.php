<?php

declare(strict_types=1);

final class ExamTemplateRenderer
{
    public function render(
        $title,
        $dir,
        $modelsJson,
        $texts,
        $singleModel,
        $duration,
        $passingPercentage,
        $antiCheatEnabled,
        $studentInfoEnabled,
        $language,
        $theme,
        $themeCss
    ) {
        $safeTitle = htmlspecialchars(
            (string) $title,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $textsJson = json_encode(
            $texts,
            JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );
        $singleModelJs = $singleModel ? 'true' : 'false';
        $antiCheatJs = $antiCheatEnabled ? 'true' : 'false';
        $studentInfoJs = $studentInfoEnabled ? 'true' : 'false';
        $timerInitDisplay = ($duration == 0) ? '00:00' : "{$duration}:00";

        // نصوص بيانات الطالب
        if ($language === 'ar') {
            $studentNameLabel = 'اسم الطالب';
            $studentClassLabel = 'الفصل';
            $startExamLabel = 'بدء الاختبار';
            $studentNamePlaceholder = 'أدخل اسمك الكامل';
            $studentClassPlaceholder = 'مثال: 3/أ';
            $examSystemEyebrow = 'منظومة الاختبارات الإلكترونية الذكية · EduCore';
            $resetAnswersLabel = 'إعادة تعيين الإجابات';
            $printResultsLabel = 'طباعة النتيجة';
        } elseif ($language === 'fr') {
            $studentNameLabel = 'Nom de l\'élève';
            $studentClassLabel = 'Classe';
            $startExamLabel = 'Commencer l\'examen';
            $studentNamePlaceholder = 'Entrez votre nom complet';
            $studentClassPlaceholder = 'Exemple: 3/A';
            $examSystemEyebrow = 'Système d\'évaluation interactif · EduCore';
            $resetAnswersLabel = 'Réinitialiser';
            $printResultsLabel = 'Imprimer le résultat';
        } else {
            $studentNameLabel = 'Student Name';
            $studentClassLabel = 'Class';
            $startExamLabel = 'Start Exam';
            $studentNamePlaceholder = 'Enter your full name';
            $studentClassPlaceholder = 'Example: 3/A';
            $examSystemEyebrow = 'Interactive Assessment System · EduCore';
            $resetAnswersLabel = 'Reset Answers';
            $printResultsLabel = 'Print Result';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="{$language}" dir="{$dir}" data-theme="{$theme}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>{$safeTitle}</title>
    <!-- Google Fonts & Font Awesome -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        {$themeCss}

        :root {
            --font-family: 'Cairo', 'Inter', system-ui, -apple-system, sans-serif;
            --page-bg: #f8fafc;
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --color-correct: #059669;
            --color-correct-bg: #ecfdf5;
            --color-correct-border: #a7f3d0;
            --color-wrong: #dc2626;
            --color-wrong-bg: #fef2f2;
            --color-wrong-border: #fecaca;
            --color-warn: #d97706;
            --color-warn-bg: #fffbeb;
            --color-warn-border: #fde68a;
            --card-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.06), 0 8px 10px -6px rgba(15, 23, 42, 0.04);
            --card-hover-shadow: 0 20px 30px -10px rgba(15, 23, 42, 0.12);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: var(--font-family);
            background: var(--page-bg);
            color: var(--text-main);
            min-height: 100vh;
            direction: {$dir};
            line-height: 1.6;
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            background-image: 
                radial-gradient(at 0% 0%, rgba(99, 102, 241, 0.08) 0px, transparent 50%),
                radial-gradient(at 100% 0%, rgba(59, 130, 246, 0.06) 0px, transparent 50%),
                radial-gradient(at 50% 100%, rgba(241, 245, 249, 0.5) 0px, transparent 50%);
        }

        /* ===== Header ===== */
        .app-header {
            background: var(--exam-header);
            color: #ffffff;
            padding: 24px 20px 28px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            position: relative;
            z-index: 100;
            overflow: visible;
        }

        .app-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #10b981, #3b82f6, #8b5cf6, #f59e0b);
        }

        .header-inner {
            max-width: 960px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }

        .header-meta {
            flex: 1;
            min-width: 280px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #fde68a;
            margin-bottom: 6px;
        }

        .exam-title {
            font-size: clamp(1.4rem, 4vw, 2rem);
            font-weight: 800;
            line-height: 1.25;
            color: #ffffff;
        }

        .header-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* Timer Badge */
        .timer-badge {
            height: 42px;
            box-sizing: border-box;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.25);
            padding: 0 16px;
            border-radius: 12px;
            backdrop-filter: blur(8px);
            color: #ffffff;
            font-weight: 700;
            line-height: 1;
        }

        .timer-badge .timer {
            font-size: 1.15rem;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
            letter-spacing: 0.5px;
            line-height: 1;
        }

        .timer-badge.warning {
            background: rgba(245, 158, 11, 0.3);
            border-color: #f59e0b;
            color: #fef08a;
            animation: pulse-warn 1s infinite;
        }

        .timer-badge.danger {
            background: rgba(239, 68, 68, 0.35);
            border-color: #ef4444;
            color: #fca5a5;
            animation: pulse-warn 0.5s infinite;
        }

        @keyframes pulse-warn {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.03); }
        }

        /* Model Badge / Switcher */
        .model-switcher {
            position: relative;
            display: inline-flex;
            align-items: center;
            z-index: 105;
        }

        .model-badge-btn {
            height: 42px;
            box-sizing: border-box;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #ffffff;
            padding: 0 16px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
            backdrop-filter: blur(8px);
            transition: all 0.2s ease;
            line-height: 1;
        }

        .model-badge-btn:hover {
            background: rgba(255, 255, 255, 0.28);
            transform: translateY(-1px);
        }

        .model-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            background: #ffffff;
            border-radius: 14px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.25);
            min-width: 150px;
            z-index: 9999;
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }

        [dir="ltr"] .model-dropdown {
            right: auto;
            left: 0;
        }

        .model-dropdown.show {
            display: block;
            animation: dropFade 0.2s ease;
        }

        @keyframes dropFade {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .model-dropdown-item {
            display: block;
            width: 100%;
            padding: 12px 18px;
            text-align: inherit;
            border: none;
            background: transparent;
            color: #1e293b;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .model-dropdown-item:hover {
            background: #f1f5f9;
            color: var(--exam-primary, #3b82f6);
        }

        .model-dropdown-item.active {
            background: var(--exam-primary-light, #eff6ff);
            color: var(--exam-primary-dark, #1d4ed8);
            font-weight: 800;
        }

        /* ===== Main Container ===== */
        main.exam-main {
            max-width: 960px;
            margin: 0 auto;
            padding: 20px 16px 80px;
        }

        /* ===== Intro & Progress Card ===== */
        .exam-intro-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 18px;
            padding: 20px 24px;
            margin-bottom: 24px;
            box-shadow: var(--card-shadow);
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .intro-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .intro-title {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .student-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 700;
            color: #334155;
        }

        .progress-track-wrap {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 14px;
            align-items: center;
        }

        .progress-track {
            height: 12px;
            background: #e2e8f0;
            border-radius: 999px;
            overflow: hidden;
            position: relative;
        }

        .progress-bar-fill {
            height: 100%;
            width: 0%;
            background: var(--exam-progress, linear-gradient(90deg, #10b981, #059669));
            border-radius: inherit;
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .progress-label-text {
            font-size: 0.92rem;
            font-weight: 800;
            color: var(--exam-primary-dark, #1e3a8a);
            min-width: 70px;
            text-align: end;
        }

        /* ===== Section Title ===== */
        .section-separator {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 32px 0 18px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
        }

        .section-separator:first-child {
            margin-top: 0;
        }

        .section-separator h2 {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--exam-heading, #1e3a8a);
        }

        .section-separator .badge-count {
            background: #e0f2fe;
            color: #0369a1;
            font-size: 0.78rem;
            font-weight: 800;
            padding: 3px 10px;
            border-radius: 12px;
        }

        /* ===== Question Cards ===== */
        .question-card {
            background: var(--card-bg);
            border: 2px solid var(--card-border);
            border-radius: 18px;
            padding: 22px;
            margin-bottom: 18px;
            box-shadow: var(--card-shadow);
            scroll-margin-top: 90px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
        }

        .question-card:hover {
            border-color: #cbd5e1;
            box-shadow: var(--card-hover-shadow);
        }

        .question-card.answered {
            border-color: #93c5fd;
            background-color: #f8fafc;
        }

        .question-header-row {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 16px;
        }

        .question-num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            min-width: 32px;
            min-height: 32px;
            border-radius: 10px;
            background: var(--exam-badge, linear-gradient(135deg, #3b82f6, #1d4ed8));
            color: #ffffff;
            font-weight: 900;
            font-size: 0.95rem;
            box-shadow: 0 4px 10px rgba(59, 130, 246, 0.25);
            flex-shrink: 0;
            margin-top: 2px;
        }

        .question-text {
            font-size: 1.06rem;
            font-weight: 800;
            color: var(--text-main);
            line-height: 1.6;
            margin: 0;
            flex: 1;
        }

        /* ===== Options Layout ===== */
        .options-grid {
            display: grid;
            gap: 10px;
        }

        .option-card {
            display: grid;
            grid-template-columns: 24px 1fr;
            gap: 12px;
            align-items: start;
            border: 1.5px solid var(--card-border);
            border-radius: 14px;
            padding: 13px 16px;
            background: #ffffff;
            cursor: pointer;
            line-height: 1.45;
            transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1);
            -webkit-tap-highlight-color: transparent;
        }

        .option-card:hover {
            border-color: var(--exam-primary, #3b82f6);
            background: var(--exam-primary-hover, #f8fafc);
            transform: translateY(-1px);
        }

        .option-card:active {
            transform: scale(0.995);
        }

        .option-indicator {
            width: 20px;
            height: 20px;
            border: 2px solid #94a3b8;
            border-radius: 50%;
            margin-top: 2px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s ease;
            flex-shrink: 0;
        }

        .option-card.selected {
            border-color: var(--exam-primary, #3b82f6);
            background: var(--exam-primary-light, #eff6ff);
            box-shadow: 0 0 0 1px var(--exam-primary, #3b82f6);
        }

        .option-card.selected .option-indicator {
            border-color: var(--exam-primary, #3b82f6);
            background: var(--exam-primary, #3b82f6);
        }

        .option-card.selected .option-indicator::after {
            content: '';
            width: 7px;
            height: 7px;
            background: #ffffff;
            border-radius: 50%;
        }

        .option-text {
            font-size: 0.98rem;
            font-weight: 600;
            color: #334155;
        }

        .option-card.selected .option-text {
            color: #0f172a;
            font-weight: 700;
        }

        /* ===== True / False Cards ===== */
        .tf-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .tf-card {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 14px;
            border: 2px solid var(--card-border);
            border-radius: 14px;
            font-size: 1rem;
            font-weight: 800;
            cursor: pointer;
            background: #ffffff;
            transition: all 0.18s ease;
        }

        .tf-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .tf-card.true-card:hover {
            border-color: #10b981;
            color: #047857;
            background: #f0fdf4;
        }

        .tf-card.false-card:hover {
            border-color: #ef4444;
            color: #b91c1c;
            background: #fef2f2;
        }

        .tf-card.selected.true-card {
            background: #dcfce7;
            border-color: #10b981;
            color: #047857;
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.2);
        }

        .tf-card.selected.false-card {
            background: #fee2e2;
            border-color: #ef4444;
            color: #991b1b;
            box-shadow: 0 4px 14px rgba(239, 68, 68, 0.2);
        }

        /* ===== Essay Input ===== */
        .essay-textarea {
            width: 100%;
            border: 2px solid var(--card-border);
            border-radius: 14px;
            padding: 16px;
            font-size: 1rem;
            font-family: inherit;
            color: var(--text-main);
            background: #ffffff;
            resize: vertical;
            min-height: 130px;
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .essay-textarea:focus {
            border-color: var(--exam-primary, #3b82f6);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.12);
        }

        /* ===== Feedback Box in Review Mode ===== */
        .feedback-box {
            display: none;
            margin-top: 14px;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 0.94rem;
            line-height: 1.5;
            font-weight: 600;
        }

        .feedback-box.show {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        .feedback-box.correct {
            background: var(--color-correct-bg);
            border: 1px solid var(--color-correct-border);
            color: var(--color-correct);
        }

        .feedback-box.incorrect {
            background: var(--color-wrong-bg);
            border: 1px solid var(--color-wrong-border);
            color: var(--color-wrong);
        }

        .feedback-box.warn {
            background: var(--color-warn-bg);
            border: 1px solid var(--color-warn-border);
            color: var(--color-warn);
        }

        /* Review Mode Option Highlights */
        .question-card.correct {
            border-color: #6ec89a !important;
            background: var(--color-correct-bg) !important;
        }

        .question-card.incorrect {
            border-color: #e89a95 !important;
            background: var(--color-wrong-bg) !important;
        }

        .question-card.unanswered-flag {
            border-color: #fde68a !important;
            background: var(--color-warn-bg) !important;
        }

        .option-card.correct-answer,
        .tf-card.correct-answer {
            border-color: var(--color-correct) !important;
            background: #d1fae5 !important;
            color: #065f46 !important;
            font-weight: 800 !important;
        }

        .option-card.wrong-selection,
        .tf-card.wrong-selection {
            border-color: var(--color-wrong) !important;
            background: #fee2e2 !important;
            color: #991b1b !important;
            font-weight: 800 !important;
        }

        /* ===== Sticky Bottom Action Panel ===== */
        .action-panel {
            position: sticky;
            bottom: max(16px, env(safe-area-inset-bottom));
            z-index: 90;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.94);
            box-shadow: 0 15px 35px rgba(15, 23, 42, 0.12);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            margin-top: 24px;
        }

        .action-panel-meta {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .action-timer {
            font-weight: 800;
            font-size: 1.1rem;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .action-panel-buttons {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-submit-exam,
        .btn-reset-exam,
        .btn-show-answers,
        .btn-export-excel,
        .btn-print-results {
            border: none;
            border-radius: 12px;
            min-height: 46px;
            padding: 11px 22px;
            font-weight: 800;
            font-size: 0.95rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            font-family: inherit;
        }

        .btn-submit-exam {
            color: #ffffff;
            background: var(--exam-submit, linear-gradient(135deg, #10b981, #059669));
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.35);
        }

        .btn-submit-exam:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.45);
        }

        .btn-reset-exam {
            color: #475569;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
        }

        .btn-reset-exam:hover {
            background: #e2e8f0;
            color: #1e293b;
        }

        /* ===== Results Box ===== */
        .result-box-container {
            display: none;
            background: var(--exam-header);
            color: #ffffff;
            border-radius: 22px;
            padding: 32px 24px;
            margin-bottom: 24px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.18);
            text-align: center;
            animation: zoomFade 0.4s ease;
        }

        .result-box-container.show {
            display: block;
        }

        @keyframes zoomFade {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }

        .result-score-title {
            font-size: clamp(2.2rem, 6vw, 3.2rem);
            font-weight: 900;
            line-height: 1.1;
            margin-bottom: 8px;
        }

        .result-score-percent {
            font-size: 1.3rem;
            font-weight: 800;
            color: #fde68a;
            margin-bottom: 16px;
        }

        .result-status-badge {
            display: inline-block;
            padding: 8px 24px;
            border-radius: 999px;
            font-size: 1.1rem;
            font-weight: 800;
            margin-bottom: 20px;
        }

        .result-status-badge.passed {
            background: #dcfce7;
            color: #065f46;
        }

        .result-status-badge.failed {
            background: #fee2e2;
            color: #991b1b;
        }

        .result-stats-pills {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 24px;
        }

        .stat-pill {
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.25);
            padding: 6px 14px;
            border-radius: 12px;
            font-size: 0.9rem;
            font-weight: 700;
        }

        .result-actions {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .btn-show-answers {
            background: #ffffff;
            color: var(--exam-primary-dark, #1e3a8a);
        }

        .btn-show-answers:hover {
            background: #f8fafc;
            transform: translateY(-2px);
        }

        .btn-export-excel {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #ffffff;
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.35);
        }

        .btn-export-excel:hover {
            transform: translateY(-2px);
        }

        .btn-print-results {
            background: rgba(255, 255, 255, 0.2);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .btn-print-results:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        /* ===== Student Gateway Overlay ===== */
        .student-info-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 3000;
            padding: 16px;
        }

        .student-info-overlay.hidden {
            display: none;
        }

        .student-info-container {
            background: #ffffff;
            border-radius: 22px;
            padding: 36px 30px;
            max-width: 480px;
            width: 100%;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.25);
            animation: zoomFade 0.3s ease;
        }

        .student-info-title {
            font-size: 1.4rem;
            font-weight: 800;
            color: #0f172a;
            text-align: center;
            margin-bottom: 6px;
        }

        .student-info-subtitle {
            font-size: 0.9rem;
            color: #64748b;
            text-align: center;
            margin-bottom: 24px;
        }

        .student-form-group {
            margin-bottom: 18px;
        }

        .student-form-group label {
            display: block;
            font-weight: 700;
            font-size: 0.92rem;
            color: #334155;
            margin-bottom: 6px;
        }

        .student-form-group input {
            width: 100%;
            padding: 13px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 1rem;
            font-family: inherit;
            color: #0f172a;
            outline: none;
            transition: all 0.2s ease;
        }

        .student-form-group input:focus {
            border-color: var(--exam-primary, #3b82f6);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.12);
        }

        .student-form-group input.error {
            border-color: #ef4444;
        }

        .student-submit-btn {
            width: 100%;
            padding: 15px;
            background: var(--exam-submit, linear-gradient(135deg, #10b981, #059669));
            color: #ffffff;
            border: none;
            border-radius: 12px;
            font-size: 1.05rem;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.3);
            font-family: inherit;
        }

        .student-submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
        }

        /* ===== Warning Banner & Modals ===== */
        .warning-banner {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #ffffff;
            padding: 14px 20px;
            text-align: center;
            font-weight: 800;
            font-size: 0.95rem;
            z-index: 4000;
            box-shadow: 0 6px 20px rgba(220, 38, 38, 0.3);
            animation: slideDown 0.3s ease;
        }

        .warning-banner.show {
            display: block;
        }

        @keyframes slideDown {
            from { transform: translateY(-100%); }
            to { transform: translateY(0); }
        }

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(6px);
            z-index: 3500;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .modal-overlay.show {
            display: flex;
        }

        .modal-content {
            background: #ffffff;
            border-radius: 20px;
            max-width: 460px;
            width: 100%;
            padding: 32px 24px;
            text-align: center;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.2);
            animation: zoomFade 0.25s ease;
        }

        .modal-icon {
            font-size: 3rem;
            margin-bottom: 12px;
        }

        .modal-title {
            font-size: 1.35rem;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 8px;
        }

        .modal-text {
            color: #64748b;
            font-size: 0.95rem;
            margin-bottom: 22px;
            line-height: 1.6;
        }

        .modal-buttons {
            display: flex;
            justify-content: center;
            gap: 12px;
        }

        .modal-btn {
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 800;
            font-size: 0.92rem;
            cursor: pointer;
            border: none;
            font-family: inherit;
            transition: all 0.2s ease;
        }

        .modal-btn.primary {
            background: #ef4444;
            color: #ffffff;
        }

        .modal-btn.secondary {
            background: #f1f5f9;
            color: #475569;
        }

        .modal-btn:hover {
            transform: translateY(-1px);
        }

        /* ===== Mobile Responsiveness ===== */
        @media (max-width: 640px) {
            .app-header {
                padding: 18px 14px 22px;
            }

            .header-inner {
                flex-direction: column;
                align-items: stretch;
            }

            .header-controls {
                justify-content: space-between;
            }

            main.exam-main {
                padding: 14px 10px 90px;
            }

            .question-card {
                padding: 16px;
                border-radius: 15px;
            }

            .tf-grid {
                grid-template-columns: 1fr;
            }

            .action-panel {
                padding: 10px 14px;
            }

            .btn-submit-exam, .btn-reset-exam {
                padding: 10px 14px;
                font-size: 0.88rem;
            }
        }

        @media print {
            .app-header,
            .action-panel,
            .result-actions,
            .student-info-overlay,
            .model-switcher {
                display: none !important;
            }

            body {
                background: #ffffff;
                color: #000000;
            }

            .question-card, .result-box-container {
                box-shadow: none !important;
                border: 1px solid #cbd5e1 !important;
                break-inside: avoid;
            }
        }
    </style>
</head>
<body>

    <!-- Student Info Gateway -->
    <div class="student-info-overlay" id="studentInfoOverlay">
        <div class="student-info-container">
            <h2 class="student-info-title"><i class="fas fa-graduation-cap text-primary me-2"></i> {$safeTitle}</h2>
            <p class="student-info-subtitle">{$examSystemEyebrow}</p>
            <form id="studentInfoForm" onsubmit="submitStudentInfo(event)">
                <div class="student-form-group">
                    <label for="studentName"><i class="far fa-user me-1"></i> {$studentNameLabel} *</label>
                    <input type="text" id="studentName" placeholder="{$studentNamePlaceholder}" required autocomplete="off">
                </div>
                <div class="student-form-group">
                    <label for="studentClass"><i class="fas fa-chalkboard-user me-1"></i> {$studentClassLabel} *</label>
                    <input type="text" id="studentClass" placeholder="{$studentClassPlaceholder}" required autocomplete="off">
                </div>
                <button type="submit" class="student-submit-btn">
                    <i class="fas fa-play me-2"></i> {$startExamLabel}
                </button>
            </form>
        </div>
    </div>

    <!-- Warning Banner -->
    <div class="warning-banner" id="warningBanner"></div>

    <!-- Confirm Submit Modal -->
    <div class="modal-overlay" id="confirmModal">
        <div class="modal-content">
            <div class="modal-icon">⚠️</div>
            <h2 class="modal-title" id="modalTitle"></h2>
            <p class="modal-text" id="modalText"></p>
            <div class="modal-buttons">
                <button class="modal-btn secondary" onclick="closeModal()">{$texts['go_back']}</button>
                <button class="modal-btn primary" onclick="forceSubmit()">{$texts['force_submit']}</button>
            </div>
        </div>
    </div>

    <!-- Reset Answers Modal -->
    <div class="modal-overlay" id="resetModal">
        <div class="modal-content">
            <div class="modal-icon">🔄</div>
            <h2 class="modal-title">{$resetAnswersLabel}</h2>
            <p class="modal-text">{$texts['change_model_warning']}</p>
            <div class="modal-buttons">
                <button class="modal-btn secondary" onclick="closeResetModal()">{$texts['go_back']}</button>
                <button class="modal-btn primary" onclick="executeReset()">{$resetAnswersLabel}</button>
            </div>
        </div>
    </div>

    <!-- Header -->
    <header class="app-header">
        <div class="header-inner">
            <div class="header-meta">
                <div class="eyebrow"><i class="fas fa-award"></i> {$examSystemEyebrow}</div>
                <h1 class="exam-title">{$safeTitle}</h1>
            </div>
            <div class="header-controls">
                <div class="model-switcher">
                    <button type="button" class="model-badge-btn" id="modelBadge" onclick="toggleModelDropdown()">
                        <i class="fas fa-layer-group"></i> <span>{$texts['model']}: -</span> <i class="fas fa-chevron-down" style="font-size:0.75rem;"></i>
                    </button>
                    <div class="model-dropdown" id="modelDropdown"></div>
                </div>
                <div class="timer-badge" id="timerBadge">
                    <i class="far fa-clock"></i>
                    <span class="timer" id="timer">{$timerInitDisplay}</span>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="exam-main" id="examContainer" style="display: none;">
        
        <!-- Results Box (Shown on submit) -->
        <div class="result-box-container" id="resultsContainer" tabindex="-1" aria-live="polite">
            <div class="result-score-title" id="resultScore">0 / 0</div>
            <div class="result-score-percent" id="resultPercentage">0%</div>
            <div class="result-status-badge" id="resultStatus">{$texts['failed']}</div>
            
            <div class="result-stats-pills" id="resultStatsPills">
                <!-- Injected via JS -->
            </div>

            <div class="result-actions">
                <button type="button" class="btn-show-answers" onclick="showAnswers()">
                    <i class="fas fa-check-double me-1"></i> {$texts['show_answers']}
                </button>
                <button type="button" class="btn-export-excel" id="exportExcelBtn" onclick="exportToExcel()">
                    <i class="fas fa-file-excel me-1"></i> {$texts['export_excel']}
                </button>
                <button type="button" class="btn-print-results" onclick="window.print()">
                    <i class="fas fa-print me-1"></i> {$printResultsLabel}
                </button>
            </div>
            <p id="exportWarningText" style="color: #fef08a; font-weight: 700; margin-top: 14px; font-size: 0.9rem;">
                ⚠️ {$texts['export_warning']}
            </p>
            <p id="exportSuccessText" style="display: none; color: #a7f3d0; font-weight: 700; margin-top: 14px; font-size: 0.95rem;">
                ✅ {$texts['exported_success']}
            </p>
        </div>

        <!-- Overview & Live Progress Card -->
        <div class="exam-intro-card" id="introCard">
            <div class="intro-header">
                <div class="intro-title">
                    <i class="fas fa-tasks text-primary"></i>
                    <span>{$texts['progress']}</span>
                </div>
                <div class="student-chip" id="studentDisplayChip" style="display:none;">
                    <i class="fas fa-user-graduate text-primary"></i>
                    <span id="studentDisplayName"></span>
                </div>
            </div>
            <div class="progress-track-wrap">
                <div class="progress-track">
                    <div class="progress-bar-fill" id="progressFill"></div>
                </div>
                <div class="progress-label-text" id="progressText">0 / 0</div>
            </div>
        </div>

        <!-- Questions List Container -->
        <div id="examBody"></div>

        <!-- Sticky Bottom Action Panel -->
        <div class="action-panel" id="actionPanel">
            <div class="action-panel-meta">
                <div class="action-timer">
                    <i class="far fa-clock text-primary"></i>
                    <span id="panelTimer">{$timerInitDisplay}</span>
                </div>
            </div>
            <div class="action-panel-buttons">
                <button type="button" class="btn-reset-exam" onclick="promptReset()">
                    <i class="fas fa-rotate-left"></i> {$resetAnswersLabel}
                </button>
                <button type="button" class="btn-submit-exam" id="submitBtn" onclick="confirmSubmit()">
                    <i class="fas fa-paper-plane"></i> {$texts['submit']}
                </button>
            </div>
        </div>

    </main>

    <script>
        "use strict";

        // Configuration
        const EXAM_DURATION = {$duration} * 60; // seconds (0 = unlimited)
        const UNLIMITED_TIME = ({$duration} === 0);
        const PASSING_PERCENTAGE = {$passingPercentage};
        const MAX_VIOLATIONS = 3;
        const TEXTS = {$textsJson};
        const MODELS = {$modelsJson};
        const SINGLE_MODEL = {$singleModelJs};
        const ANTI_CHEAT_ENABLED = {$antiCheatJs};
        const STUDENT_INFO_ENABLED = {$studentInfoJs};

        // State
        let currentModel = null;
        let questions = [];
        let answers = {};
        let timeRemaining = UNLIMITED_TIME ? 0 : EXAM_DURATION;
        let elapsedTime = 0;
        let timerInterval = null;
        let violations = 0;
        let examSubmitted = false;
        let examStarted = false;
        let studentName = '';
        let studentClass = '';
        let gradeExported = false;
        let examScore = { correct: 0, total: 0, percentage: 0, passed: false, essayCount: 0 };

        // Prevent page close before exporting grade
        window.addEventListener('beforeunload', function(e) {
            if (examSubmitted && !gradeExported) {
                const msg = TEXTS.export_warning || 'يجب تصدير الدرجة أولاً قبل إغلاق الصفحة';
                e.preventDefault();
                e.returnValue = msg;
                return msg;
            }
        });

        // Student Info Gateway Form
        function submitStudentInfo(event) {
            event.preventDefault();
            const nameInput = document.getElementById('studentName');
            const classInput = document.getElementById('studentClass');

            studentName = nameInput.value.trim();
            studentClass = classInput.value.trim();

            if (!studentName || !studentClass) {
                if (!studentName) nameInput.classList.add('error');
                if (!studentClass) classInput.classList.add('error');
                return;
            }

            document.getElementById('studentInfoOverlay').classList.add('hidden');
            document.getElementById('examContainer').style.display = 'block';

            if (studentName) {
                const chip = document.getElementById('studentDisplayChip');
                const nameDisplay = document.getElementById('studentDisplayName');
                if (chip && nameDisplay) {
                    nameDisplay.textContent = studentName + (studentClass ? ' (' + studentClass + ')' : '');
                    chip.style.display = 'inline-flex';
                }
            }

            initExam();
        }

        // Initialize on page load
        window.onload = function() {
            if (STUDENT_INFO_ENABLED) {
                document.getElementById('studentInfoOverlay').classList.remove('hidden');
                document.getElementById('examContainer').style.display = 'none';
            } else {
                document.getElementById('studentInfoOverlay').classList.add('hidden');
                document.getElementById('examContainer').style.display = 'block';
                initExam();
            }
        };

        // Model Switcher
        function toggleModelDropdown() {
            if (examSubmitted) return;
            const dropdown = document.getElementById('modelDropdown');
            dropdown.classList.toggle('show');

            const modelKeys = Object.keys(MODELS);
            dropdown.innerHTML = modelKeys.map(key =>
                '<button type="button" class="model-dropdown-item ' + (key === currentModel ? 'active' : '') + '" onclick="switchModel(\'' + key + '\')">' +
                '<i class="fas fa-file-signature me-1"></i> ' + TEXTS.model + ' ' + key +
                '</button>'
            ).join('');
        }

        function switchModel(newModel) {
            if (examSubmitted || !MODELS[newModel]) return;

            const answeredCount = Object.keys(answers).length;
            if (answeredCount > 0) {
                if (!confirm(TEXTS.change_model_warning || 'تحذير: سيتم مسح إجاباتك الحالية. هل تريد المتابعة؟')) {
                    document.getElementById('modelDropdown').classList.remove('show');
                    return;
                }
            }

            currentModel = newModel;
            questions = MODELS[currentModel];
            answers = {};

            const badgeBtn = document.getElementById('modelBadge');
            if (badgeBtn) {
                badgeBtn.innerHTML = '<i class="fas fa-layer-group"></i> <span>' + TEXTS.model + ': ' + currentModel + '</span> <i class="fas fa-chevron-down" style="font-size:0.75rem;"></i>';
            }
            document.getElementById('modelDropdown').classList.remove('show');

            renderQuestions();
            updateProgress();
        }

        document.addEventListener('click', function(e) {
            const dropdown = document.getElementById('modelDropdown');
            const badge = document.getElementById('modelBadge');
            if (dropdown && badge && !dropdown.contains(e.target) && !badge.contains(e.target)) {
                dropdown.classList.remove('show');
            }
        });

        // Initialize Exam
        function initExam() {
            const modelKeys = Object.keys(MODELS);
            if (SINGLE_MODEL || modelKeys.length === 1) {
                currentModel = modelKeys[0];
            } else {
                currentModel = modelKeys[Math.floor(Math.random() * modelKeys.length)];
            }
            questions = MODELS[currentModel] || [];

            const badgeBtn = document.getElementById('modelBadge');
            if (badgeBtn) {
                badgeBtn.innerHTML = '<i class="fas fa-layer-group"></i> <span>' + TEXTS.model + ': ' + currentModel + '</span> <i class="fas fa-chevron-down" style="font-size:0.75rem;"></i>';
            }

            renderQuestions();
            updateProgress();
            startTimer();
            setupAntiCheat();
            examStarted = true;
        }

        // Render Questions
        function renderQuestions() {
            const container = document.getElementById('examBody');
            let html = '';
            let mcCounter = 0;
            let tfCounter = 0;
            let essayCounter = 0;

            const hasMC = questions.some(q => q.type === 'multiple_choice');
            const hasTF = questions.some(q => q.type === 'true_false');
            const hasEssay = questions.some(q => q.type === 'essay');

            if (hasMC) {
                const count = questions.filter(q => q.type === 'multiple_choice').length;
                html += '<div class="section-separator">' +
                    '<h2><i class="fas fa-list-ul text-primary me-2"></i>' + TEXTS.multiple_choice + '</h2>' +
                    '<span class="badge-count">' + count + '</span>' +
                    '</div>';
                questions.forEach((q, index) => {
                    if (q.type === 'multiple_choice') {
                        mcCounter++;
                        html += renderMCQuestion(q, index, mcCounter);
                    }
                });
            }

            if (hasTF) {
                const count = questions.filter(q => q.type === 'true_false').length;
                html += '<div class="section-separator">' +
                    '<h2><i class="fas fa-check-circle text-success me-2"></i>' + TEXTS.true_false + '</h2>' +
                    '<span class="badge-count">' + count + '</span>' +
                    '</div>';
                questions.forEach((q, index) => {
                    if (q.type === 'true_false') {
                        tfCounter++;
                        html += renderTFQuestion(q, index, tfCounter);
                    }
                });
            }

            if (hasEssay) {
                const count = questions.filter(q => q.type === 'essay').length;
                html += '<div class="section-separator">' +
                    '<h2><i class="fas fa-pen-nib text-purple me-2"></i>' + (TEXTS.essay_questions || 'أسئلة مقالية') + '</h2>' +
                    '<span class="badge-count">' + count + '</span>' +
                    '</div>';
                if (TEXTS.essay_note) {
                    html += '<div style="background:#fffbeb;color:#92400e;border:1px solid #fde68a;padding:12px 16px;border-radius:12px;margin-bottom:16px;font-size:0.92rem;font-weight:600;"><i class="fas fa-info-circle me-1"></i> ' + TEXTS.essay_note + '</div>';
                }
                questions.forEach((q, index) => {
                    if (q.type === 'essay') {
                        essayCounter++;
                        html += renderEssayQuestion(q, index, essayCounter);
                    }
                });
            }

            container.innerHTML = html;
        }

        function renderMCQuestion(q, index, displayNum) {
            let optionsHtml = '';
            q.options.forEach((opt, optIndex) => {
                optionsHtml +=
                    '<label class="option-card" onclick="selectOption(' + index + ', ' + optIndex + ')" id="opt_' + index + '_' + optIndex + '">' +
                        '<input type="radio" name="q' + index + '" value="' + optIndex + '" style="display:none;">' +
                        '<span class="option-indicator"></span>' +
                        '<span class="option-text">' + opt + '</span>' +
                    '</label>';
            });

            return '<article class="question-card" id="qcard_' + index + '">' +
                '<div class="question-header-row">' +
                    '<span class="question-num">' + displayNum + '</span>' +
                    '<div class="question-text">' + q.question + '</div>' +
                '</div>' +
                '<div class="options-grid">' + optionsHtml + '</div>' +
                '<div class="feedback-box" id="answer_' + index + '"></div>' +
            '</article>';
        }

        function renderTFQuestion(q, index, displayNum) {
            return '<article class="question-card" id="qcard_' + index + '">' +
                '<div class="question-header-row">' +
                    '<span class="question-num">' + displayNum + '</span>' +
                    '<div class="question-text">' + q.question + '</div>' +
                '</div>' +
                '<div class="tf-grid">' +
                    '<div class="tf-card true-card" onclick="selectTF(' + index + ', 1)" id="tf_' + index + '_1">' +
                        '<i class="fas fa-check text-success"></i> ' + TEXTS.true +
                    '</div>' +
                    '<div class="tf-card false-card" onclick="selectTF(' + index + ', 0)" id="tf_' + index + '_0">' +
                        '<i class="fas fa-times text-danger"></i> ' + TEXTS.false +
                    '</div>' +
                '</div>' +
                '<div class="feedback-box" id="answer_' + index + '"></div>' +
            '</article>';
        }

        function renderEssayQuestion(q, index, displayNum) {
            return '<article class="question-card essay-question" id="qcard_' + index + '">' +
                '<div class="question-header-row">' +
                    '<span class="question-num" style="background:linear-gradient(135deg,#7c3aed,#9333ea);">' + displayNum + '</span>' +
                    '<div class="question-text">' + q.question + '</div>' +
                '</div>' +
                '<textarea id="essay_' + index + '" class="essay-textarea" placeholder="' + (TEXTS.write_answer || 'اكتب إجابتك النموذجية هنا...') + '" oninput="essayInput(' + index + ', this.value)"></textarea>' +
                '<div class="feedback-box" id="answer_' + index + '"></div>' +
            '</article>';
        }

        function selectOption(qIndex, optIndex) {
            if (examSubmitted) return;

            for (let i = 0; i < 4; i++) {
                const el = document.getElementById('opt_' + qIndex + '_' + i);
                if (el) el.classList.remove('selected');
            }

            const current = document.getElementById('opt_' + qIndex + '_' + optIndex);
            if (current) current.classList.add('selected');

            document.getElementById('qcard_' + qIndex).classList.add('answered');
            answers[qIndex] = optIndex;
            updateProgress();
        }

        function selectTF(qIndex, value) {
            if (examSubmitted) return;

            document.getElementById('tf_' + qIndex + '_0').classList.remove('selected');
            document.getElementById('tf_' + qIndex + '_1').classList.remove('selected');
            document.getElementById('tf_' + qIndex + '_' + value).classList.add('selected');

            document.getElementById('qcard_' + qIndex).classList.add('answered');
            answers[qIndex] = value;
            updateProgress();
        }

        function essayInput(qIndex, value) {
            if (examSubmitted) return;
            if (value.trim().length > 0) {
                document.getElementById('qcard_' + qIndex).classList.add('answered');
                answers[qIndex] = value.trim();
            } else {
                document.getElementById('qcard_' + qIndex).classList.remove('answered');
                delete answers[qIndex];
            }
            updateProgress();
        }

        function updateProgress() {
            const answered = Object.keys(answers).length;
            const total = questions.length;
            const percentage = total > 0 ? Math.round((answered / total) * 100) : 0;

            const fill = document.getElementById('progressFill');
            const label = document.getElementById('progressText');
            if (fill) fill.style.width = percentage + '%';
            if (label) label.textContent = answered + ' / ' + total + ' (' + percentage + '%)';
        }

        // Timer
        function startTimer() {
            if (UNLIMITED_TIME) {
                timerInterval = setInterval(() => {
                    elapsedTime++;
                    const minutes = Math.floor(elapsedTime / 60);
                    const seconds = elapsedTime % 60;
                    const str = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
                    document.getElementById('timer').textContent = str;
                    const panelTimer = document.getElementById('panelTimer');
                    if (panelTimer) panelTimer.textContent = str;
                }, 1000);
                return;
            }

            timerInterval = setInterval(() => {
                timeRemaining--;
                const minutes = Math.max(0, Math.floor(timeRemaining / 60));
                const seconds = Math.max(0, timeRemaining % 60);
                const str = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');

                const timerEl = document.getElementById('timer');
                const badge = document.getElementById('timerBadge');
                const panelTimer = document.getElementById('panelTimer');

                if (timerEl) timerEl.textContent = str;
                if (panelTimer) panelTimer.textContent = str;

                if (badge) {
                    if (timeRemaining <= 60) {
                        badge.className = 'timer-badge danger';
                    } else if (timeRemaining <= 300) {
                        badge.className = 'timer-badge warning';
                    }
                }

                if (timeRemaining <= 0) {
                    clearInterval(timerInterval);
                    showWarning(TEXTS.time_up);
                    submitExam();
                }
            }, 1000);
        }

        // Anti-Cheat System
        function setupAntiCheat() {
            if (!ANTI_CHEAT_ENABLED) return;

            document.addEventListener('contextmenu', e => { e.preventDefault(); recordViolation(); });
            document.addEventListener('copy', e => { e.preventDefault(); recordViolation(); });
            document.addEventListener('cut', e => { e.preventDefault(); recordViolation(); });
            document.addEventListener('paste', e => { e.preventDefault(); recordViolation(); });

            document.addEventListener('keydown', e => {
                if (e.ctrlKey || e.metaKey) {
                    if (['c', 'v', 'x', 'a', 'p', 's', 'u'].includes(e.key.toLowerCase())) {
                        e.preventDefault();
                        recordViolation();
                    }
                }
                if (e.key === 'F12' || (e.ctrlKey && e.shiftKey && e.key === 'I')) {
                    e.preventDefault();
                    recordViolation();
                }
            });

            document.addEventListener('visibilitychange', () => {
                if (document.hidden && examStarted && !examSubmitted) {
                    recordViolation();
                }
            });

            window.addEventListener('blur', () => {
                if (examStarted && !examSubmitted) {
                    recordViolation();
                }
            });
        }

        function recordViolation() {
            if (examSubmitted) return;
            violations++;
            showWarning(TEXTS.cheating_warning + ' (' + TEXTS.cheating_count + ': ' + violations + '/3)');

            if (violations >= MAX_VIOLATIONS) {
                showWarning(TEXTS.cheating_limit);
                setTimeout(() => submitExam(), 1800);
            }
        }

        function showWarning(message) {
            const banner = document.getElementById('warningBanner');
            if (banner) {
                banner.textContent = message;
                banner.classList.add('show');
                setTimeout(() => banner.classList.remove('show'), 3000);
            }
        }

        // Reset
        function promptReset() {
            if (examSubmitted) return;
            document.getElementById('resetModal').classList.add('show');
        }

        function closeResetModal() {
            document.getElementById('resetModal').classList.remove('show');
        }

        function executeReset() {
            closeResetModal();
            answers = {};
            document.querySelectorAll('.option-card, .tf-card').forEach(el => el.classList.remove('selected'));
            document.querySelectorAll('.question-card').forEach(el => el.classList.remove('answered'));
            document.querySelectorAll('.essay-textarea').forEach(el => el.value = '');
            updateProgress();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Submission
        function confirmSubmit() {
            const unanswered = questions.length - Object.keys(answers).length;
            if (unanswered > 0) {
                document.getElementById('modalTitle').textContent = TEXTS.confirm_submit;
                document.getElementById('modalText').textContent =
                    TEXTS.unanswered_warning + ' ' + unanswered + ' ' + TEXTS.unanswered_count;
                document.getElementById('confirmModal').classList.add('show');
            } else {
                submitExam();
            }
        }

        function closeModal() {
            document.getElementById('confirmModal').classList.remove('show');
        }

        function forceSubmit() {
            closeModal();
            submitExam();
        }

        function submitExam() {
            if (examSubmitted) return;
            examSubmitted = true;
            clearInterval(timerInterval);

            let correct = 0;
            let gradableCount = 0;
            let essayCount = 0;

            questions.forEach((q, index) => {
                if (q.type === 'essay') {
                    essayCount++;
                    return;
                }
                gradableCount++;
                if (answers[index] !== undefined && answers[index] === q.correct) {
                    correct++;
                }
            });

            const total = gradableCount > 0 ? gradableCount : 1;
            const percentage = Math.round((correct / total) * 100);
            const passed = percentage >= PASSING_PERCENTAGE;
            const unansweredCount = questions.length - Object.keys(answers).length;
            const incorrectCount = total - correct - unansweredCount;

            examScore = { correct, total, percentage, passed, essayCount };

            // Hide action panel, show results box
            const actionPanel = document.getElementById('actionPanel');
            if (actionPanel) actionPanel.style.display = 'none';

            const resultsContainer = document.getElementById('resultsContainer');
            resultsContainer.classList.add('show');

            const scoreEl = document.getElementById('resultScore');
            scoreEl.textContent = correct + ' / ' + total;
            if (essayCount > 0) {
                scoreEl.textContent += ' (+' + essayCount + ' ' + (TEXTS.essay_questions || 'مقالي') + ')';
            }

            document.getElementById('resultPercentage').textContent = percentage + '%';

            const statusEl = document.getElementById('resultStatus');
            statusEl.textContent = passed ? TEXTS.passed : TEXTS.failed;
            statusEl.className = 'result-status-badge ' + (passed ? 'passed' : 'failed');

            // Render stats pills
            const pillsContainer = document.getElementById('resultStatsPills');
            pillsContainer.innerHTML =
                '<span class="stat-pill"><i class="fas fa-check-circle text-success me-1"></i> ' + (TEXTS.correct_answer || 'صحيحة') + ': ' + correct + '</span>' +
                '<span class="stat-pill"><i class="fas fa-times-circle text-danger me-1"></i> ' + (TEXTS.your_answer || 'خاطئة') + ': ' + Math.max(0, incorrectCount) + '</span>' +
                '<span class="stat-pill"><i class="fas fa-question-circle text-warning me-1"></i> ' + (TEXTS.unanswered_count || 'غير مجابة') + ': ' + unansweredCount + '</span>' +
                (studentName ? '<span class="stat-pill"><i class="fas fa-user me-1"></i> ' + studentName + ' (' + studentClass + ')</span>' : '');

            // Scroll to results
            resultsContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });

            // Lock inputs
            document.querySelectorAll('.option-card, .tf-card').forEach(el => {
                el.style.pointerEvents = 'none';
            });
            document.querySelectorAll('.essay-textarea').forEach(el => {
                el.readOnly = true;
                el.style.background = '#f8fafc';
            });
        }

        // Show Answers & Detailed Review
        function showAnswers() {
            questions.forEach((q, index) => {
                const card = document.getElementById('qcard_' + index);
                const feedback = document.getElementById('answer_' + index);
                const userAnswer = answers[index];

                if (q.type === 'essay') {
                    if (q.model_answer) {
                        feedback.innerHTML = '<strong><i class="fas fa-star me-1"></i> ' + (TEXTS.correct_answer || 'الإجابة النموذجية') + ':</strong><br>' + q.model_answer;
                        feedback.className = 'feedback-box show correct';
                    }
                    return;
                }

                const isCorrect = userAnswer !== undefined && userAnswer === q.correct;
                const isUnanswered = userAnswer === undefined;

                if (isCorrect) {
                    card.classList.add('correct');
                    feedback.className = 'feedback-box show correct';
                    feedback.innerHTML = '<i class="fas fa-check-circle me-1"></i> <strong>' + (TEXTS.passed || 'إجابة صحيحة!') + '</strong>' +
                        (q.explanation ? '<br>' + q.explanation : '');
                } else if (isUnanswered) {
                    card.classList.add('unanswered-flag');
                    feedback.className = 'feedback-box show warn';
                    const correctText = (q.type === 'multiple_choice') ? q.options[q.correct] : (q.correct === 1 ? TEXTS.true : TEXTS.false);
                    feedback.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i> <strong>' + (TEXTS.unanswered_warning || 'لم تتم الإجابة.') + '</strong> ' +
                        TEXTS.correct_answer + ': <strong>' + correctText + '</strong>' +
                        (q.explanation ? '<br>' + q.explanation : '');
                } else {
                    card.classList.add('incorrect');
                    feedback.className = 'feedback-box show incorrect';
                    const correctText = (q.type === 'multiple_choice') ? q.options[q.correct] : (q.correct === 1 ? TEXTS.true : TEXTS.false);
                    feedback.innerHTML = '<i class="fas fa-times-circle me-1"></i> <strong>' + (TEXTS.failed || 'إجابة غير صحيحة.') + '</strong> ' +
                        TEXTS.correct_answer + ': <strong>' + correctText + '</strong>' +
                        (q.explanation ? '<br>' + q.explanation : '');
                }

                // Highlight options
                if (q.type === 'multiple_choice') {
                    const correctOption = document.getElementById('opt_' + index + '_' + q.correct);
                    if (correctOption) correctOption.classList.add('correct-answer');

                    if (!isCorrect && userAnswer !== undefined) {
                        const wrongOption = document.getElementById('opt_' + index + '_' + userAnswer);
                        if (wrongOption) wrongOption.classList.add('wrong-selection');
                    }
                } else if (q.type === 'true_false') {
                    const correctTf = document.getElementById('tf_' + index + '_' + q.correct);
                    if (correctTf) correctTf.classList.add('correct-answer');

                    if (!isCorrect && userAnswer !== undefined) {
                        const wrongTf = document.getElementById('tf_' + index + '_' + userAnswer);
                        if (wrongTf) wrongTf.classList.add('wrong-selection');
                    }
                }
            });

            // Hide review button after clicked
            const showBtn = document.querySelector('.btn-show-answers');
            if (showBtn) showBtn.style.display = 'none';
        }

        // Export Grade to CSV / Excel
        function exportToExcel() {
            const now = new Date();
            const dateStr = now.toLocaleDateString('ar-EG') + ' ' + now.toLocaleTimeString('ar-EG');
            const statusText = examScore.passed ? (TEXTS.passed || 'ناجح') : (TEXTS.failed || 'راسب');

            const nameLabel = TEXTS.student_name_label || 'اسم الطالب';
            const classLabel = TEXTS.student_class_label || 'الفصل';
            const scoreObtainedLabel = TEXTS.score_obtained_label || 'الدرجة المحصلة';
            const totalScoreLabel = TEXTS.total_score_label || 'الدرجة النهائية';
            const gradeLabel = TEXTS.grade_label || 'الدرجة';
            const percLabel = TEXTS.percentage_label || 'النسبة المئوية';
            const statusLabel = TEXTS.status_label || 'الحالة';
            const modelLabel = TEXTS.model_label || 'النموذج';
            const dateLabel = TEXTS.date_label || 'التاريخ';

            const BOM = '\uFEFF';
            let csv = BOM;
            csv += nameLabel + ',' + classLabel + ',' + scoreObtainedLabel + ',' + totalScoreLabel + ',' + gradeLabel + ',' + percLabel + ',' + statusLabel + ',' + modelLabel + ',' + dateLabel + '\\n';
            csv += '"' + (studentName || '-') + '","' + (studentClass || '-') + '","' + examScore.correct + '","' + examScore.total + '","' + examScore.correct + '/' + examScore.total + '","' + examScore.percentage + '%","' + statusText + '","' + (currentModel || '-') + '","' + dateStr + '"\\n';

            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            const fileName = (studentName || 'student') + '_' + (studentClass || 'class') + '_grade.csv';
            a.download = fileName.replace(/\s+/g, '_');
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);

            gradeExported = true;

            const btn = document.getElementById('exportExcelBtn');
            if (btn) {
                btn.style.background = 'linear-gradient(135deg, #64748b, #475569)';
                btn.textContent = '✅ ' + (TEXTS.exported_success || 'تم تصدير الدرجة بنجاح');
                btn.disabled = true;
            }
            document.getElementById('exportWarningText').style.display = 'none';
            document.getElementById('exportSuccessText').style.display = 'block';
        }
    </script>
</body>
</html>
HTML;
    }
}
