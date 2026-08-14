<?php
/**
 * Notifications Helper
 *
 * Provides functions to fetch active notifications for students, teachers, and public portal.
 * Include this file then call the appropriate function.
 */

/**
 * Check if a notification should be shown right now based on scheduling
 */
function isNotificationScheduleActive($notif) {
    $now = new DateTime();
    $today = $now->format('Y-m-d');
    $currentTime = $now->format('H:i:s');
    $currentDay = (int)$now->format('w'); // 0=Sunday ... 6=Saturday

    // Check date range
    if (!empty($notif['start_date']) && $today < $notif['start_date']) return false;
    if (!empty($notif['end_date']) && $today > $notif['end_date']) return false;

    // Check time range
    if (!empty($notif['start_time']) && $currentTime < $notif['start_time']) return false;
    if (!empty($notif['end_time']) && $currentTime > $notif['end_time']) return false;

    // Check days of week
    if (!empty($notif['show_days'])) {
        $days = json_decode($notif['show_days'], true);
        if (is_array($days) && !empty($days) && !in_array($currentDay, $days)) return false;
    }

    return true;
}

/**
 * Batch-fetch all targets for a set of notification IDs (eliminates N+1 query)
 * Returns array indexed by notification_id => [ [target_type, target_id], ... ]
 */
function batchFetchNotificationTargets($db, array $notifIds) {
    if (empty($notifIds)) return [];
    $placeholders = implode(',', array_fill(0, count($notifIds), '?'));
    $stmt = $db->prepare("SELECT notification_id, target_type, target_id FROM notification_targets WHERE notification_id IN ($placeholders)");
    $stmt->execute(array_values($notifIds));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) {
        $map[$r['notification_id']][] = $r;
    }
    return $map;
}

/**
 * Get active notifications for a student
 *
 * @param PDO $db Database connection
 * @param int $userId Student user ID
 * @param int|null $classId Student's class ID
 * @param bool $excludeRead Whether to exclude already-read notifications
 * @return array Active notifications
 */
function getStudentNotifications($db, $userId, $classId = null, $excludeRead = true) {
    // Get student info for grade/stage matching
    $gradeId = null;
    $stageId = null;

    if ($classId) {
        $stmt = $db->prepare("SELECT c.grade_id, g.stage_id FROM classes c LEFT JOIN grades g ON c.grade_id = g.id WHERE c.id = ?");
        $stmt->execute([$classId]);
        $info = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($info) {
            $gradeId = $info['grade_id'];
            $stageId = $info['stage_id'];
        }
    }

    // Build the query - get all active student notifications
    $sql = "SELECT DISTINCT n.* FROM notifications n
            WHERE n.type = 'student' AND n.is_active = 1";

    if ($excludeRead) {
        $sql .= " AND n.id NOT IN (SELECT notification_id FROM notification_reads WHERE user_id = ?)";
    }

    $sql .= " ORDER BY FIELD(n.priority, 'urgent', 'important', 'normal'), n.created_at DESC";

    $params = $excludeRead ? [$userId] : [];
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $allNotifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Batch-fetch all targets in one query (eliminates N+1)
    $notifIds = array_column($allNotifs, 'id');
    $targetsMap = batchFetchNotificationTargets($db, $notifIds);

    $result = [];
    foreach ($allNotifs as $notif) {
        // Check scheduling
        if (!isNotificationScheduleActive($notif)) continue;

        // Check targeting
        $targets = $targetsMap[$notif['id']] ?? [];

        // If no targets = show to everyone
        if (empty($targets)) {
            $result[] = $notif;
            continue;
        }

        // Check if this student matches any target
        $matches = false;
        foreach ($targets as $t) {
            if ($t['target_type'] === 'student' && $t['target_id'] == $userId) { $matches = true; break; }
            if ($t['target_type'] === 'class' && $classId && $t['target_id'] == $classId) { $matches = true; break; }
            if ($t['target_type'] === 'grade' && $gradeId && $t['target_id'] == $gradeId) { $matches = true; break; }
            if ($t['target_type'] === 'stage' && $stageId && $t['target_id'] == $stageId) { $matches = true; break; }
        }

        if ($matches) $result[] = $notif;
    }

    return $result;
}

/**
 * Get active notifications for a teacher
 *
 * @param PDO $db Database connection
 * @param int $userId Teacher user ID
 * @param bool $excludeRead Whether to exclude already-read notifications
 * @return array Active notifications
 */
