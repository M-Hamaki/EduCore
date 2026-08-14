<?php

// Loaded only after the shared AJAX authentication, CSRF and permission gates.
switch ($action) {
        case 'admin_reports_datatable':
            if ($role !== 'admin' && $role !== 'specialist' && !$hasDelegatedPageGrant) {
                sendJsonResponse(['success'=>false,'message'=>'غير مصرح'], 403);
            }
            $draw = (int)($requestGet['draw'] ?? 1);
            $start = (int)($requestGet['start'] ?? 0);
            $requestedLength = (int)($requestGet['length'] ?? 50);
            $length = $requestedLength === -1 ? PHP_INT_MAX : max(10, min(500, $requestedLength));
            $searchValue = trim($requestGet['search']['value'] ?? '');
            $orderCol = (int)($requestGet['order'][0]['column'] ?? 8); // default date col index considering checkbox col
            $orderDir = strtoupper($requestGet['order'][0]['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

            // Columns mapping: [checkbox], id, student, teacher, grade, class, evaluation, type, points, date, actions
            $columns = [
                0 => 'checkbox',
                1 => 'e.id',
                2 => 's.name',
                3 => 't.name',
                4 => 'g.grade_name',
                5 => 'c.name',
                6 => 'et.name',
                7 => 'display_type',
                8 => 'display_points',
                9 => 'e.date_created',
                10 => 'actions'
            ];
            $orderBy = $columns[$orderCol] ?? 'e.date_created';

            // Filters
            $filter_grade = $requestGet['grade_id'] ?? null;
            $filter_class = $requestGet['class_id'] ?? null;
            $filter_student = $requestGet['student_id'] ?? null;
            $filter_teacher = $requestGet['teacher_id'] ?? null;
            $filter_evaluation_type = $requestGet['evaluation_type_id'] ?? null;
            $filter_date_from = $requestGet['date_from'] ?? null;
            $filter_date_to = $requestGet['date_to'] ?? null;
            $filter_time_from = $requestGet['time_from'] ?? null;
            $filter_time_to = $requestGet['time_to'] ?? null;

            $from = " FROM evaluations e
                      JOIN users s ON e.student_id = s.id
                      JOIN users t ON e.teacher_id = t.id
                      JOIN classes c ON e.class_id = c.id
                      LEFT JOIN grades g ON c.grade_id = g.id
                      JOIN evaluation_types et ON e.evaluation_type_id = et.id
                       WHERE 1=1";
            $params = [];
            if ($role === 'specialist') {
                $reportAllowedClassIds = $staffPortalContext->allowedClassIds() ?? [];
                if ($reportAllowedClassIds === []) {
                    sendJsonResponse(['draw'=>$draw,'recordsTotal'=>0,'recordsFiltered'=>0,'data'=>[]]);
                }
                $from .= ' AND e.class_id IN (' . implode(',', array_map('intval', $reportAllowedClassIds)) . ')';
            }
            if ($currentAcademicYearId > 0) {
                $from .= ' AND (e.academic_year_id = :portal_year_id OR e.academic_year_id IS NULL)';
                $params[':portal_year_id'] = $currentAcademicYearId;
            }
            if (!empty($filter_grade)) { $from .= " AND c.grade_id = :grade_id"; $params[':grade_id'] = (int)$filter_grade; }
            if (!empty($filter_class)) { $from .= " AND e.class_id = :class_id"; $params[':class_id'] = (int)$filter_class; }
            if (!empty($filter_student)) { $from .= " AND e.student_id = :student_id"; $params[':student_id'] = (int)$filter_student; }
            if (!empty($filter_teacher)) { $from .= " AND e.teacher_id = :teacher_id"; $params[':teacher_id'] = (int)$filter_teacher; }
            if (!empty($filter_evaluation_type)) { $from .= " AND e.evaluation_type_id = :evaluation_type_id"; $params[':evaluation_type_id'] = (int)$filter_evaluation_type; }
            if (!empty($filter_date_from)) { $from .= " AND e.date_created >= :date_from"; $params[':date_from'] = $filter_date_from . ' 00:00:00'; }
            if (!empty($filter_date_to)) { $from .= " AND e.date_created <= :date_to"; $params[':date_to'] = $filter_date_to . ' 23:59:59'; }
            if (!empty($filter_time_from)) { $from .= " AND TIME(e.date_created) >= :time_from"; $params[':time_from'] = $filter_time_from; }
            if (!empty($filter_time_to)) { $from .= " AND TIME(e.date_created) <= :time_to"; $params[':time_to'] = $filter_time_to; }

            // Total
            $countSql = "SELECT COUNT(*)" . $from;
            $countStmt = $db->prepare($countSql);
            foreach ($params as $k=>$v) { $countStmt->bindValue($k, $v); }
            $countStmt->execute();
            $recordsTotal = (int)$countStmt->fetchColumn();

            // Search
            $searchSql = '';
            if ($searchValue !== '') {
                $searchSql = " AND (s.name LIKE :q OR t.name LIKE :q OR g.grade_name LIKE :q OR c.name LIKE :q OR et.name LIKE :q OR e.reason LIKE :q)";
                $params[':q'] = '%' . $searchValue . '%';
            }

            $countFilteredSql = "SELECT COUNT(*)" . $from . $searchSql;
            $countFilteredStmt = $db->prepare($countFilteredSql);
            foreach ($params as $k=>$v) { $countFilteredStmt->bindValue($k, $v); }
            $countFilteredStmt->execute();
            $recordsFiltered = (int)$countFilteredStmt->fetchColumn();

            $select = "SELECT e.id, e.date_created,
                              s.name AS student_name,
                              t.name AS teacher_name,
                              g.grade_name,
                              c.name AS class_name,
                              et.name AS evaluation_name,
                              et.type,
                              et.points,
                              e.custom_points,
                              e.reason,
                              CASE WHEN e.custom_points IS NOT NULL THEN ABS(e.custom_points) ELSE et.points END AS display_points,
                              CASE WHEN e.custom_points IS NOT NULL THEN (CASE WHEN e.custom_points >= 0 THEN 'positive' ELSE 'negative' END) ELSE et.type END AS display_type";

            if ($orderBy === 'display_type') {
                $orderExpr = "CASE WHEN e.custom_points IS NOT NULL THEN (CASE WHEN e.custom_points >= 0 THEN 'positive' ELSE 'negative' END) ELSE et.type END";
            } elseif ($orderBy === 'display_points') {
                $orderExpr = "CASE WHEN e.custom_points IS NOT NULL THEN ABS(e.custom_points) ELSE et.points END";
            } else {
                $orderExpr = $orderBy;
            }

            $dataSql = $select . $from . $searchSql . " ORDER BY $orderExpr $orderDir LIMIT :start, :length";
            $dataStmt = $db->prepare($dataSql);
            foreach ($params as $k=>$v) { $dataStmt->bindValue($k, $v); }
            $dataStmt->bindValue(':start', $start, PDO::PARAM_INT);
            $dataStmt->bindValue(':length', $length, PDO::PARAM_INT);
            $dataStmt->execute();

            $data = [];
            while ($row = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
                $checkbox = '<input type="checkbox" name="selected_evaluations[]" value="' . (int)$row['id'] . '" class="form-check-input evaluation-checkbox">';
                $actions = '<button type="button" class="btn btn-action-pills btn-delete delete-evaluation-btn" '
                         . 'data-bs-toggle="tooltip" title="حذف" '
                         . 'data-id="' . (int)$row['id'] . '" '
                         . 'data-student-name="' . htmlspecialchars($row['student_name'], ENT_QUOTES, 'UTF-8') . '" '
                         . 'data-evaluation-type="' . htmlspecialchars($row['evaluation_name'], ENT_QUOTES, 'UTF-8') . '">'
                         . '<i class="fas fa-trash"></i></button>';

                $data[] = [
                    $checkbox,
                    $row['id'],
                    htmlspecialchars($row['student_name'], ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($row['teacher_name'], ENT_QUOTES, 'UTF-8'),
                    !empty($row['grade_name']) ? htmlspecialchars($row['grade_name'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">غير محدد</span>',
                    htmlspecialchars($row['class_name'], ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($row['evaluation_name'], ENT_QUOTES, 'UTF-8') . (!empty($row['reason']) ? ('<br><small class="text-muted">السبب: ' . htmlspecialchars($row['reason'], ENT_QUOTES, 'UTF-8') . '</small>') : ''),
                    $row['display_type'] === 'positive' ? '<span class="badge bg-success">إيجابي</span>' : '<span class="badge bg-danger">سلبي</span>',
                    '<span class="badge ' . ($row['display_type'] === 'positive' ? 'bg-success' : 'bg-danger') . '">' . ($row['display_type'] === 'positive' ? '+' : '-') . $row['display_points'] . '</span>',
                    date('Y-m-d H:i', strtotime($row['date_created'])),
                    $actions
                ];
            }

            sendJsonResponse([
                'draw' => $draw,
                'recordsTotal' => $recordsTotal,
                'recordsFiltered' => $recordsFiltered,
                'data' => $data,
            ]);
            break;
        // DataTables server-side for specialist reports page
        case 'specialist_reports_datatable':
            if ($role !== 'specialist') {
                sendJsonResponse(['success'=>false,'message'=>'غير مصرح'], 403);
            }
            $specialistId = (int)$requestSession['user_id'];
            $userModel = new User($db);
            $assigned = $userModel->getAssignedClasses($specialistId);
            $allowedClassIds = array_map('intval', array_column($assigned, 'id'));
            if (empty($allowedClassIds)) {
                sendJsonResponse(['draw'=>(int)($requestGet['draw']??1),'recordsTotal'=>0,'recordsFiltered'=>0,'data'=>[]]);
            }

            $draw = (int)($requestGet['draw'] ?? 1);
            $start = (int)($requestGet['start'] ?? 0);
            $requestedLength = (int)($requestGet['length'] ?? 50);
            $length = $requestedLength === -1 ? PHP_INT_MAX : max(10, min(500, $requestedLength));
            $searchValue = trim($requestGet['search']['value'] ?? '');
            $orderCol = (int)($requestGet['order'][0]['column'] ?? 8); // default date col (now column 8)
            $orderDir = strtoupper($requestGet['order'][0]['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

            // Columns: checkbox, id, student, teacher, class, evaluation(+reason), type, points, date, actions
            $columns = [
                0 => 'checkbox',
                1 => 'e.id',
                2 => 's.name',
                3 => 't.name',
                4 => 'c.name',
                5 => 'et.name',
                6 => 'display_type',
                7 => 'display_points',
                8 => 'e.date_created',
                9 => 'actions'
            ];
            $orderBy = $columns[$orderCol] ?? 'e.date_created';

            // Filters
            $filter_class = $requestGet['class_id'] ?? null;
            $filter_student = $requestGet['student_id'] ?? null;
            $filter_teacher = $requestGet['teacher_id'] ?? null;
            $filter_evaluation_type = $requestGet['evaluation_type_id'] ?? null;
            $filter_date_from = $requestGet['date_from'] ?? null;
            $filter_date_to = $requestGet['date_to'] ?? null;
            $filter_time_from = $requestGet['time_from'] ?? null;
            $filter_time_to = $requestGet['time_to'] ?? null;

            // Base FROM and WHERE with allowed classes
            $from = " FROM evaluations e
                      JOIN users s ON e.student_id = s.id
                      JOIN users t ON e.teacher_id = t.id
                      JOIN classes c ON e.class_id = c.id
                      JOIN evaluation_types et ON e.evaluation_type_id = et.id
                      WHERE e.class_id IN (" . implode(',', $allowedClassIds) . ")";
            $params = [];

            if (!empty($filter_class) && in_array((int)$filter_class, $allowedClassIds, true)) { $from .= " AND e.class_id = :class_id"; $params[':class_id'] = (int)$filter_class; }
            if (!empty($filter_student)) { $from .= " AND e.student_id = :student_id"; $params[':student_id'] = (int)$filter_student; }
            if (!empty($filter_teacher)) { $from .= " AND e.teacher_id = :teacher_id"; $params[':teacher_id'] = (int)$filter_teacher; }
            if (!empty($filter_evaluation_type)) { $from .= " AND e.evaluation_type_id = :evaluation_type_id"; $params[':evaluation_type_id'] = (int)$filter_evaluation_type; }
            if (!empty($filter_date_from)) { $from .= " AND e.date_created >= :date_from"; $params[':date_from'] = $filter_date_from . ' 00:00:00'; }
            if (!empty($filter_date_to)) { $from .= " AND e.date_created <= :date_to"; $params[':date_to'] = $filter_date_to . ' 23:59:59'; }
            if (!empty($filter_time_from)) { $from .= " AND TIME(e.date_created) >= :time_from"; $params[':time_from'] = $filter_time_from; }
            if (!empty($filter_time_to)) { $from .= " AND TIME(e.date_created) <= :time_to"; $params[':time_to'] = $filter_time_to; }

            // Total count
            $countSql = "SELECT COUNT(*)" . $from;
            $countStmt = $db->prepare($countSql);
            foreach ($params as $k=>$v) { $countStmt->bindValue($k, $v); }
            $countStmt->execute();
            $recordsTotal = (int)$countStmt->fetchColumn();

            // Search
            $searchSql = '';
            if ($searchValue !== '') {
                $searchSql = " AND (s.name LIKE :q OR t.name LIKE :q OR c.name LIKE :q OR et.name LIKE :q OR e.reason LIKE :q)";
                $params[':q'] = '%' . $searchValue . '%';
            }

            // Filtered count
            $countFilteredSql = "SELECT COUNT(*)" . $from . $searchSql;
            $countFilteredStmt = $db->prepare($countFilteredSql);
            foreach ($params as $k=>$v) { $countFilteredStmt->bindValue($k, $v); }
            $countFilteredStmt->execute();
            $recordsFiltered = (int)$countFilteredStmt->fetchColumn();

            // Select data
            $select = "SELECT e.id, e.date_created,
                              s.name AS student_name,
                              t.name AS teacher_name,
                              c.name AS class_name,
                              et.name AS evaluation_name,
                              et.type,
                              et.points,
                              e.custom_points,
                              e.reason,
                              CASE WHEN e.custom_points IS NOT NULL THEN ABS(e.custom_points) ELSE et.points END AS display_points,
                              CASE WHEN e.custom_points IS NOT NULL THEN (CASE WHEN e.custom_points >= 0 THEN 'positive' ELSE 'negative' END) ELSE et.type END AS display_type";

            if ($orderBy === 'display_type') {
                $orderExpr = "CASE WHEN e.custom_points IS NOT NULL THEN (CASE WHEN e.custom_points >= 0 THEN 'positive' ELSE 'negative' END) ELSE et.type END";
            } elseif ($orderBy === 'display_points') {
                $orderExpr = "CASE WHEN e.custom_points IS NOT NULL THEN ABS(e.custom_points) ELSE et.points END";
            } else {
                $orderExpr = $orderBy;
            }

            $dataSql = $select . $from . $searchSql . " ORDER BY $orderExpr $orderDir LIMIT :start, :length";
            $dataStmt = $db->prepare($dataSql);
            foreach ($params as $k=>$v) { $dataStmt->bindValue($k, $v); }
            $dataStmt->bindValue(':start', $start, PDO::PARAM_INT);
            $dataStmt->bindValue(':length', $length, PDO::PARAM_INT);
            $dataStmt->execute();

            $data = [];
            while ($row = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
                $checkbox = '<input type="checkbox" class="form-check-input evaluation-checkbox" value="' . (int)$row['id'] . '">';

                $actions = '<button type="button" class="btn btn-action-pills btn-delete delete-evaluation-btn" '
                         . 'data-bs-toggle="tooltip" title="حذف" '
                         . 'data-id="' . (int)$row['id'] . '" '
                         . 'data-student-name="' . htmlspecialchars($row['student_name'], ENT_QUOTES, 'UTF-8') . '" '
                         . 'data-evaluation-type="' . htmlspecialchars($row['evaluation_name'], ENT_QUOTES, 'UTF-8') . '">'
                         . '<i class="fas fa-trash"></i></button>';

                $data[] = [
                    $checkbox,
                    $row['id'],
                    htmlspecialchars($row['student_name'], ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($row['teacher_name'], ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($row['class_name'], ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($row['evaluation_name'], ENT_QUOTES, 'UTF-8') . (!empty($row['reason']) ? ('<br><small class="text-muted">السبب: ' . htmlspecialchars($row['reason'], ENT_QUOTES, 'UTF-8') . '</small>') : ''),
                    $row['display_type'] === 'positive' ? '<span class="badge bg-success">إيجابي</span>' : '<span class="badge bg-danger">سلبي</span>',
                    '<span class="badge ' . ($row['display_type'] === 'positive' ? 'bg-success' : 'bg-danger') . '">' . ($row['display_type'] === 'positive' ? '+' : '-') . $row['display_points'] . '</span>',
                    date('Y-m-d H:i', strtotime($row['date_created'])),
                    $actions
                ];
            }

            sendJsonResponse([
                'draw' => $draw,
                'recordsTotal' => $recordsTotal,
                'recordsFiltered' => $recordsFiltered,
                'data' => $data,
            ]);
            break;
        // DataTables server-side for teacher evaluations
        case 'teacher_evaluations_datatable':
            if ($role !== 'teacher' && $role !== 'admin') {
                sendJsonResponse(['success'=>false,'message'=>'غير مصرح'], 403);
            }
            $teacherId = (int)$requestSession['user_id'];

            // DataTables params
            $draw = (int)($requestGet['draw'] ?? 1);
            $start = (int)($requestGet['start'] ?? 0);
            $requestedLength = (int)($requestGet['length'] ?? 50);
            $length = $requestedLength === -1 ? PHP_INT_MAX : max(10, min(500, $requestedLength));
            $searchValue = trim($requestGet['search']['value'] ?? '');
            $orderCol = (int)($requestGet['order'][0]['column'] ?? 5); // default date col (now column 5)
            $orderDir = strtoupper($requestGet['order'][0]['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

            // Map column index -> DB column
            $columns = [
                0 => 's.name',
                1 => 'c.name',
                2 => 'et.name',
                3 => 'display_type', // handled via CASE in outer select; fall back to et.type for ordering
                4 => 'display_points', // computed; order by computed expression
                5 => 'e.date_created',
            ];
            $orderBy = $columns[$orderCol] ?? 'e.date_created';

            // Filters (optional) to mirror page UI
            $filter_class = $requestGet['class_id'] ?? null;
            $filter_student = $requestGet['student_id'] ?? null;
            $filter_type = $requestGet['type'] ?? null; // positive|negative
            $filter_date_from = $requestGet['date_from'] ?? null;
            $filter_date_to = $requestGet['date_to'] ?? null;

            // Base from clause
            $from = " FROM evaluations e
                      JOIN users s ON e.student_id = s.id
                      JOIN classes c ON e.class_id = c.id
                      JOIN evaluation_types et ON e.evaluation_type_id = et.id
                      WHERE e.teacher_id = :teacher_id";

            $params = [':teacher_id' => $teacherId];

            // Apply filters
            if (!empty($filter_class)) { $from .= " AND e.class_id = :class_id"; $params[':class_id'] = $filter_class; }
            if (!empty($filter_student)) { $from .= " AND e.student_id = :student_id"; $params[':student_id'] = $filter_student; }
            if (!empty($filter_date_from)) { $from .= " AND e.date_created >= :date_from"; $params[':date_from'] = $filter_date_from . ' 00:00:00'; }
            if (!empty($filter_date_to)) { $from .= " AND e.date_created <= :date_to"; $params[':date_to'] = $filter_date_to . ' 23:59:59'; }
            if (!empty($filter_type) && in_array($filter_type, ['positive','negative'], true)) {
                $from .= " AND (CASE WHEN e.custom_points IS NOT NULL THEN CASE WHEN e.custom_points >= 0 THEN 'positive' ELSE 'negative' END ELSE et.type END) = :type";
                $params[':type'] = $filter_type;
            }

            // Records total (without search)
            $countSql = "SELECT COUNT(*)" . $from;
            $countStmt = $db->prepare($countSql);
            foreach ($params as $k=>$v) { $countStmt->bindValue($k, $v); }
            $countStmt->execute();
            $recordsTotal = (int)$countStmt->fetchColumn();

            // Search
            $searchSql = '';
            if ($searchValue !== '') {
                $searchSql = " AND (s.name LIKE :q OR c.name LIKE :q OR et.name LIKE :q OR e.reason LIKE :q)";
                $params[':q'] = '%' . $searchValue . '%';
            }

            // Records filtered
            $countFilteredSql = "SELECT COUNT(*)" . $from . $searchSql;
            $countFilteredStmt = $db->prepare($countFilteredSql);
            foreach ($params as $k=>$v) { $countFilteredStmt->bindValue($k, $v); }
            $countFilteredStmt->execute();
            $recordsFiltered = (int)$countFilteredStmt->fetchColumn();

            // Data query
            $select = "SELECT e.id, e.date_created,
                              s.name AS student_name,
                              c.name AS class_name,
                              et.name AS evaluation_name,
                              et.type,
                              et.points,
                              e.custom_points,
                              e.reason,
                              CASE WHEN e.custom_points IS NOT NULL THEN ABS(e.custom_points) ELSE et.points END AS display_points,
                              CASE WHEN e.custom_points IS NOT NULL THEN (CASE WHEN e.custom_points >= 0 THEN 'positive' ELSE 'negative' END) ELSE et.type END AS display_type";

            // Ordering: handle computed columns by repeating the expression
            if ($orderBy === 'display_type') {
                $orderExpr = "CASE WHEN e.custom_points IS NOT NULL THEN (CASE WHEN e.custom_points >= 0 THEN 'positive' ELSE 'negative' END) ELSE et.type END";
            } elseif ($orderBy === 'display_points') {
                $orderExpr = "CASE WHEN e.custom_points IS NOT NULL THEN ABS(e.custom_points) ELSE et.points END";
            } else {
                $orderExpr = $orderBy;
            }

            $dataSql = $select . $from . $searchSql . " ORDER BY $orderExpr $orderDir LIMIT :start, :length";
            $dataStmt = $db->prepare($dataSql);
            foreach ($params as $k=>$v) { $dataStmt->bindValue($k, $v); }
            $dataStmt->bindValue(':start', $start, PDO::PARAM_INT);
            $dataStmt->bindValue(':length', $length, PDO::PARAM_INT);
            $dataStmt->execute();

            $data = [];
            // Fetch teacher deletion settings once
            $stmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('teacher_delete_limit_enabled', 'teacher_delete_limit_minutes', 'teacher_delete_retroactive', 'teacher_delete_enabled_at')");
            $setts = [];
            while($srow = $stmt->fetch(PDO::FETCH_ASSOC)) { $setts[$srow['setting_key']] = $srow['setting_value']; }

            $deleteEnabled = isset($setts['teacher_delete_limit_enabled']) && $setts['teacher_delete_limit_enabled'] == '1';
            $limitMinutes = isset($setts['teacher_delete_limit_minutes']) ? (int)$setts['teacher_delete_limit_minutes'] : 180;
            $retroactive = !isset($setts['teacher_delete_retroactive']) || $setts['teacher_delete_retroactive'] == '1';
            $enabledAt = isset($setts['teacher_delete_enabled_at']) ? $setts['teacher_delete_enabled_at'] : null;

            while ($row = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
                // Check if evaluation can be deleted
                $createdTime = strtotime($row['date_created']);
                $currentTime = time();
                $minutesDiff = ($currentTime - $createdTime) / 60;

                $canDelete = $deleteEnabled && ($minutesDiff <= $limitMinutes);

                // If not retroactive, check if created after feature was enabled
                if ($canDelete && !$retroactive && $enabledAt) {
                    if ($row['date_created'] < $enabledAt) {
                        $canDelete = false;
                    }
                }

                // Build action buttons
                $actionButtons = '';
                if ($canDelete) {
                    $evalInfo = 'الطالب: ' . $row['student_name'] . '\n' .
                                'التقييم: ' . $row['evaluation_name'] . '\n' .
                                'النقاط: ' . ($row['display_type'] === 'positive' ? '+' : '-') . $row['display_points'];
                    $actionButtons = '<button class="btn btn-action-pills btn-delete delete-evaluation-btn"
                                       data-id="' . $row['id'] . '"
                                       data-info="' . htmlspecialchars($evalInfo, ENT_QUOTES) . '"
                                       data-bs-toggle="tooltip"
                                       title="حذف">
                                       <i class="fas fa-trash"></i>
                                      </button>';
                } elseif (!$deleteEnabled) {
                    $actionButtons = '<span class="text-muted small" title="حذف التقييمات معطل من قبل الإدارة">
                                      <i class="fas fa-ban"></i> غير متاح
                                      </span>';
                } else {
                    $timeAgoStr = $minutesDiff > 60 ? round($minutesDiff/60, 1) . ' ساعة' : round($minutesDiff) . ' دقيقة';
                    $actionButtons = '<span class="text-muted small" title="مر على هذا التقييم ' . $timeAgoStr . '">
                                      <i class="fas fa-lock"></i> مؤمّن
                                      </span>';
                }

                $data[] = [
                    $row['student_name'],
                    $row['class_name'],
                    $row['evaluation_name'] . (!empty($row['reason']) ? ('<br><small class="text-muted">السبب: ' . htmlspecialchars($row['reason']) . '</small>') : ''),
                    $row['display_type'] === 'positive' ? '<span class="badge bg-success">إيجابي</span>' : '<span class="badge bg-danger">سلبي</span>',
                    '<span class="badge ' . ($row['display_type'] === 'positive' ? 'bg-success' : 'bg-danger') . '">' . ($row['display_type'] === 'positive' ? '+' : '-') . $row['display_points'] . '</span>',
                    date('Y-m-d H:i', strtotime($row['date_created'])),
                    $actionButtons
                ];
            }

            sendJsonResponse([
                'draw' => $draw,
                'recordsTotal' => $recordsTotal,
                'recordsFiltered' => $recordsFiltered,
                'data' => $data,
            ]);
            break;

        // Delete teacher evaluation (within 24 hours only)
        case 'delete_teacher_evaluation':
            if ($role !== 'teacher') {
                sendJsonResponse(['success' => false, 'message' => 'غير مصرح بهذا الإجراء'], 403);
            }

            $evaluationId = isset($requestPost['evaluation_id']) ? (int)$requestPost['evaluation_id'] : 0;

            if ($evaluationId <= 0) {
                sendJsonResponse(['success' => false, 'message' => 'معرف التقييم غير صحيح']);
            }

            try {
                // Get evaluation details and check ownership and time
                $checkQuery = "SELECT e.id, e.date_created, e.teacher_id, s.name as student_name,
                                      et.name as evaluation_name, et.points, e.custom_points,
                                      CASE WHEN e.custom_points IS NOT NULL THEN
                                          CASE WHEN e.custom_points >= 0 THEN 'positive' ELSE 'negative' END
                                      ELSE et.type END as display_type,
                                      CASE WHEN e.custom_points IS NOT NULL THEN ABS(e.custom_points)
                                      ELSE et.points END as display_points
                               FROM evaluations e
                               JOIN users s ON e.student_id = s.id
                               JOIN evaluation_types et ON e.evaluation_type_id = et.id
                               WHERE e.id = :eval_id";

                $checkStmt = $db->prepare($checkQuery);
                $checkStmt->bindParam(':eval_id', $evaluationId, PDO::PARAM_INT);
                $checkStmt->execute();
                $evaluation = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if (!$evaluation) {
                    sendJsonResponse(['success' => false, 'message' => 'التقييم غير موجود']);
                }

                // Check if teacher owns this evaluation
                if ($evaluation['teacher_id'] != $requestSession['user_id']) {
                    sendJsonResponse(['success' => false, 'message' => 'لا يمكنك حذف تقييم لم تقم بإضافته']);
                }

                // Fetch teacher deletion settings
                $stmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('teacher_delete_limit_enabled', 'teacher_delete_limit_minutes', 'teacher_delete_retroactive', 'teacher_delete_enabled_at')");
                $setts = [];
                while($srow = $stmt->fetch(PDO::FETCH_ASSOC)) { $setts[$srow['setting_key']] = $srow['setting_value']; }

                $deleteEnabled = isset($setts['teacher_delete_limit_enabled']) && $setts['teacher_delete_limit_enabled'] == '1';
                $limitMinutes = isset($setts['teacher_delete_limit_minutes']) ? (int)$setts['teacher_delete_limit_minutes'] : 180;
                $retroactive = !isset($setts['teacher_delete_retroactive']) || $setts['teacher_delete_retroactive'] == '1';
                $enabledAt = isset($setts['teacher_delete_enabled_at']) ? $setts['teacher_delete_enabled_at'] : null;

                if (!$deleteEnabled) {
                    sendJsonResponse(['success' => false, 'message' => '🚫 عذراً، إمكانية حذف التقييمات معطلة حالياً من قبل الإدارة.']);
                }

                if (!$retroactive && $enabledAt && $evaluation['date_created'] < $enabledAt) {
                    sendJsonResponse(['success' => false, 'message' => '🛡️ عذراً، لا يمكن حذف التقييمات التي أضيفت قبل تفعيل خاصية الحذف في وضع "التقييمات الجديدة فقط".']);
                }

                // Check time limit
                $createdTime = strtotime($evaluation['date_created']);
                $currentTime = time();
                $minutesDiff = ($currentTime - $createdTime) / 60;

                if ($minutesDiff > $limitMinutes) {
                    $limitText = $limitMinutes >= 60 ? round($limitMinutes/60, 1) . ' ساعة' : $limitMinutes . ' دقيقة';
                    $actualText = $minutesDiff >= 60 ? round($minutesDiff/60, 1) . ' ساعة' : round($minutesDiff) . ' دقيقة';

                    $message = "⏰ لا يمكن حذف هذا التقييم\n\n";
                    $message .= "السبب: تجاوزت المدة المسموح بها للحذف (" . $limitText . ")\n";
                    $message .= "المدة الفعلية: " . $actualText;
                    $message .= "\n\nملاحظة: يمكنك حذف التقييمات خلال " . $limitText . " فقط من وقت إضافتها.";
                    sendJsonResponse(['success' => false, 'message' => $message]);
                }

                // Delete the evaluation
                $deleteQuery = "DELETE FROM evaluations WHERE id = :eval_id";
                $deleteStmt = $db->prepare($deleteQuery);
                $deleteStmt->bindParam(':eval_id', $evaluationId, PDO::PARAM_INT);

                if ($deleteStmt->execute()) {
                    $message = "✅ تم حذف التقييم بنجاح\n\n";
                    $message .= "الطالب: " . $evaluation['student_name'] . "\n";
                    $message .= "التقييم: " . $evaluation['evaluation_name'] . "\n";
                    $message .= "النقاط: " . ($evaluation['display_type'] === 'positive' ? '+' : '-') . $evaluation['display_points'];

                    sendJsonResponse(['success' => true, 'message' => $message]);
                } else {
                    sendJsonResponse(['success' => false, 'message' => 'فشل في حذف التقييم من قاعدة البيانات']);
                }

            } catch (PDOException $e) {
                error_log("Delete teacher evaluation error: " . $e->getMessage());
                sendJsonResponse(['success' => false, 'message' => 'حدث خطأ أثناء الحذف. يرجى المحاولة مرة أخرى.']);
            }
            break;

}