function getTeacherNotifications($db, $userId, $excludeRead = true) {
    // Get teacher's subjects and classes (stages)
    $teacherSubjects = [];
    $teacherStages = [];

    $stmt = $db->prepare("SELECT subject_id FROM teacher_subjects WHERE teacher_id = ?");
    $stmt->execute([$userId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $teacherSubjects[] = $row['subject_id'];
    }

    // Get stages from teacher_classes
    $stmt = $db->prepare("SELECT DISTINCT g.stage_id FROM teacher_classes tc
                          JOIN classes c ON tc.class_id = c.id
                          JOIN grades g ON c.grade_id = g.id
                          WHERE tc.teacher_id = ?");
    $stmt->execute([$userId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['stage_id']) $teacherStages[] = $row['stage_id'];
    }

    $sql = "SELECT DISTINCT n.* FROM notifications n
            WHERE n.type = 'teacher' AND n.is_active = 1";

    if ($excludeRead) {
        $sql .= " AND n.id NOT IN (SELECT notification_id FROM notification_reads WHERE user_id = ?)";
    }

    $sql .= " ORDER BY FIELD(n.priority, 'urgent', 'important', 'normal'), n.created_at DESC";

    $params = $excludeRead ? [$userId] : [];
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $allNotifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Batch-fetch all targets in one query
    $notifIds = array_column($allNotifs, 'id');
    $targetsMap = batchFetchNotificationTargets($db, $notifIds);

    $result = [];
    foreach ($allNotifs as $notif) {
        if (!isNotificationScheduleActive($notif)) continue;

        $targets = $targetsMap[$notif['id']] ?? [];

        // No targets = all teachers
        if (empty($targets)) {
            $result[] = $notif;
            continue;
        }

        $matches = false;
        foreach ($targets as $t) {
            if ($t['target_type'] === 'teacher' && $t['target_id'] == $userId) { $matches = true; break; }
            if ($t['target_type'] === 'subject' && in_array($t['target_id'], $teacherSubjects)) { $matches = true; break; }
            if ($t['target_type'] === 'stage' && in_array($t['target_id'], $teacherStages)) { $matches = true; break; }
        }

        if ($matches) $result[] = $notif;
    }

    return $result;
}

/**
 * Get active notifications for a specialist
 *
 * @param PDO $db Database connection
 * @param int $userId Specialist user ID
 * @param bool $excludeRead Whether to exclude already-read notifications
 * @return array Active notifications
 */
function getSpecialistNotifications($db, $userId, $excludeRead = true) {
    // Get specialist/supervisor's stages - check both tables for supervisor compatibility
    $specialistStages = [];

    // Check user role to determine which table to query
    $roleStmt = $db->prepare("SELECT role, is_supervisor FROM users WHERE id = ?");
    $roleStmt->execute([$userId]);
    $userRow = $roleStmt->fetch(PDO::FETCH_ASSOC);
    $userRole = $userRow['role'] ?? '';
    $isSupervisor = (int)($userRow['is_supervisor'] ?? 0);

    if ($userRole === 'supervisor' || ($userRole === 'teacher' && $isSupervisor)) {
        // Supervisor/teacher-supervisor uses user_class_access table
        $stmt = $db->prepare("SELECT DISTINCT g.stage_id FROM user_class_access uca
                              JOIN classes c ON uca.class_id = c.id
                              JOIN grades g ON c.grade_id = g.id
                              WHERE uca.user_id = ?");
    } else {
        // Specialist uses the active annual academic scope.
        $stmt = $db->prepare("SELECT DISTINCT g.stage_id FROM specialist_active_classes sc
                              JOIN classes c ON sc.class_id = c.id
                              JOIN grades g ON c.grade_id = g.id
                              WHERE sc.specialist_id = ?");
    }
    $stmt->execute([$userId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['stage_id']) $specialistStages[] = $row['stage_id'];
    }

    $sql = "SELECT DISTINCT n.* FROM notifications n
            WHERE n.type = 'specialist' AND n.is_active = 1";

    if ($excludeRead) {
        $sql .= " AND n.id NOT IN (SELECT notification_id FROM notification_reads WHERE user_id = ?)";
    }

    $sql .= " ORDER BY FIELD(n.priority, 'urgent', 'important', 'normal'), n.created_at DESC";

    $params = $excludeRead ? [$userId] : [];
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $allNotifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Batch-fetch all targets in one query
    $notifIds = array_column($allNotifs, 'id');
    $targetsMap = batchFetchNotificationTargets($db, $notifIds);

    $result = [];
    foreach ($allNotifs as $notif) {
        if (!isNotificationScheduleActive($notif)) continue;

        $targets = $targetsMap[$notif['id']] ?? [];

        // No targets = all specialists
        if (empty($targets)) {
            $result[] = $notif;
            continue;
        }

        $matches = false;
        foreach ($targets as $t) {
            if ($t['target_type'] === 'specialist' && $t['target_id'] == $userId) { $matches = true; break; }
            if ($t['target_type'] === 'stage' && in_array($t['target_id'], $specialistStages)) { $matches = true; break; }
        }

        if ($matches) $result[] = $notif;
    }

    return $result;
}

/**
 * Get active public notifications (for the main portal / stage selection page)
 *
 * @param PDO $db Database connection
 * @return array Active public notifications
 */
function getPublicNotifications($db) {
    $sql = "SELECT * FROM notifications WHERE type = 'public' AND is_active = 1
            ORDER BY FIELD(priority, 'urgent', 'important', 'normal'), created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $allNotifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($allNotifs as $notif) {
        if (isNotificationScheduleActive($notif)) {
            $result[] = $notif;
        }
    }

    return $result;
}

/**
 * Mark a notification as read by a user
 */
function markNotificationRead($db, $notificationId, $userId) {
    try {
        $stmt = $db->prepare("INSERT IGNORE INTO notification_reads (notification_id, user_id) VALUES (?, ?)");
        $stmt->execute([$notificationId, $userId]);
    } catch (Exception $e) {
        // Ignore duplicate errors
    }
}

/**
 * Render notification alerts HTML for student/teacher pages
 *
 * @param array $notifications Array of notification data
 * @param string $dismissUrl URL to POST dismiss action (AJAX endpoint)
 * @return string HTML output
 */
function renderNotificationAlerts($notifications, $dismissUrl = '') {
    $dismissCsrfToken = rawurlencode((string)($_SESSION['csrf_token'] ?? ''));
    if (empty($notifications)) return '';

    $priorityClasses = [
        'urgent' => 'danger',
        'important' => 'warning',
        'normal' => 'info'
    ];

    $priorityIcons = [
        'urgent' => 'fas fa-exclamation-circle',
        'important' => 'fas fa-exclamation-triangle',
        'normal' => 'fas fa-bell'
    ];

    $html = '<div class="notifications-container mb-4">';

    $hasUrgent = false;
    foreach ($notifications as $notif) {
        $alertClass = $priorityClasses[$notif['priority']] ?? 'info';
        $icon = $priorityIcons[$notif['priority']] ?? 'fas fa-bell';
        $notifId = $notif['id'];
        $isUrgent = ($notif['priority'] === 'urgent');
        if ($isUrgent) $hasUrgent = true;

        $urgentStyle = $isUrgent ? ' urgent-notification' : '';

        $html .= '<div class="alert alert-' . $alertClass . ' alert-dismissible fade show notification-alert' . $urgentStyle . '" role="alert" data-notification-id="' . $notifId . '" style="border-radius: 12px; border-right: 5px solid; margin-bottom: 10px;">';
        if ($isUrgent) {
            $html .= '<div class="text-center mb-2"><span class="badge bg-danger urgent-badge" style="font-size:0.9em;padding:5px 18px;">عاجل</span></div>';
            $html .= '<h6 class="alert-heading mb-2 fw-bold text-center">' . htmlspecialchars($notif['title']) . '</h6>';
            $html .= '<div class="d-flex align-items-center">';
            $html .= '<div class="me-3"><i class="' . $icon . ' fa-lg urgent-icon"></i></div>';
            $html .= '<div class="flex-grow-1"><p class="mb-0">' . nl2br(htmlspecialchars($notif['message'])) . '</p></div>';
            $html .= '<div class="ms-3 d-flex align-items-center"><i class="fas fa-volume-up urgent-sound-icon"></i></div>';
            $html .= '</div>';
        } else {
            $html .= '<div class="d-flex align-items-center">';
            $html .= '<div class="me-3"><i class="' . $icon . ' fa-lg"></i></div>';
            $html .= '<div class="flex-grow-1">';
            $html .= '<h6 class="alert-heading mb-1 fw-bold">' . htmlspecialchars($notif['title']) . '</h6>';
            $html .= '<p class="mb-0">' . nl2br(htmlspecialchars($notif['message'])) . '</p>';
            $html .= '</div>';
            $html .= '</div>';
        }
        $html .= '<button type="button" class="btn-close dismiss-notification" data-notification-id="' . $notifId . '" data-bs-dismiss="alert" aria-label="إغلاق"></button>';
        $html .= '</div>';
    }

    $html .= '</div>';

    // Add dismiss JS
    if (!empty($dismissUrl)) {
        $html .= '<script>
        document.querySelectorAll(".dismiss-notification").forEach(function(btn) {
            btn.addEventListener("click", function() {
                var nid = this.getAttribute("data-notification-id");
                fetch("' . $dismissUrl . '", {
                    method: "POST",
                    headers: {"Content-Type": "application/x-www-form-urlencoded"},
                    body: "action=dismiss_notification&notification_id=" + nid + "&csrf_token=' . $dismissCsrfToken . '"
                });
            });
        });
        </script>';
    }

    // Add urgent notification styles and sound
    if ($hasUrgent) {
        $html .= '<style>
        @keyframes urgentPulse {
            0% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.5); }
            50% { box-shadow: 0 0 20px 6px rgba(220, 53, 69, 0.25); }
            100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
        }
        @keyframes urgentIconPulse {
            0%, 100% { transform: scale(1); }
            25% { transform: scale(1.3); }
            50% { transform: scale(1); }
            75% { transform: scale(1.2); }
        }
        @keyframes urgentBadgePulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .urgent-notification {
            animation: urgentPulse 2.5s ease-in-out 3;
            border-right-width: 6px !important;
            border-color: #dc3545 !important;
            position: relative;
        }
        .urgent-icon {
            animation: urgentIconPulse 1.5s ease-in-out 3;
            color: #dc3545;
        }
        .urgent-badge {
            animation: urgentBadgePulse 1.5s ease-in-out infinite;
            font-size: 0.7em;
            vertical-align: middle;
            margin-right: 6px;
        }
        .urgent-sound-icon {
            color: #dc3545;
            font-size: 1.3em;
            display: inline-block;
            animation: soundWave 1.5s ease-in-out 4;
        }
        @keyframes soundWave {
            0%, 100% { transform: scale(1); opacity: 0.7; }
            15% { transform: scale(1.4) rotate(-8deg); opacity: 1; }
            30% { transform: scale(1) rotate(0deg); opacity: 0.7; }
            45% { transform: scale(1.3) rotate(8deg); opacity: 1; }
            60% { transform: scale(1) rotate(0deg); opacity: 0.7; }
        }
        @keyframes urgentCardScale {
            0%, 100% { transform: scale(1); }
            25% { transform: scale(1.045); }
            50% { transform: scale(1); }
            75% { transform: scale(1.03); }
        }
        .urgent-card-animating {
            animation: urgentCardScale 1s ease-in-out 3 !important;
            transform-origin: center center;
        }
        </style>';

        // Play a subtle notification sound using Web Audio API
        $html .= '<script>
        (function() {
            try {
                var audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                function playTone(freq, startTime, duration) {
                    var osc = audioCtx.createOscillator();
                    var gain = audioCtx.createGain();
                    osc.connect(gain);
                    gain.connect(audioCtx.destination);
                    osc.type = "sine";
                    osc.frequency.value = freq;
                    gain.gain.setValueAtTime(0.15, startTime);
                    gain.gain.exponentialRampToValueAtTime(0.001, startTime + duration);
                    osc.start(startTime);
                    osc.stop(startTime + duration);
                }
                function animateUrgentCards() {
                    document.querySelectorAll(".urgent-notification").forEach(function(el) {
                        el.classList.remove("urgent-card-animating");
                        void el.offsetWidth;
                        el.classList.add("urgent-card-animating");
                        setTimeout(function() { el.classList.remove("urgent-card-animating"); }, 3200);
                    });
                }
                function playAlertSound() {
                    var t = audioCtx.currentTime + 0.1;
                    for (var i = 0; i < 3; i++) {
                        var offset = i * 1.0;
                        playTone(880, t + offset, 0.15);
                        playTone(1100, t + offset + 0.18, 0.15);
                        playTone(880, t + offset + 0.38, 0.2);
                    }
                    animateUrgentCards();
                }
                function tryPlay() {
                    if (audioCtx.state === "suspended") {
                        audioCtx.resume().then(playAlertSound);
                    } else {
                        playAlertSound();
                    }
                }
                tryPlay();
                // Replay on bfcache restore (back button)
                window.addEventListener("pageshow", function(e) { if (e.persisted) tryPlay(); });
                // Resume on first user interaction if still suspended
                if (audioCtx.state === "suspended") {
                    var resumeOnce = function() {
                        audioCtx.resume().then(playAlertSound);
                        document.removeEventListener("click", resumeOnce);
                        document.removeEventListener("touchstart", resumeOnce);
                    };
                    document.addEventListener("click", resumeOnce);
                    document.addEventListener("touchstart", resumeOnce);
                }
            } catch(e) {}
        })();
        </script>';
    }

    return $html;
}

/**
 * Render notification alerts HTML for public portal (no dismiss, different style)
 */
function renderPublicNotificationAlerts($notifications) {
    if (empty($notifications)) return '';

    $html = '<div class="public-notifications-container" style="max-width: 900px; margin: 0 auto 2rem auto; padding: 0 20px;">';

    $hasUrgent = false;
    foreach ($notifications as $notif) {
        $bgColor = '#ffffff';
        $borderColor = '#3b82f6';
        $iconColor = '#3b82f6';
        $icon = 'fas fa-bell';
        $isUrgent = ($notif['priority'] === 'urgent');
        if ($isUrgent) $hasUrgent = true;

        if ($notif['priority'] === 'urgent') {
            $borderColor = '#ef4444';
            $iconColor = '#ef4444';
            $icon = 'fas fa-exclamation-circle';
        } elseif ($notif['priority'] === 'important') {
            $borderColor = '#f59e0b';
            $iconColor = '#f59e0b';
            $icon = 'fas fa-exclamation-triangle';
        }

        $urgentClass = $isUrgent ? ' public-urgent-notification' : '';
        $urgentSoundIcon = $isUrgent ? '<div style="margin-right:15px;display:flex;align-items:center;"><i class="fas fa-volume-up" style="color:#ef4444;font-size:1.3em;display:inline-block;animation:soundWave 1.5s ease-in-out 4;"></i></div>' : '';

        $html .= '<div class="public-notification-card' . $urgentClass . '" style="background: ' . $bgColor . '; border-radius: 15px; padding: 20px 25px; margin-bottom: 15px; border-right: 5px solid ' . $borderColor . '; box-shadow: 0 4px 15px rgba(0,0,0,0.1); animation: fadeInUp 0.5s ease-out;">';
        if ($isUrgent) {
            $html .= '<div style="text-align:center;margin-bottom:10px;"><span style="background:#ef4444;color:#fff;padding:5px 18px;border-radius:20px;font-size:0.9em;animation:urgentBadgePulse 1.5s ease-in-out infinite;">عاجل</span></div>';
            $html .= '<h5 style="margin:0 0 12px 0; font-weight:700; color:#1e293b; text-align:center;">' . htmlspecialchars($notif['title']) . '</h5>';
            $html .= '<div style="display:flex; align-items:center; gap:15px;">';
            $html .= '<div style="color:' . $iconColor . '; font-size: 1.5rem;"><i class="' . $icon . ' public-urgent-icon"></i></div>';
            $html .= '<div style="flex:1;"><p style="margin:0; color:#475569; line-height:1.6;">' . nl2br(htmlspecialchars($notif['message'])) . '</p></div>';
            $html .= $urgentSoundIcon . '</div></div>';
        } else {
            $html .= '<div style="display:flex; align-items:center; gap:15px;">';
            $html .= '<div style="color:' . $iconColor . '; font-size: 1.5rem;"><i class="' . $icon . '"></i></div>';
            $html .= '<div style="flex:1;">';
            $html .= '<h5 style="margin:0 0 8px 0; font-weight:700; color:#1e293b;">' . htmlspecialchars($notif['title']) . '</h5>';
            $html .= '<p style="margin:0; color:#475569; line-height:1.6;">' . nl2br(htmlspecialchars($notif['message'])) . '</p>';
            $html .= '</div></div></div>';
        }
    }

    $html .= '</div>';

    $urgentStyles = $hasUrgent ? '
        @keyframes publicUrgentPulse {
            0% { box-shadow: 0 4px 15px rgba(0,0,0,0.1), 0 0 0 0 rgba(239,68,68,0.5); }
            50% { box-shadow: 0 4px 15px rgba(0,0,0,0.1), 0 0 20px 6px rgba(239,68,68,0.25); }
            100% { box-shadow: 0 4px 15px rgba(0,0,0,0.1), 0 0 0 0 rgba(239,68,68,0); }
        }
        @keyframes publicUrgentIconPulse {
            0%, 100% { transform: scale(1); }
            25% { transform: scale(1.3); }
            50% { transform: scale(1); }
            75% { transform: scale(1.2); }
        }
        @keyframes urgentBadgePulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        @keyframes publicUrgentCardScale {
            0%, 100% { transform: scale(1); }
            25% { transform: scale(1.045); }
            50% { transform: scale(1); }
            75% { transform: scale(1.03); }
        }
        .public-urgent-notification {
            animation: publicUrgentPulse 2.5s ease-in-out 3, fadeInUp 0.5s ease-out !important;
            border-right-width: 6px !important;
        }
        .public-urgent-icon {
            animation: publicUrgentIconPulse 1.5s ease-in-out 3;
        }
        .public-urgent-card-animating {
            animation: publicUrgentCardScale 1s ease-in-out 3 !important;
            transform-origin: center center;
        }
    ' : '';

    $html .= '<style>@keyframes fadeInUp { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } } @keyframes soundWave { 0%, 100% { transform: scale(1); opacity: 0.7; } 15% { transform: scale(1.4) rotate(-8deg); opacity: 1; } 30% { transform: scale(1) rotate(0deg); opacity: 0.7; } 45% { transform: scale(1.3) rotate(8deg); opacity: 1; } 60% { transform: scale(1) rotate(0deg); opacity: 0.7; } } body.dark-mode .public-notification-card { background: #1e293b !important; } body.dark-mode .public-notification-card h5 { color: #f1f5f9 !important; } body.dark-mode .public-notification-card p { color: #cbd5e1 !important; }' . $urgentStyles . '</style>';

    if ($hasUrgent) {
        $html .= '<script>
        (function() {
            try {
                var audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                function playTone(freq, startTime, duration) {
                    var osc = audioCtx.createOscillator();
                    var gain = audioCtx.createGain();
                    osc.connect(gain);
                    gain.connect(audioCtx.destination);
                    osc.type = "sine";
                    osc.frequency.value = freq;
                    gain.gain.setValueAtTime(0.15, startTime);
                    gain.gain.exponentialRampToValueAtTime(0.001, startTime + duration);
                    osc.start(startTime);
                    osc.stop(startTime + duration);
                }
                function animatePublicUrgentCards() {
                    document.querySelectorAll(".public-urgent-notification").forEach(function(el) {
                        el.classList.remove("public-urgent-card-animating");
                        void el.offsetWidth;
                        el.classList.add("public-urgent-card-animating");
                        setTimeout(function() { el.classList.remove("public-urgent-card-animating"); }, 3200);
                    });
                }
                function playAlertSound() {
                    var t = audioCtx.currentTime + 0.1;
                    for (var i = 0; i < 3; i++) {
                        var offset = i * 1.0;
                        playTone(880, t + offset, 0.15);
                        playTone(1100, t + offset + 0.18, 0.15);
                        playTone(880, t + offset + 0.38, 0.2);
                    }
                    animatePublicUrgentCards();
                }
                function tryPlay() {
                    if (audioCtx.state === "suspended") {
                        audioCtx.resume().then(playAlertSound);
                    } else {
                        playAlertSound();
                    }
                }
                tryPlay();
                window.addEventListener("pageshow", function(e) { if (e.persisted) tryPlay(); });
                if (audioCtx.state === "suspended") {
                    var resumeOnce = function() {
                        audioCtx.resume().then(playAlertSound);
                        document.removeEventListener("click", resumeOnce);
                        document.removeEventListener("touchstart", resumeOnce);
                    };
                    document.addEventListener("click", resumeOnce);
                    document.addEventListener("touchstart", resumeOnce);
                }
            } catch(e) {}
        })();
        </script>';
    }

    return $html;
}

// ====================================================================
// Portal Notifications - Displayed on student/teacher portal pages
// ====================================================================

/**
 * Get active occasion notifications for the current date
 */
function getActiveOccasions($db, $role = 'all') {
    try {
        $today = date('m-d');

        $sql = "SELECT * FROM occasion_notifications WHERE is_active = 1
                AND (target_type = 'all' OR target_type = ? OR target_type = 'both')
                ORDER BY sort_order";
        $stmt = $db->prepare($sql);
        $stmt->execute([$role]);
        $occasions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($occasions as $occ) {
            if (!empty($occ['start_date']) && !empty($occ['end_date'])) {
                if ($today < $occ['start_date'] || $today > $occ['end_date']) {
                    continue;
                }
            }
            $result[] = $occ;
        }

        return $result;
    } catch (Exception $e) {
        error_log("getActiveOccasions error: " . $e->getMessage());
        return [];
    }
}

/**
 * Render occasion banner HTML for portal pages
 */
function renderOccasionBanners($occasions) {
    if (empty($occasions)) return '';

    $html = '';

    foreach ($occasions as $occ) {
        $gs = htmlspecialchars($occ['gradient_start']);
        $ge = htmlspecialchars($occ['gradient_end']);
        $tc = htmlspecialchars($occ['text_color']);
        $icon = htmlspecialchars($occ['icon']);
        $title = htmlspecialchars($occ['title']);
        $message = htmlspecialchars($occ['message']);
        $theme = htmlspecialchars($occ['theme']);
        $animType = htmlspecialchars($occ['animation_type']);
        $confetti = $occ['show_confetti'];
        $key = htmlspecialchars($occ['occasion_key']);
        $emojiRaw = trim($occ['emoji'] ?? '');
        $emoji = htmlspecialchars($emojiRaw);

        $displayTitle = $title;
        if (!empty($emojiRaw) && mb_strpos($displayTitle, $emojiRaw) === false) {
            $displayTitle = $emoji . ' ' . $displayTitle;
        }

        $html .= "
        <div class=\"occasion-banner occasion-{$theme}\" data-occasion=\"{$key}\" data-animation=\"{$animType}\" data-confetti=\"{$confetti}\"
             style=\"background: linear-gradient(135deg, {$gs} 0%, {$ge} 100%); color: {$tc};\">
            <div class=\"occasion-decoration occasion-deco-{$theme}\"></div>
            <button class=\"occasion-close\" onclick=\"this.closest('.occasion-banner').style.display='none'\" title=\"إغلاق\">
                <i class=\"fas fa-times\"></i>
            </button>
            <div class=\"occasion-content\">
                <div class=\"occasion-icon\">
                    <i class=\"{$icon}\"></i>
                </div>
                <div class=\"occasion-text\">
                    <h4 class=\"occasion-title\">{$displayTitle}</h4>
                    <p class=\"occasion-message\">{$message}</p>
                </div>
            </div>
        </div>";
    }

    return $html;
}

/**
 * Render portal notifications (admin-created notifications for portal pages)
 */
function renderPortalNotifications($notifications) {
    if (empty($notifications)) return '';

    $priorityColors = [
        'urgent' => ['bg' => '#fef2f2', 'border' => '#ef4444', 'icon_bg' => '#fee2e2', 'icon' => '#dc2626'],
        'important' => ['bg' => '#fffbeb', 'border' => '#f59e0b', 'icon_bg' => '#fef3c7', 'icon' => '#d97706'],
        'normal' => ['bg' => '#eff6ff', 'border' => '#3b82f6', 'icon_bg' => '#dbeafe', 'icon' => '#2563eb']
    ];

    $priorityIcons = [
        'urgent' => 'fas fa-exclamation-circle',
        'important' => 'fas fa-exclamation-triangle',
        'normal' => 'fas fa-bell'
    ];

    $html = '<div class="portal-notifications-container">';

    foreach ($notifications as $notif) {
        $p = $notif['priority'] ?? 'normal';
        $colors = $priorityColors[$p] ?? $priorityColors['normal'];
        $icon = $priorityIcons[$p] ?? 'fas fa-bell';
        $isUrgent = ($p === 'urgent');
        $urgentClass = $isUrgent ? ' portal-notif-urgent' : '';

        $html .= "
        <div class=\"portal-notif-card{$urgentClass}\"
             style=\"background: {$colors['bg']}; border-right: 4px solid {$colors['border']};\"
             data-notif-id=\"{$notif['id']}\">
            <button class=\"portal-notif-close\" onclick=\"dismissPortalNotif(this, {$notif['id']})\" title=\"إغلاق\">
                <i class=\"fas fa-times\"></i>
            </button>
            <div class=\"portal-notif-inner\">
                <div class=\"portal-notif-icon\" style=\"background: {$colors['icon_bg']}; color: {$colors['icon']};\">
                    <i class=\"{$icon}\"></i>
                </div>
                <div class=\"portal-notif-body\">
                    <h6 class=\"portal-notif-title\">" . htmlspecialchars($notif['title']) . "</h6>
                    <p class=\"portal-notif-msg\">" . nl2br(htmlspecialchars($notif['message'])) . "</p>
                </div>
            </div>
        </div>";
    }

    $html .= '</div>';

    return $html;
}

/**
 * Get CSS + JS assets for portal notifications & occasion banners
 */
function getPortalNotificationsAssets($dismissUrl = '../api/dismiss_notification.php') {
    $dismissCsrfToken = rawurlencode((string)($_SESSION['csrf_token'] ?? ''));
    return '
    <style>
    .portal-notifications-container {
        max-width: 800px;
        margin: 0 auto 1.5rem;
        padding: 0 1rem;
    }
    .portal-notif-card {
        border-radius: 14px;
        padding: 1rem 1.25rem;
        margin-bottom: 0.75rem;
        position: relative;
        box-shadow: 0 2px 10px rgba(0,0,0,0.06);
        animation: portalNotifSlide 0.5s ease-out;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .portal-notif-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }
    .portal-notif-urgent {
        animation: portalNotifSlide 0.5s ease-out, urgentPortalPulse 2.5s ease-in-out 3;
    }
    .portal-notif-close {
        position: absolute;
        top: 8px;
        left: 8px;
        background: rgba(0,0,0,0.08);
        border: none;
        border-radius: 50%;
        width: 28px;
        height: 28px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 0.75rem;
        color: #64748b;
        transition: all 0.2s;
    }
    .portal-notif-close:hover {
        background: rgba(0,0,0,0.15);
        color: #1e293b;
    }
    .portal-notif-inner {
        display: flex;
        align-items: flex-start;
        gap: 0.85rem;
    }
    .portal-notif-icon {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
    }
    .portal-notif-title {
        font-weight: 700;
        font-size: 0.95rem;
        margin: 0 0 4px;
        color: #1e293b;
    }
    .portal-notif-msg {
        font-size: 0.85rem;
        margin: 0;
        color: #475569;
        line-height: 1.6;
    }
    @keyframes portalNotifSlide {
        from { opacity: 0; transform: translateY(-15px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @keyframes urgentPortalPulse {
        0%, 100% { box-shadow: 0 2px 10px rgba(0,0,0,0.06); }
        50% { box-shadow: 0 0 20px rgba(239, 68, 68, 0.25); }
    }

    /* ============ Occasion Banners ============ */
    .occasion-banner {
        max-width: 800px;
        margin: 0 auto 1.25rem;
        border-radius: 18px;
        padding: 1.5rem 2rem;
        position: relative;
        overflow: hidden;
        box-shadow: 0 8px 30px rgba(0,0,0,0.2);
        animation: occasionFadeIn 0.8s ease-out;
    }
    .occasion-close {
        position: absolute;
        top: 12px;
        left: 12px;
        background: rgba(255,255,255,0.15);
        border: none;
        border-radius: 50%;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: inherit;
        font-size: 0.85rem;
        transition: all 0.2s;
        z-index: 3;
    }
    .occasion-close:hover {
        background: rgba(255,255,255,0.3);
        transform: scale(1.1);
    }
    .occasion-decoration {
        position: absolute;
        top: 0; left: 0; right: 0; bottom: 0;
        pointer-events: none;
        z-index: 1;
    }
    .occasion-content {
        position: relative;
        z-index: 2;
        display: flex;
        align-items: center;
        gap: 1.25rem;
    }
    .occasion-icon {
        width: 65px;
        height: 65px;
        border-radius: 50%;
        background: rgba(255,255,255,0.15);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.8rem;
        flex-shrink: 0;
        animation: occasionIconFloat 3s ease-in-out infinite;
    }
    .occasion-title {
        font-size: 1.3rem;
        font-weight: 800;
        margin: 0 0 6px;
        text-shadow: 0 2px 4px rgba(0,0,0,0.15);
    }
    .occasion-message {
        font-size: 0.92rem;
        margin: 0;
        opacity: 0.9;
        line-height: 1.7;
    }
    @keyframes occasionFadeIn {
        from { opacity: 0; transform: translateY(-20px) scale(0.97); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }
    @keyframes occasionIconFloat {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-6px); }
    }

    /* Ramadan */
    .occasion-deco-ramadan::before { content: "\\2726 \\262A \\2726 \\262A \\2726"; position: absolute; top: 10px; right: 15px; font-size: 1.2rem; opacity: 0.2; letter-spacing: 12px; }
    .occasion-deco-ramadan::after { content: ""; position: absolute; bottom: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, transparent, rgba(255,215,0,0.5), transparent); }
    .occasion-ramadan .occasion-icon { background: rgba(255,215,0,0.2); animation: ramadanGlow 2s ease-in-out infinite; }
    @keyframes ramadanGlow { 0%, 100% { box-shadow: 0 0 10px rgba(255,215,0,0.2); } 50% { box-shadow: 0 0 25px rgba(255,215,0,0.4); } }

    /* Eid */
    .occasion-deco-eid::before { content: "\\2726 \\2727 \\2726 \\2727 \\2726 \\2727 \\2726"; position: absolute; top: 8px; right: 10px; left: 10px; text-align: center; font-size: 1rem; opacity: 0.15; letter-spacing: 15px; }
    .occasion-eid .occasion-icon { animation: eidBounce 1.5s ease-in-out infinite; }
    @keyframes eidBounce { 0%, 100% { transform: translateY(0) scale(1); } 50% { transform: translateY(-8px) scale(1.05); } }

    /* National */
    .occasion-deco-national::before { content: ""; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #ce1126 33%, #fff 33%, #fff 66%, #000 66%); }
    .occasion-national .occasion-icon { border: 2px solid rgba(255,255,255,0.3); }

    /* Islamic */
    .occasion-deco-islamic::after { content: ""; position: absolute; bottom: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent); }
    .occasion-islamic .occasion-icon { animation: islamicGlow 3s ease-in-out infinite; }
    @keyframes islamicGlow { 0%, 100% { box-shadow: 0 0 8px rgba(255,255,255,0.1); } 50% { box-shadow: 0 0 20px rgba(255,255,255,0.25); } }

    /* Celebration */
    .occasion-celebration .occasion-icon { animation: celebrationPop 2s ease-in-out infinite; }
    @keyframes celebrationPop { 0%, 100% { transform: scale(1) rotate(0deg); } 25% { transform: scale(1.1) rotate(-5deg); } 75% { transform: scale(1.05) rotate(5deg); } }

    /* Spring */
    .occasion-deco-spring::before { content: "\\1F338 \\1F33A \\1F337 \\1F338 \\1F33A"; position: absolute; top: 8px; right: 10px; font-size: 1rem; opacity: 0.3; letter-spacing: 8px; }

    /* Dark mode */
    body.dark-mode .portal-notif-card { background: rgba(30,41,59,0.9) !important; border-color: rgba(255,255,255,0.1); }
    body.dark-mode .portal-notif-title { color: #e2e8f0; }
    body.dark-mode .portal-notif-msg { color: #94a3b8; }
    body.dark-mode .portal-notif-close { color: #94a3b8; background: rgba(255,255,255,0.1); }

    /* Responsive */
    @media (max-width: 768px) {
        .occasion-banner { padding: 1.25rem 1rem; margin: 0 0.5rem 1rem; border-radius: 14px; }
        .occasion-icon { width: 50px; height: 50px; font-size: 1.3rem; }
        .occasion-title { font-size: 1.05rem; }
        .occasion-message { font-size: 0.82rem; }
        .occasion-content { gap: 0.85rem; }
        .portal-notifications-container { padding: 0 0.5rem; }
        .portal-notif-card { padding: 0.85rem 1rem; }
    }
    </style>

    <script>
    function dismissPortalNotif(btn, notifId) {
        var card = btn.closest(".portal-notif-card");
        card.style.opacity = "0";
        card.style.transform = "translateX(-30px)";
        card.style.transition = "all 0.3s ease";
        setTimeout(function() { card.remove(); }, 300);
        fetch("' . $dismissUrl . '", {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: "action=dismiss_notification&notification_id=" + notifId + "&csrf_token=' . $dismissCsrfToken . '"
        }).catch(function(){});
    }
    document.addEventListener("DOMContentLoaded", function() {
        document.querySelectorAll(".occasion-banner[data-confetti=\\"1\\"]").forEach(function(banner) {
            var colors = ["#f00","#0f0","#00f","#ff0","#f0f","#0ff","#ffa500","#ff69b4"];
            var shapes = ["\\u25CF","\\u25A0","\\u2605","\\u25B2","\\u2666"];
            for (var i = 0; i < 20; i++) {
                var span = document.createElement("span");
                span.textContent = shapes[Math.floor(Math.random()*shapes.length)];
                span.style.cssText = "position:absolute;font-size:"+(6+Math.random()*10)+"px;color:"+colors[Math.floor(Math.random()*colors.length)]+";left:"+(Math.random()*100)+"%;top:"+(Math.random()*100)+"%;opacity:0;pointer-events:none;z-index:1;animation:confettiFall "+(2+Math.random()*3)+"s ease-out "+(Math.random()*2)+"s infinite;";
                banner.appendChild(span);
            }
        });
    });
    </script>
    <style>@keyframes confettiFall { 0% { opacity:0; transform:translateY(-20px) rotate(0deg); } 20% { opacity:0.8; } 100% { opacity:0; transform:translateY(60px) rotate(360deg); } }</style>';
}
