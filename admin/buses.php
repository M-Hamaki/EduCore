<?php
/**
 * إدارة الحافلات - Buses Management
 * Rebuilt with Modal Forms, Cascading Dropdowns, and Statistics Cards
 */
$page_title = "إدارة الحافلات";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../classes/UndoManager.php';
require_once '../src/Modules/Operations/Audit/AuditService.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

$database = new Database();
$db = $database->getConnection();
UndoManager::setDb($db);

// =============== AJAX ENDPOINTS ===============
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    if ($_GET['ajax'] === 'get_bus') {
        $busId = (int)($_GET['id'] ?? 0);

        $stmtBus = $db->prepare("SELECT * FROM buses WHERE id = ?");
        $stmtBus->execute([$busId]);
        $bus = $stmtBus->fetch(PDO::FETCH_ASSOC);

        if (!$bus) {
            echo json_encode(['success' => false, 'message' => 'الحافلة غير موجودة.']);
            exit();
        }

        $stmtStops = $db->prepare("SELECT * FROM bus_route_stops WHERE bus_id = ? ORDER BY stop_order");
        $stmtStops->execute([$busId]);
        $stops = $stmtStops->fetchAll(PDO::FETCH_ASSOC);

        $stmtStaff = $db->prepare("SELECT bs.* FROM bus_staff bs JOIN bus_staff_assignments bsa ON bs.id = bsa.staff_id WHERE bsa.bus_id = ? ORDER BY bs.role, bs.id");
        $stmtStaff->execute([$busId]);
        $staff = $stmtStaff->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'bus' => $bus,
            'stops' => $stops,
            'staff' => $staff
        ]);
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'طلب غير صالح.']);
    exit();
}

// PRG: session messages
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// =============== POST HANDLERS ===============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['error_message'] = "خطأ في التحقق من الأمان (CSRF Token Invalid)";
        header("Location: buses.php");
        exit();
    }

    $action = $_POST['action'] ?? '';

    // ===== إضافة / تعديل حافلة =====
    if ($action === 'save_bus') {
        $busId = !empty($_POST['bus_id']) ? (int)$_POST['bus_id'] : null;
        $busNumber = trim($_POST['bus_number'] ?? '');
        $capacity = !empty($_POST['capacity']) ? (int)$_POST['capacity'] : null;
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';
        $busNotes = trim($_POST['bus_notes'] ?? '');

        if (empty($busNumber)) {
            $_SESSION['error_message'] = 'رقم الحافلة مطلوب.';
            header("Location: buses.php");
            exit();
        }

        try {
            $db->beginTransaction();
            $audit = new \EduCore\Modules\Operations\Audit\AuditService($db);
            $isEdit = $busId !== null;
            $beforeAggregate = [];
            if ($isEdit) {
                $beforeBusStmt = $db->prepare('SELECT * FROM buses WHERE id = ? FOR UPDATE');
                $beforeBusStmt->execute([$busId]);
                $beforeAggregate['bus'] = $beforeBusStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $beforeStopsStmt = $db->prepare('SELECT * FROM bus_route_stops WHERE bus_id = ? ORDER BY stop_order, id');
                $beforeStopsStmt->execute([$busId]);
                $beforeAggregate['stops'] = $beforeStopsStmt->fetchAll(PDO::FETCH_ASSOC);
                $beforeStaffStmt = $db->prepare('SELECT staff_id FROM bus_staff_assignments WHERE bus_id = ? ORDER BY staff_id');
                $beforeStaffStmt->execute([$busId]);
                $beforeAggregate['staff_ids'] = array_map('intval', $beforeStaffStmt->fetchAll(PDO::FETCH_COLUMN));
            }

            // 1. Determine the automatically computed area based on the first valid stop's city (or governorate)
            $stopGovs = $_POST['stop_governorate'] ?? [];
            $stopCities = $_POST['stop_city'] ?? [];
            
            $firstValidIndex = null;
            for ($i = 0; $i < count($stopGovs); $i++) {
                if (!empty($stopGovs[$i])) {
                    $firstValidIndex = $i;
                    break;
                }
            }

            $area = 'غير محدد';
            if ($firstValidIndex !== null) {
                $firstStopGov = (int)$stopGovs[$firstValidIndex];
                $firstStopCity = !empty($stopCities[$firstValidIndex]) ? (int)$stopCities[$firstValidIndex] : null;
                
                if ($firstStopCity) {
                    $stmtCity = $db->prepare("SELECT name FROM cities WHERE id = ?");
                    $stmtCity->execute([$firstStopCity]);
                    $cityName = $stmtCity->fetchColumn();
                    if ($cityName) $area = $cityName;
                } elseif ($firstStopGov) {
                    $stmtGov = $db->prepare("SELECT name FROM governorates WHERE id = ?");
                    $stmtGov->execute([$firstStopGov]);
                    $govName = $stmtGov->fetchColumn();
                    if ($govName) $area = $govName;
                }
            }

            // 2. Insert or update the buses table
            if ($busId) {
                $stmt = $db->prepare("UPDATE buses SET bus_number = ?, capacity = ?, status = ?, notes = ?, area = ? WHERE id = ?");
                $stmt->execute([$busNumber, $capacity, $status, $busNotes ?: null, $area, $busId]);
            } else {
                $stmt = $db->prepare("INSERT INTO buses (bus_number, capacity, status, notes, area) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$busNumber, $capacity, $status, $busNotes ?: null, $area]);
                $busId = $db->lastInsertId();
            }

            // 3. Save Stops
            $db->prepare("DELETE FROM bus_route_stops WHERE bus_id = ?")->execute([$busId]);
            $stopGovs = $_POST['stop_governorate'] ?? [];
            $stopCities = $_POST['stop_city'] ?? [];
            $stopCenters = $_POST['stop_center'] ?? [];
            $stopNeis = $_POST['stop_neighborhood'] ?? [];
            $stopStreets = $_POST['stop_street'] ?? [];
            $stopNotes = $_POST['stop_notes'] ?? [];

            $stmtStop = $db->prepare("INSERT INTO bus_route_stops (bus_id, stop_order, governorate_id, city_id, center_id, neighborhood_id, street_id, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $order = 1;
            for ($i = 0; $i < count($stopGovs); $i++) {
                $gov = !empty($stopGovs[$i]) ? (int)$stopGovs[$i] : null;
                $city = !empty($stopCities[$i]) ? (int)$stopCities[$i] : null;
                $center = !empty($stopCenters[$i]) ? (int)$stopCenters[$i] : null;
                $nei = !empty($stopNeis[$i]) ? (int)$stopNeis[$i] : null;
                $street = !empty($stopStreets[$i]) ? (int)$stopStreets[$i] : null;
                $note = trim($stopNotes[$i] ?? '');

                // Save only if at least one field has been set (optional fields)
                if ($gov || $city || $center || $nei || $street || !empty($note)) {
                    $stmtStop->execute([$busId, $order++, $gov, $city, $center, $nei, $street, $note ?: null]);
                }
            }

            // 4. Save Staff (Update assignments in pivot table)
            $db->prepare("DELETE FROM bus_staff_assignments WHERE bus_id = ?")->execute([$busId]);
            $staffIds = $_POST['staff_ids'] ?? [];
            if (!empty($staffIds)) {
                $stmtStaff = $db->prepare("INSERT IGNORE INTO bus_staff_assignments (bus_id, staff_id) VALUES (?, ?)");
                foreach ($staffIds as $sId) {
                    $sId = (int)$sId;
                    if ($sId > 0) {
                        $stmtStaff->execute([$busId, $sId]);
                    }
                }
            }
            // Sync legacy column bus_staff.bus_id (set bus_id to this bus for all assigned staff, and clear for unassigned staff)
            $db->prepare("UPDATE bus_staff SET bus_id = NULL WHERE bus_id = ?")->execute([$busId]);
            if (!empty($staffIds)) {
                $stmtLegacy = $db->prepare("UPDATE bus_staff SET bus_id = ? WHERE id = ?");
                foreach ($staffIds as $sId) {
                    $sId = (int)$sId;
                    if ($sId > 0) {
                        $stmtLegacy->execute([$busId, $sId]);
                    }
                }
            }

            // 5. Update Simplified Route Description for Table display
            $stmtStopsFetch = $db->prepare("SELECT s.*, gov.name as gov_name, cit.name as city_name, cen.name as center_name, nei.name as nei_name, str.name as street_name 
                                     FROM bus_route_stops s 
                                     LEFT JOIN governorates gov ON s.governorate_id=gov.id 
                                     LEFT JOIN cities cit ON s.city_id=cit.id 
                                     LEFT JOIN centers cen ON s.center_id=cen.id 
                                     LEFT JOIN neighborhoods nei ON s.neighborhood_id=nei.id 
                                     LEFT JOIN streets str ON s.street_id=str.id 
                                     WHERE s.bus_id = ? ORDER BY s.stop_order");
            $stmtStopsFetch->execute([$busId]);
            $stopsList = $stmtStopsFetch->fetchAll(PDO::FETCH_ASSOC);
            $routeParts = [];
            foreach ($stopsList as $stop) {
                $parts = [];
                if ($stop['gov_name']) $parts[] = $stop['gov_name'];
                if ($stop['city_name']) $parts[] = $stop['city_name'];
                if ($stop['nei_name']) $parts[] = $stop['nei_name'];
                if ($stop['street_name']) $parts[] = $stop['street_name'];
                if (!empty($parts)) {
                    $routeParts[] = implode(' ← ', $parts);
                }
            }
            $routeDesc = implode(' | ', $routeParts);
            $db->prepare("UPDATE buses SET route_description = ? WHERE id = ?")->execute([$routeDesc, $busId]);

            $afterBusStmt = $db->prepare('SELECT * FROM buses WHERE id = ?');
            $afterBusStmt->execute([$busId]);
            $afterStopsStmt = $db->prepare('SELECT * FROM bus_route_stops WHERE bus_id = ? ORDER BY stop_order, id');
            $afterStopsStmt->execute([$busId]);
            $afterStaffStmt = $db->prepare('SELECT staff_id FROM bus_staff_assignments WHERE bus_id = ? ORDER BY staff_id');
            $afterStaffStmt->execute([$busId]);
            $afterAggregate = [
                'bus' => $afterBusStmt->fetch(PDO::FETCH_ASSOC) ?: [],
                'stops' => $afterStopsStmt->fetchAll(PDO::FETCH_ASSOC),
                'staff_ids' => array_map('intval', $afterStaffStmt->fetchAll(PDO::FETCH_COLUMN)),
            ];
            $audit->recordEvent(
                $isEdit ? 'update' : 'create',
                'bus',
                (int)$busId,
                $busNumber,
                [
                    'summary' => $isEdit ? 'تعديل الحافلة ومسارها وطاقمها' : 'إضافة حافلة ومسارها وطاقمها',
                    'changes' => \EduCore\Modules\Operations\Audit\EntityChangeTracker::diff($beforeAggregate, $afterAggregate),
                    'undo_policy' => 'composite_restore_not_enabled',
                ]
            );

            $db->commit();
            $_SESSION['success_message'] = !empty($_POST['bus_id']) ? 'تم تعديل الحافلة بنجاح.' : 'تم إضافة الحافلة بنجاح.';
            header("Location: buses.php");
            exit();

        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($e->getCode() == 23000) {
                $_SESSION['error_message'] = 'رقم الحافلة موجود مسبقاً.';
            } else {
                error_log('buses save error: ' . $e->getMessage());
                $_SESSION['error_message'] = 'تعذر حفظ بيانات الحافلة.';
            }
            header("Location: buses.php");
            exit();
        }
    }

    // ===== حذف حافلة =====
    elseif ($action === 'delete_bus' && !empty($_POST['bus_id'])) {
        $busId = (int)$_POST['bus_id'];
        try {
            $db->beginTransaction();
            $oldData = UndoManager::fetchRecord('buses', $busId);
            $oldStopsStmt = $db->prepare('SELECT * FROM bus_route_stops WHERE bus_id = ? ORDER BY stop_order, id');
            $oldStopsStmt->execute([$busId]);
            $oldStaffStmt = $db->prepare('SELECT * FROM bus_staff WHERE bus_id = ? ORDER BY id');
            $oldStaffStmt->execute([$busId]);
            $oldAggregate = [
                'bus' => $oldData ?: [],
                'stops' => $oldStopsStmt->fetchAll(PDO::FETCH_ASSOC),
                'staff' => $oldStaffStmt->fetchAll(PDO::FETCH_ASSOC),
            ];
            
            $db->prepare("DELETE FROM bus_route_stops WHERE bus_id = ?")->execute([$busId]);
            $db->prepare("DELETE FROM bus_staff WHERE bus_id = ?")->execute([$busId]);
            $db->prepare("DELETE FROM buses WHERE id = ?")->execute([$busId]);

            (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordEvent(
                'delete', 'bus', $busId, (string)($oldData['bus_number'] ?? ('حافلة #' . $busId)),
                [
                    'summary' => 'حذف الحافلة والعناصر المرتبطة بها',
                    'deleted_snapshot' => $oldAggregate,
                    'undo_policy' => 'composite_restore_not_enabled',
                ]
            );
            
            $db->commit();
            $_SESSION['success_message'] = 'تم حذف الحافلة بنجاح.';
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('buses delete error: ' . $e->getMessage());
            $_SESSION['error_message'] = 'تعذر حذف الحافلة.';
        }
        header("Location: buses.php");
        exit();
    }

    // ===== تبديل حالة حافلة =====
    elseif ($action === 'toggle_status' && !empty($_POST['bus_id'])) {
        $busId = (int)$_POST['bus_id'];
        try {
            $db->beginTransaction();
            $oldData = UndoManager::fetchRecord('buses', $busId);
            
            $stmt = $db->prepare("SELECT status FROM buses WHERE id = ?");
            $stmt->execute([$busId]);
            $currentStatus = $stmt->fetchColumn();
            $newStatus = ($currentStatus === 'active') ? 'inactive' : 'active';
            
            $stmtUpdate = $db->prepare("UPDATE buses SET status = ? WHERE id = ?");
            $stmtUpdate->execute([$newStatus, $busId]);
            
            $newData = UndoManager::fetchRecord('buses', $busId);
            (new \EduCore\Modules\Operations\Audit\AuditService($db))->recordUpdate(
                'bus', 'buses', $busId, (string)($newData['bus_number'] ?? ('حافلة #' . $busId)),
                $oldData ?: [], $newData ?: [], ($newStatus === 'active' ? 'تفعيل حافلة' : 'تعطيل حافلة')
            );
            
            $db->commit();
            $_SESSION['success_message'] = ($newStatus === 'active') ? 'تم تفعيل الحافلة بنجاح.' : 'تم تعطيل الحافلة بنجاح.';
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('buses toggle status error: ' . $e->getMessage());
            $_SESSION['error_message'] = 'تعذر تغيير حالة الحافلة.';
        }
        header("Location: buses.php");
        exit();
    }
}

// ================= FETCH DATA FOR PAGE =================
$totalBuses = (int)$db->query("SELECT COUNT(*) FROM buses")->fetchColumn();
$activeBuses = (int)$db->query("SELECT COUNT(*) FROM buses WHERE status = 'active'")->fetchColumn();
$inactiveBuses = (int)$db->query("SELECT COUNT(*) FROM buses WHERE status = 'inactive'")->fetchColumn();
$availableSeats = (int)$db->query("
    SELECT SUM(
        GREATEST(0, b.capacity - (
            SELECT COUNT(*) FROM student_bus_assignments sba
            JOIN users su ON su.id = sba.student_id AND su.status = 'active' AND su.deleted_at IS NULL
            WHERE sba.bus_id = b.id
        ))
    )
    FROM buses b
    WHERE b.status = 'active' AND b.capacity > 0
")->fetchColumn();
$overCapacityBusesList = $db->query("
    SELECT b.id, b.bus_number, b.capacity, 
           (SELECT COUNT(*) FROM student_bus_assignments sba
            JOIN users su ON su.id = sba.student_id AND su.status = 'active' AND su.deleted_at IS NULL
            WHERE sba.bus_id = b.id) as student_count
    FROM buses b 
    WHERE b.capacity > 0 
    HAVING student_count > b.capacity
    ORDER BY b.bus_number
")->fetchAll(PDO::FETCH_ASSOC);
$overCapacityBuses = count($overCapacityBusesList);

// ===== Filter parameters =====
$filterGov   = !empty($_GET['gov'])   ? (int)$_GET['gov']   : 0;
$filterOverCapacity = $_GET['over_capacity'] ?? '';
$filterCity  = !empty($_GET['city'])  ? (int)$_GET['city']  : 0;
$filterCenter = !empty($_GET['center']) ? (int)$_GET['center'] : 0;
$filterNei   = !empty($_GET['nei'])   ? (int)$_GET['nei']   : 0;
$filterStreet = !empty($_GET['street']) ? (int)$_GET['street'] : 0;

// Auto-resolve parents if a deep level is selected directly (Table filters reload)
if ($filterStreet) {
    $stmtLoc = $db->prepare("SELECT n.center_id, cen.city_id, cit.governorate_id, s.neighborhood_id 
                             FROM streets s 
                             LEFT JOIN neighborhoods n ON s.neighborhood_id = n.id
                             LEFT JOIN centers cen ON n.center_id = cen.id
                             LEFT JOIN cities cit ON cen.city_id = cit.id
                             WHERE s.id = ?");
    $stmtLoc->execute([$filterStreet]);
    $locData = $stmtLoc->fetch(PDO::FETCH_ASSOC);
    if ($locData) {
        if (!$filterNei) $filterNei = (int)$locData['neighborhood_id'];
        if (!$filterCenter) $filterCenter = (int)$locData['center_id'];
        if (!$filterCity) $filterCity = (int)$locData['city_id'];
        if (!$filterGov) $filterGov = (int)$locData['governorate_id'];
    }
} elseif ($filterNei) {
    $stmtLoc = $db->prepare("SELECT n.center_id, cen.city_id, cit.governorate_id 
                             FROM neighborhoods n 
                             LEFT JOIN centers cen ON n.center_id = cen.id
                             LEFT JOIN cities cit ON cen.city_id = cit.id
                             WHERE n.id = ?");
    $stmtLoc->execute([$filterNei]);
    $locData = $stmtLoc->fetch(PDO::FETCH_ASSOC);
    if ($locData) {
        if (!$filterCenter) $filterCenter = (int)$locData['center_id'];
        if (!$filterCity) $filterCity = (int)$locData['city_id'];
        if (!$filterGov) $filterGov = (int)$locData['governorate_id'];
    }
} elseif ($filterCenter) {
    $stmtLoc = $db->prepare("SELECT cen.city_id, cit.governorate_id 
                             FROM centers cen 
                             LEFT JOIN cities cit ON cen.city_id = cit.id
                             WHERE cen.id = ?");
    $stmtLoc->execute([$filterCenter]);
    $locData = $stmtLoc->fetch(PDO::FETCH_ASSOC);
    if ($locData) {
        if (!$filterCity) $filterCity = (int)$locData['city_id'];
        if (!$filterGov) $filterGov = (int)$locData['governorate_id'];
    }
} elseif ($filterCity) {
    $stmtLoc = $db->prepare("SELECT governorate_id FROM cities WHERE id = ?");
    $stmtLoc->execute([$filterCity]);
    $locData = $stmtLoc->fetch(PDO::FETCH_ASSOC);
    if ($locData) {
        if (!$filterGov) $filterGov = (int)$locData['governorate_id'];
    }
}

// Build bus IDs matching location filter
$filteredBusIds = null;
if ($filterGov || $filterCity || $filterCenter || $filterNei || $filterStreet) {
    $filterWhere = [];
    $filterParams = [];
    if ($filterStreet)  { $filterWhere[] = 's.street_id = ?';       $filterParams[] = $filterStreet; }
    elseif ($filterNei) { $filterWhere[] = 's.neighborhood_id = ?'; $filterParams[] = $filterNei; }
    elseif ($filterCenter){ $filterWhere[] = 's.center_id = ?';     $filterParams[] = $filterCenter; }
    elseif ($filterCity){ $filterWhere[] = 's.city_id = ?';         $filterParams[] = $filterCity; }
    elseif ($filterGov) { $filterWhere[] = 's.governorate_id = ?';  $filterParams[] = $filterGov; }
    $fSql = "SELECT DISTINCT bus_id FROM bus_route_stops s WHERE " . implode(' AND ', $filterWhere);
    $fStmt = $db->prepare($fSql);
    $fStmt->execute($filterParams);
    $filteredBusIds = $fStmt->fetchAll(PDO::FETCH_COLUMN);
}

// Fetch buses (with optional filter)
if ($filteredBusIds !== null) {
    if (empty($filteredBusIds)) {
        $buses = [];
    } else {
        $placeholders = implode(',', array_fill(0, count($filteredBusIds), '?'));
        $busStmt = $db->prepare("SELECT b.*, (SELECT COUNT(*) FROM student_bus_assignments sba JOIN users su ON su.id = sba.student_id AND su.status = 'active' AND su.deleted_at IS NULL WHERE sba.bus_id = b.id) as student_count
            FROM buses b WHERE b.id IN ($placeholders) ORDER BY b.bus_number");
        $busStmt->execute($filteredBusIds);
        $buses = $busStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} else {
    $buses = $db->query("SELECT b.*, 
        (SELECT COUNT(*) FROM student_bus_assignments sba JOIN users su ON su.id = sba.student_id AND su.status = 'active' AND su.deleted_at IS NULL WHERE sba.bus_id = b.id) as student_count
        FROM buses b ORDER BY b.bus_number")->fetchAll(PDO::FETCH_ASSOC);
}

// Filter by over capacity if requested
if ($filterOverCapacity === '1') {
    $buses = array_filter($buses, function($bus) {
        return (int)$bus['capacity'] > 0 && (int)$bus['student_count'] > (int)$bus['capacity'];
    });
} elseif ($filterOverCapacity === '0') {
    $buses = array_filter($buses, function($bus) {
        return (int)$bus['capacity'] > 0 && (int)$bus['student_count'] <= (int)$bus['capacity'];
    });
}

// Fetch all staff assignments
$allStaff = $db->query("SELECT bs.*, bsa.bus_id FROM bus_staff bs JOIN bus_staff_assignments bsa ON bs.id = bsa.staff_id ORDER BY bsa.bus_id, bs.role, bs.id")->fetchAll(PDO::FETCH_ASSOC);
$staffByBus = [];
foreach ($allStaff as $s) {
    $staffByBus[$s['bus_id']][] = $s;
}

// Fetch all stops
$allStops = $db->query("SELECT s.*, 
       gov.name as governorate_name, 
       cit.name as city_name, 
       cen.name as center_name, 
       nei.name as neighborhood_name, 
       str.name as street_name
FROM bus_route_stops s
LEFT JOIN governorates gov ON s.governorate_id = gov.id
LEFT JOIN cities cit ON s.city_id = cit.id
LEFT JOIN centers cen ON s.center_id = cen.id
LEFT JOIN neighborhoods nei ON s.neighborhood_id = nei.id
LEFT JOIN streets str ON s.street_id = str.id
ORDER BY s.bus_id, s.stop_order")->fetchAll(PDO::FETCH_ASSOC);

$stopsByBus = [];
foreach ($allStops as $stop) {
    $stopsByBus[$stop['bus_id']][] = $stop;
}

// Pre-load active governorates
$governorates = $db->query("SELECT id, name FROM governorates WHERE status='active' ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);

// Pre-load filter dropdown data (cascading)
if ($filterGov) {
    $stmt = $db->prepare("SELECT id, name FROM cities WHERE governorate_id = ? AND status='active' ORDER BY name");
    $stmt->execute([$filterGov]);
    $filterCities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $filterCities = $db->query("SELECT id, name FROM cities WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

$filterCenters = [];
if ($filterCity) {
    $stmt = $db->prepare("SELECT id, name FROM centers WHERE city_id = ? AND status='active' ORDER BY name");
    $stmt->execute([$filterCity]);
    $filterCenters = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $filterCenters = $db->query("SELECT id, name FROM centers WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

$filterNeighborhoods = [];
if ($filterCenter) {
    $stmt = $db->prepare("SELECT id, name FROM neighborhoods WHERE center_id = ? AND status='active' ORDER BY name");
    $stmt->execute([$filterCenter]);
    $filterNeighborhoods = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $filterNeighborhoods = $db->query("SELECT id, name FROM neighborhoods WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

$filterStreets = [];
if ($filterNei) {
    $stmt = $db->prepare("SELECT id, name FROM streets WHERE neighborhood_id = ? AND status='active' ORDER BY name");
    $stmt->execute([$filterNei]);
    $filterStreets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $filterStreets = $db->query("SELECT id, name FROM streets WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

// Pre-load all registered bus staff with all currently assigned bus numbers
$allAvailableStaff = $db->query("SELECT bs.id, bs.name, bs.role, bs.phones, bs.notes,
       (SELECT GROUP_CONCAT(b.bus_number ORDER BY b.bus_number SEPARATOR '، ') 
        FROM bus_staff_assignments bsa 
        JOIN buses b ON bsa.bus_id = b.id 
        WHERE bsa.staff_id = bs.id) as assigned_buses
FROM bus_staff bs
ORDER BY bs.role, bs.name")->fetchAll(PDO::FETCH_ASSOC);

// Pre-load all active location levels for modal select options
$allGovs = $db->query("SELECT id, name FROM governorates WHERE status='active' ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);
$allCities = $db->query("SELECT id, name, governorate_id FROM cities WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$allCenters = $db->query("SELECT id, name, city_id FROM centers WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$allNeis = $db->query("SELECT id, name, center_id FROM neighborhoods WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$allStreets = $db->query("SELECT id, name, neighborhood_id FROM streets WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

require_once '../includes/admin_header.php';
?>

<!-- Page Header Toolbar -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-bus me-2 text-primary"></i>إدارة الحافلات</h1>
    <div class="admin-top-actions no-print">
        <button type="button" class="btn btn-header-premium btn-success shadow-sm" id="openAddBusModalBtn">
            <i class="fas fa-plus-circle me-1"></i>إضافة حافلة جديدة
        </button>
        <button type="button" class="btn btn-header-premium btn-import-soft" data-bs-toggle="modal" data-bs-target="#importBusesModal">
            <i class="fas fa-file-import me-1"></i>استيراد Excel
        </button>
    </div>
</div>

<!-- Alerts -->
<?php if ($success_message): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<!-- Stat Cards Row -->
<div class="row g-3 mb-4">
    <!-- إجمالي الحافلات -->
    <div class="col-6 col-md">
        <a href="buses.php" class="text-decoration-none d-block h-100">
            <div class="stat-card h-100" style="--card-gradient: linear-gradient(135deg, #3b82f6, #2563eb);">
                <div class="stat-card-icon"><i class="fas fa-bus"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo $totalBuses; ?>">0</div>
                    <div class="stat-card-label">إجمالي الحافلات</div>
                </div>
            </div>
        </a>
    </div>
    <!-- حافلات نشطة -->
    <div class="col-6 col-md">
        <div class="stat-card h-100" style="--card-gradient: linear-gradient(135deg, #10b981, #059669);">
            <div class="stat-card-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $activeBuses; ?>">0</div>
                <div class="stat-card-label">حافلات نشطة</div>
            </div>
        </div>
    </div>
    <!-- حافلات متوقفة -->
    <div class="col-6 col-md">
        <div class="stat-card h-100" style="--card-gradient: linear-gradient(135deg, #f59e0b, #d97706);">
            <div class="stat-card-icon"><i class="fas fa-ban"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $inactiveBuses; ?>">0</div>
                <div class="stat-card-label">حافلات متوقفة</div>
            </div>
        </div>
    </div>
    <!-- حافلات تجاوزت السعة -->
    <div class="col-6 col-md">
        <a href="#" data-bs-toggle="modal" data-bs-target="#overCapacityModal" class="text-decoration-none d-block h-100">
            <div class="stat-card h-100 shadow-sm" style="--card-gradient: linear-gradient(135deg, #ef4444, #dc2626); transition: transform 0.2s;">
                <div class="stat-card-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="stat-card-info">
                    <div class="stat-card-number counter" data-target="<?php echo $overCapacityBuses; ?>">0</div>
                    <div class="stat-card-label">تجاوزت السعة</div>
                </div>
            </div>
        </a>
    </div>
    <!-- طلاب مقيدون -->
    <!-- مقاعد شاغرة -->
    <div class="col-6 col-md">
        <div class="stat-card h-100" style="--card-gradient: linear-gradient(135deg, #8b5cf6, #7c3aed);">
            <div class="stat-card-icon"><i class="fas fa-chair"></i></div>
            <div class="stat-card-info">
                <div class="stat-card-number counter" data-target="<?php echo $availableSeats; ?>">0</div>
                <div class="stat-card-label">مقاعد شاغرة</div>
            </div>
        </div>
    </div>
</div>

<!-- buses list table -->
<form method="GET" action="buses.php" class="admin-filter-bar no-print mb-3" id="busFilterForm">
    <div class="admin-filter-controls">
        <!-- المحافظة -->
        <select name="gov" class="form-select form-select-sm" style="width:auto; min-width:130px;" id="filterGov" onchange="this.form.submit()">
            <option value="">كل المحافظات</option>
            <?php foreach ($governorates as $g): ?>
                <option value="<?php echo $g['id']; ?>" <?php echo ($filterGov == $g['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($g['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <!-- المدينة -->
        <select name="city" class="form-select form-select-sm" style="width:auto; min-width:120px;" id="filterCity" onchange="this.form.submit()">
            <option value="">كل المدن</option>
            <?php foreach ($filterCities as $c): ?>
                <option value="<?php echo $c['id']; ?>" <?php echo ($filterCity == $c['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <!-- المركز -->
        <select name="center" class="form-select form-select-sm" style="width:auto; min-width:120px;" onchange="this.form.submit()">
            <option value="">كل المراكز</option>
            <?php foreach ($filterCenters as $c): ?>
                <option value="<?php echo $c['id']; ?>" <?php echo ($filterCenter == $c['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <!-- الحي -->
        <select name="nei" class="form-select form-select-sm" style="width:auto; min-width:110px;" onchange="this.form.submit()">
            <option value="">كل الأحياء</option>
            <?php foreach ($filterNeighborhoods as $n): ?>
                <option value="<?php echo $n['id']; ?>" <?php echo ($filterNei == $n['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($n['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <!-- الشارع -->
        <select name="street" class="form-select form-select-sm" style="width:auto; min-width:110px;" onchange="this.form.submit()">
            <option value="">كل الشوارع</option>
            <?php foreach ($filterStreets as $s): ?>
                <option value="<?php echo $s['id']; ?>" <?php echo ($filterStreet == $s['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <!-- فلتر تجاوز السعة -->
        <select name="over_capacity" class="form-select form-select-sm" style="width:auto; min-width:140px;" onchange="this.form.submit()">
            <option value="">كل السعات</option>
            <option value="1" <?php echo $filterOverCapacity === '1' ? 'selected' : ''; ?>>تجاوز السعة المقعدية</option>
            <option value="0" <?php echo $filterOverCapacity === '0' ? 'selected' : ''; ?>>لم تتجاوز السعة المقعدية</option>
        </select>
    </div>
    <div class="admin-filter-actions">
        <a href="buses.php" class="btn btn-light btn-sm" title="إعادة تعيين الفلاتر">
            <i class="fas fa-rotate-left me-1"></i>إعادة تعيين
        </a>
        <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#busTableSettingsModal" title="تخصيص أعمدة الجدول">
            <i class="fas fa-cog me-1"></i>إعدادات الجدول
        </button>
    </div>
</form>

<div class="admin-list-surface animate-up delay-1">
        <?php if (empty($buses)): ?>
            <div class="alert alert-info m-3"><i class="fas fa-info-circle me-2"></i>
                <?php echo ($filterGov || $filterCity || $filterCenter || $filterNei || $filterStreet) ? 'لا توجد حافلات تطابق الفلاتر المحددة.' : 'لا توجد حافلات مسجلة بعد.'; ?>
            </div>
        <?php else: ?>
        <div class="table-responsive admin-table-wrap">
            <table class="table table-hover table-striped datatable align-middle admin-data-table mb-0" id="busesTable">
                <thead>
                    <tr>
                        <th width="45" data-col="col_num">#</th>
                        <th data-col="col_bus">رقم الحافلة</th>
                        <th data-col="col_route">خط السير والمحطات</th>
                        <th data-col="col_driver">السائق</th>
                        <th data-col="col_supervisors">المشرفون</th>
                        <th data-col="col_students">عدد الطلاب</th>
                        <th data-col="col_status">الحالة</th>
                        <th style="width: 160px; min-width: 160px;" data-col="col_actions">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $n = 0; foreach ($buses as $bus): $n++;
                        $busStaff = $staffByBus[$bus['id']] ?? [];
                        $drivers = array_filter($busStaff, fn($s) => $s['role'] === 'driver');
                        $supervisors = array_filter($busStaff, fn($s) => $s['role'] === 'supervisor');
                        $busStops = $stopsByBus[$bus['id']] ?? [];
                        // Determine first stop city/governorate to avoid duplicating area badge
                        $firstStopCity = !empty($busStops[0]['city_name']) ? $busStops[0]['city_name'] : '';
                        $firstStopGov  = !empty($busStops[0]['governorate_name']) ? $busStops[0]['governorate_name'] : '';
                        $areaLabel = $bus['area'] ?? 'غير محدد';
                        // Show area badge only if it differs from what the stops already show
                        $showAreaBadge = ($areaLabel !== $firstStopCity && $areaLabel !== $firstStopGov && $areaLabel !== 'غير محدد') || empty($busStops);
                    ?>
                    <tr>
                        <td data-col="col_num"><?php echo $n; ?></td>
                        <td data-col="col_bus"><strong class="text-primary fs-6"><?php echo htmlspecialchars($bus['bus_number']); ?></strong></td>
                        <td data-col="col_route">
                            <?php if ($showAreaBadge): ?>
                            <div class="mb-1">
                                <span class="badge bg-secondary-subtle text-secondary border"><i class="fas fa-map-marker-alt text-primary me-1"></i><?php echo htmlspecialchars($areaLabel); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if (empty($busStops)): ?>
                                <span class="text-muted small">لا توجد محطات</span>
                            <?php else: ?>
                                <div class="d-flex flex-wrap gap-1 mt-1">
                                    <?php foreach ($busStops as $stop):
                                        $stopNameParts = [];
                                        if (!empty($stop['governorate_name'])) $stopNameParts[] = $stop['governorate_name'];
                                        if (!empty($stop['city_name'])) $stopNameParts[] = $stop['city_name'];
                                        if (!empty($stop['center_name'])) $stopNameParts[] = $stop['center_name'];
                                        if (!empty($stop['neighborhood_name'])) $stopNameParts[] = $stop['neighborhood_name'];
                                        if (!empty($stop['street_name'])) $stopNameParts[] = $stop['street_name'];
                                        $fullStopText = implode(' ← ', $stopNameParts);
                                        // Label: include Governorate + the most specific available location name
                                        $specificLabel = $stop['street_name'] ?: ($stop['neighborhood_name'] ?: ($stop['center_name'] ?: ($stop['city_name'] ?: $stop['governorate_name'])));
                                        if (!empty($stop['governorate_name']) && $specificLabel !== $stop['governorate_name']) {
                                            $stopLabel = $stop['governorate_name'] . ' - ' . $specificLabel;
                                        } else {
                                            $stopLabel = $specificLabel;
                                        }
                                    ?>
                                        <span class="badge bg-light text-dark border" data-bs-toggle="tooltip"
                                              title="<?php echo htmlspecialchars($fullStopText . ($stop['notes'] ? " ({$stop['notes']})" : "")); ?>">
                                            <?php echo htmlspecialchars($stop['stop_order'] . '. ' . $stopLabel); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td data-col="col_driver">
                            <?php foreach ($drivers as $d): ?>
                                <div class="mb-1">
                                    <span class="fw-semibold text-primary small"><?php echo htmlspecialchars($d['name']); ?></span>
                                    <?php if ($d['phones']): ?>
                                    <div class="text-muted small" dir="ltr"><i class="fas fa-phone text-secondary me-1" style="font-size:0.7rem;"></i> <?php echo htmlspecialchars($d['phones']); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($drivers)): ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td data-col="col_supervisors">
                            <?php foreach ($supervisors as $sv): ?>
                                <div class="mb-1">
                                    <span class="fw-semibold text-success small"><?php echo htmlspecialchars($sv['name']); ?></span>
                                    <?php if ($sv['phones']): ?>
                                    <div class="text-muted small" dir="ltr"><i class="fas fa-phone text-secondary me-1" style="font-size:0.7rem;"></i> <?php echo htmlspecialchars($sv['phones']); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($supervisors)): ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td data-col="col_students">
                            <?php 
                                $overCap = ($bus['capacity'] > 0 && (int)$bus['student_count'] > (int)$bus['capacity']);
                                $badgeStyle = $overCap 
                                    ? 'background-color:#fee2e2; color:#ef4444;' 
                                    : 'background-color:#ede9fe; color:#7c3aed;';
                                $capText = $bus['capacity'] > 0 ? ' / ' . $bus['capacity'] : '';
                            ?>
                            <span class="badge fw-bold" style="font-size:0.85rem; <?php echo $badgeStyle; ?>">
                                <?php echo htmlspecialchars($bus['student_count'] . $capText); ?> طالب
                            </span>
                        </td>
                        <td data-col="col_status">
                            <?php if ($bus['status'] === 'active'): ?>
                                <span class="badge bg-success-subtle text-success fw-bold" style="font-size: 0.85rem;"><i class="fas fa-check-circle me-1"></i>نشط</span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger fw-bold" style="font-size: 0.85rem;"><i class="fas fa-ban me-1"></i>معطل</span>
                            <?php endif; ?>
                        </td>
                        <td data-col="col_actions" class="actions-column">
                            <button type="button" class="btn btn-action-pills btn-edit edit-bus-btn me-1" data-id="<?php echo (int)$bus['id']; ?>" data-bs-toggle="tooltip" title="تعديل">
                                <i class="fas fa-edit"></i>
                            </button>
                            <?php if ($bus['status'] === 'active'): ?>
                                <button type="button" class="btn btn-action-pills btn-deactivate toggle-status-btn me-1" data-id="<?php echo (int)$bus['id']; ?>" data-number="<?php echo htmlspecialchars($bus['bus_number']); ?>" data-status="active" data-bs-toggle="tooltip" title="تعطيل">
                                    <i class="fas fa-ban"></i>
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-action-pills btn-activate toggle-status-btn me-1" data-id="<?php echo (int)$bus['id']; ?>" data-number="<?php echo htmlspecialchars($bus['bus_number']); ?>" data-status="inactive" data-bs-toggle="tooltip" title="تفعيل">
                                    <i class="fas fa-check"></i>
                                </button>
                            <?php endif; ?>
                            <a href="bus_report.php?bus_id=<?php echo (int)$bus['id']; ?>" class="btn btn-action-pills btn-view me-1" data-bs-toggle="tooltip" title="التقرير">
                                <i class="fas fa-eye"></i>
                            </a>
                            <button type="button" class="btn btn-action-pills btn-delete delete-bus-btn" data-id="<?php echo (int)$bus['id']; ?>" data-number="<?php echo htmlspecialchars($bus['bus_number']); ?>" data-bs-toggle="tooltip" title="حذف">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
</div>

<!-- Table Settings Modal -->
<div class="modal fade" id="busTableSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-view">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-cog me-2"></i>إعدادات أعمدة الجدول</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">اختر الأعمدة التي تريد عرضها في الجدول — يطبق التغيير فوراً:</p>
                <div class="row g-2">
                    <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="col_num" checked><label class="form-check-label" for="col_num">#</label></div></div>
                    <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="col_bus" checked><label class="form-check-label" for="col_bus">رقم الحافلة</label></div></div>
                    <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="col_route" checked><label class="form-check-label" for="col_route">خط السير</label></div></div>
                    <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="col_driver" checked><label class="form-check-label" for="col_driver">السائق</label></div></div>
                    <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="col_supervisors" checked><label class="form-check-label" for="col_supervisors">المشرفون</label></div></div>
                    <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="col_students" checked><label class="form-check-label" for="col_students">عدد الطلاب</label></div></div>
                    <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="col_status" checked><label class="form-check-label" for="col_status">الحالة</label></div></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-check me-1"></i>إغلاق</button>
            </div>
        </div>
    </div>
</div>

<!-- Over Capacity Modal -->
<div class="modal fade" id="overCapacityModal" tabindex="-1" aria-labelledby="overCapacityModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-warning">
            <div class="modal-header">
                <h5 class="modal-title" id="overCapacityModalLabel"><i class="fas fa-exclamation-triangle me-2"></i>حافلات تجاوزت السعة المقعدية</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if (empty($overCapacityBusesList)): ?>
                    <div class="alert alert-success text-center mb-0">
                        <i class="fas fa-check-circle me-2"></i>لا توجد أي حافلات متجاوزة للسعة المقعدية حالياً.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>رقم الحافلة</th>
                                    <th class="text-center">السعة المقعدية</th>
                                    <th class="text-center">الطلاب المسجلين</th>
                                    <th class="text-center">مقدار التجاوز</th>
                                    <th class="text-center">إجراء</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($overCapacityBusesList as $ob): 
                                    $excess = $ob['student_count'] - $ob['capacity'];
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($ob['bus_number']); ?></strong></td>
                                    <td class="text-center"><?php echo $ob['capacity']; ?></td>
                                    <td class="text-center text-danger fw-bold"><?php echo $ob['student_count']; ?></td>
                                    <td class="text-center"><span class="badge bg-danger">+<?php echo $excess; ?></span></td>
                                    <td class="text-center">
                                        <a href="student_buses.php?bus_id=<?php echo $ob['id']; ?>" class="btn btn-sm btn-outline-primary" title="إدارة طلاب الحافلة">
                                            <i class="fas fa-users-cog"></i> إدارة الطلاب
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<!-- ================= BUS ADD/EDIT MODAL ================= -->
<div class="modal fade" id="busModal" tabindex="-1" aria-labelledby="busModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit" id="busModalContent">
            <form method="POST" id="busForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="save_bus">
                <input type="hidden" name="bus_id" id="bus_id" value="">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="busModalLabel"><i class="fas fa-bus me-2"></i>إضافة حافلة جديدة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body bg-light-subtle">
                    <!-- Bootstrap Tab Navigation -->
                    <ul class="nav nav-tabs mb-3 fw-bold" id="busModalTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="general-tab" data-bs-toggle="tab" data-bs-target="#general-pane" type="button" role="tab" aria-controls="general-pane" aria-selected="true">
                                <i class="fas fa-info-circle me-1"></i>البيانات العامة
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="stops-tab" data-bs-toggle="tab" data-bs-target="#stops-pane" type="button" role="tab" aria-controls="stops-pane" aria-selected="false">
                                <i class="fas fa-map-marked-alt me-1"></i>خط السير والمحطات
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="staff-tab" data-bs-toggle="tab" data-bs-target="#staff-pane" type="button" role="tab" aria-controls="staff-pane" aria-selected="false">
                                <i class="fas fa-users-cog me-1"></i>طاقم الحافلة
                            </button>
                        </li>
                    </ul>
                    
                    <div class="tab-content" id="busModalTabsContent">
                        <!-- Tab 1: General Details -->
                        <div class="tab-pane fade show active" id="general-pane" role="tabpanel" aria-labelledby="general-tab" tabindex="0">
                            <div class="card shadow-sm border-0">
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">رقم الحافلة <span class="text-danger">*</span></label>
                                            <input type="text" name="bus_number" id="bus_number" class="form-control" required placeholder="مثال: أ ب ج 1234">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">السعة المقعدية (عدد الركاب)</label>
                                            <input type="number" name="capacity" id="bus_capacity" class="form-control" min="1" placeholder="السعة">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">الحالة</label>
                                            <select name="status" id="bus_status" class="form-select">
                                                <option value="active">نشط</option>
                                                <option value="inactive">معطل</option>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label fw-bold">ملاحظات عامة</label>
                                            <input type="text" name="bus_notes" id="bus_notes" class="form-control" placeholder="ملاحظات الحافلة...">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 2: Route Stops -->
                        <div class="tab-pane fade" id="stops-pane" role="tabpanel" aria-labelledby="stops-tab" tabindex="0">
                            <div class="card shadow-sm border-0">
                                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom-0">
                                    <h6 class="fw-bold mb-0 text-primary"><i class="fas fa-map-marked-alt me-2"></i>خط السير والمحطات الجغرافية <span class="text-muted small fw-normal">(جميع الحقول اختيارية)</span></h6>
                                    <button type="button" class="btn btn-sm btn-success px-3" id="addStopBtn">
                                        <i class="fas fa-plus-circle me-1"></i>إضافة محطة
                                    </button>
                                </div>
                                <div class="card-body pt-0">
                                    <div class="alert alert-info py-2 mb-3 small d-flex align-items-center">
                                        <i class="fas fa-info-circle me-2 fs-5"></i>
                                        <span>سيتم تحديد "المنطقة" تلقائياً في الجدول بناءً على أول محطة يتم تعبئة بياناتها الجغرافية.</span>
                                    </div>
                                    
                                    <div id="stopsContainer" class="d-flex flex-column gap-2">
                                        <!-- Dynamic Stops -->
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 3: Staff -->
                        <div class="tab-pane fade" id="staff-pane" role="tabpanel" aria-labelledby="staff-tab" tabindex="0">
                            <div class="card shadow-sm border-0">
                                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom-0">
                                    <h6 class="fw-bold mb-0 text-primary"><i class="fas fa-users-cog me-2"></i>طاقم الحافلة <span class="text-muted small fw-normal">(السائقون والمشرفون المسجلون)</span></h6>
                                    <button type="button" class="btn btn-sm btn-success px-3" id="addStaffBtn">
                                        <i class="fas fa-plus-circle me-1"></i>تعيين موظف
                                    </button>
                                </div>
                                <div class="card-body pt-0">
                                    <div id="staffContainer" class="d-flex flex-column gap-2">
                                        <!-- Dynamic Staff -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-primary" id="saveBusSubmitBtn">
                        <i class="fas fa-save me-1"></i>حفظ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ================= DELETE CONFIRMATION MODAL ================= -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <form method="POST" action="buses.php" class="admin-modal-actions">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="delete_bus">
                <input type="hidden" name="bus_id" id="delete_bus_id" value="">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash me-2"></i>حذف حافلة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-exclamation-triangle text-danger" style="font-size: 3rem;"></i>
                    </div>
                    <p class="text-center">هل أنت متأكد من حذف الحافلة رقم <span class="fw-bold text-primary" id="delete_bus_number"></span>؟</p>
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle me-2"></i>
                        سيؤدي هذا الإجراء لحذف الحافلة وكافة المحطات والطواقم المرتبطة بها نهائياً.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i>حذف الحافلة
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ================= TOGGLE STATUS CONFIRMATION MODAL ================= -->
<div class="modal fade" id="toggleStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-warning" id="toggleStatusModalContent">
            <form method="POST" action="buses.php" class="admin-modal-actions">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="bus_id" id="toggle_bus_id" value="">
                
                <div class="modal-header" id="toggleModalHeader">
                    <h5 class="modal-title" id="toggleModalTitle"><i class="fas fa-ban me-2"></i>تعطيل حافلة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-info-circle" id="toggleModalIcon" style="font-size: 3rem;"></i>
                    </div>
                    <p class="text-center" id="toggleModalText">هل أنت متأكد من تغيير حالة الحافلة؟</p>
                </div>
                <div class="modal-footer">
                    <!-- زر الإلغاء/الرجوع دائماً btn-danger (أحمر مصمت) للتنبيه -->
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <!-- زر التأكيد دائماً على اليمين (مصمت) -->
                    <button type="submit" class="btn" id="toggleModalSubmitBtn">
                        تأكيد
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ================= IMPORT BUSES MODAL ================= -->
<div class="modal fade" id="importBusesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-create">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-import me-2"></i>استيراد الحافلات</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>ارفع ملف Excel أو CSV لاستيراد بيانات الحافلات.</p>
                <form id="importBusesForm" method="POST" enctype="multipart/form-data" action="import_buses.php">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="mb-3">
                        <label for="busesFile" class="form-label fw-bold">اختر الملف</label>
                        <input type="file" class="form-control" id="busesFile" name="file" accept=".xlsx,.xls,.csv" required>
                    </div>
                    <div class="alert alert-info py-2 small mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        يجب أن يحتوي الملف على الأعمدة التالية أو مرادفاتها: 
                        <br><strong>رقم الحافلة</strong>، <strong>السعة</strong>، <strong>الحالة</strong>، <strong>الملاحظات</strong>.
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>إلغاء
                </button>
                <button type="submit" class="btn btn-success" form="importBusesForm">
                    <i class="fas fa-upload me-1"></i>استيراد
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ================= TEMPLATES FOR DYNAMIC FIELDS ================= -->
<!-- Stop Row Template -->
<script type="text/template" id="stop-row-template">
<div class="row g-2 p-2 rounded bg-light border-start border-primary border-3 stop-row align-items-center mb-2">
    <!-- المحافظة -->
    <div class="col-md">
        <select name="stop_governorate[]" class="form-select form-select-sm loc-governorate">
            <option value="">اختر المحافظة</option>
            <?php foreach($allGovs as $g): ?>
                <option value="<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <!-- المدينة -->
    <div class="col-md">
        <select name="stop_city[]" class="form-select form-select-sm loc-city">
            <option value="">اختر المدينة</option>
            <?php foreach($allCities as $c): ?>
                <option value="<?php echo $c['id']; ?>" data-parent="<?php echo $c['governorate_id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <!-- المركز -->
    <div class="col-md">
        <select name="stop_center[]" class="form-select form-select-sm loc-center">
            <option value="">اختر المركز</option>
            <?php foreach($allCenters as $c): ?>
                <option value="<?php echo $c['id']; ?>" data-parent="<?php echo $c['city_id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <!-- الحي -->
    <div class="col-md">
        <select name="stop_neighborhood[]" class="form-select form-select-sm loc-neighborhood">
            <option value="">اختر الحي</option>
            <?php foreach($allNeis as $n): ?>
                <option value="<?php echo $n['id']; ?>" data-parent="<?php echo $n['center_id']; ?>"><?php echo htmlspecialchars($n['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <!-- الشارع -->
    <div class="col-md">
        <select name="stop_street[]" class="form-select form-select-sm loc-street">
            <option value="">اختر الشارع</option>
            <?php foreach($allStreets as $s): ?>
                <option value="<?php echo $s['id']; ?>" data-parent="<?php echo $s['neighborhood_id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3 d-flex gap-2 align-items-center">
        <input type="text" name="stop_notes[]" class="form-control form-control-sm flex-grow-1" placeholder="ملاحظات المحطة" style="min-height: 31px;">
        <button type="button" class="btn btn-sm btn-danger remove-stop-btn px-3" title="حذف" style="min-height: 31px; display: inline-flex; align-items: center; justify-content: center;"><i class="fas fa-times"></i></button>
    </div>
</div>
</script>

<!-- Staff Row Template -->
<script type="text/template" id="staff-row-template">
<div class="row g-2 p-2 rounded bg-light border-start border-success border-3 staff-row align-items-center mb-2">
    <div class="col-md-5">
        <select name="staff_ids[]" class="form-select form-select-sm staff-select" style="min-height: 31px;">
            <option value="">اختر الموظف...</option>
            <?php foreach ($allAvailableStaff as $staff): ?>
                <option value="<?php echo $staff['id']; ?>" 
                        data-role="<?php echo htmlspecialchars($staff['role']); ?>"
                        data-phones="<?php echo htmlspecialchars($staff['phones'] ?? ''); ?>">
                    <?php echo htmlspecialchars($staff['name']) . ($staff['role'] === 'driver' ? ' (سائق)' : ' (مشرف)') . (!empty($staff['assigned_buses']) ? ' [معين للحافلات: ' . htmlspecialchars($staff['assigned_buses']) . ']' : ''); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <span class="badge bg-secondary-subtle text-secondary w-100 py-2 border staff-role-badge" style="font-size: 0.85rem; min-height: 31px; display: inline-flex; align-items: center; justify-content: center;"><i class="fas fa-id-card me-1"></i>الدور: —</span>
    </div>
    <div class="col-md-4 d-flex gap-2 align-items-center">
        <span class="badge bg-light text-dark flex-grow-1 py-2 border staff-phones-badge" style="font-size: 0.85rem; min-height: 31px; display: inline-flex; align-items: center; justify-content: center;"><i class="fas fa-phone me-1 text-secondary"></i>الهاتف: —</span>
        <button type="button" class="btn btn-sm btn-danger remove-staff-btn px-3" title="حذف" style="min-height: 31px; display: inline-flex; align-items: center; justify-content: center;"><i class="fas fa-times"></i></button>
    </div>
</div>
</script>

<!-- ================= JAVASCRIPT LOGIC ================= -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const stopsContainer = document.getElementById('stopsContainer');
    const staffContainer = document.getElementById('staffContainer');
    const busModal = new bootstrap.Modal(document.getElementById('busModal'));
    const deleteConfirmModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    const toggleStatusModal = new bootstrap.Modal(document.getElementById('toggleStatusModal'));

    // Add Stop Row
    function addStopRow() {
        const template = document.getElementById('stop-row-template').innerHTML;
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = template.trim();
        const row = tempDiv.firstElementChild;
        stopsContainer.appendChild(row);
        return row;
    }

    // Add Staff Row
    function addStaffRow() {
        const template = document.getElementById('staff-row-template').innerHTML;
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = template.trim();
        const row = tempDiv.firstElementChild;
        staffContainer.appendChild(row);
        return row;
    }

    // Populate Stop Dropdowns directly (Pre-loaded Options)
    function loadStopRowData(row, stopData) {
        row.querySelector('.loc-governorate').value = stopData.governorate_id || '';
        row.querySelector('.loc-city').value = stopData.city_id || '';
        row.querySelector('.loc-center').value = stopData.center_id || '';
        row.querySelector('.loc-neighborhood').value = stopData.neighborhood_id || '';
        row.querySelector('.loc-street').value = stopData.street_id || '';
        row.querySelector('input[name="stop_notes[]"]').value = stopData.notes || '';
        
        // Trigger change on city or lowest level to filter and auto-select parent items correctly
        if (stopData.street_id) {
            row.querySelector('.loc-street').dispatchEvent(new Event('change', { bubbles: true }));
        } else if (stopData.neighborhood_id) {
            row.querySelector('.loc-neighborhood').dispatchEvent(new Event('change', { bubbles: true }));
        } else if (stopData.center_id) {
            row.querySelector('.loc-center').dispatchEvent(new Event('change', { bubbles: true }));
        } else if (stopData.city_id) {
            row.querySelector('.loc-city').dispatchEvent(new Event('change', { bubbles: true }));
        } else if (stopData.governorate_id) {
            row.querySelector('.loc-governorate').dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    // Bidirectional Cascading Dropdowns Change Listener
    document.addEventListener('change', function(e) {
        const target = e.target;
        if (!target.classList.contains('form-select') || !target.closest('.stop-row')) return;
        
        const row = target.closest('.stop-row');
        const govSel  = row.querySelector('.loc-governorate');
        const citySel = row.querySelector('.loc-city');
        const cenSel  = row.querySelector('.loc-center');
        const neiSel  = row.querySelector('.loc-neighborhood');
        const strSel  = row.querySelector('.loc-street');
        
        const order = [govSel, citySel, cenSel, neiSel, strSel];
        const index = order.indexOf(target);
        if (index === -1) return;
        
        const val = target.value;
        
        // 1. UPWARD AUTO-SELECTION (If child is selected, select parent)
        if (val !== "") {
            for (let i = index; i > 0; i--) {
                const child = order[i];
                const parent = order[i - 1];
                const selectedOpt = child.options[child.selectedIndex];
                if (selectedOpt) {
                    const parentId = selectedOpt.getAttribute('data-parent');
                    if (parentId && parent.value !== parentId) {
                        parent.value = parentId;
                    }
                }
            }
        }
        
        // 2. DOWNWARD FILTERING (Filter options of subsequent child dropdowns)
        for (let i = 0; i < order.length - 1; i++) {
            const parent = order[i];
            const child = order[i + 1];
            const pVal = parent.value;
            
            let childMatch = false;
            for (let j = 0; j < child.options.length; j++) {
                const opt = child.options[j];
                const parentId = opt.getAttribute('data-parent');
                if (!opt.value) continue; // Skip placeholder
                
                if (!pVal || parentId === pVal) {
                    opt.style.display = '';
                    if (child.value === opt.value) childMatch = true;
                } else {
                    opt.style.display = 'none';
                }
            }
            // If parent changes and currently selected child doesn't match, clear it
            if (child.value !== "" && !childMatch && pVal) {
                child.value = "";
            }
        }
    });

    // Helper: Reset modal tabs to first tab when opening
    function resetModalTabs() {
        const firstTabEl = document.querySelector('#busModalTabs button[id="general-tab"]');
        if (firstTabEl) {
            const tabObj = bootstrap.Tab.getOrCreateInstance(firstTabEl);
            tabObj.show();
        }
    }

    // Dynamic Staff Badges Update
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('staff-select')) {
            const row = e.target.closest('.staff-row');
            const opt = e.target.options[e.target.selectedIndex];
            const roleBadge = row.querySelector('.staff-role-badge');
            const phonesBadge = row.querySelector('.staff-phones-badge');
            
            if (opt && opt.value) {
                const role = opt.dataset.role === 'driver' ? 'سائق' : 'مشرف';
                const phones = opt.dataset.phones || 'لا يوجد هاتف';
                
                roleBadge.className = opt.dataset.role === 'driver' ? 'badge bg-primary-subtle text-primary w-100 py-2 border staff-role-badge' : 'badge bg-success-subtle text-success w-100 py-2 border staff-role-badge';
                roleBadge.innerHTML = '<i class="fas fa-id-card me-1"></i>الدور: ' + role;
                phonesBadge.innerHTML = '<i class="fas fa-phone me-1 text-secondary"></i>الهاتف: ' + phones;
            } else {
                roleBadge.className = 'badge bg-secondary-subtle text-secondary w-100 py-2 border staff-role-badge';
                roleBadge.innerHTML = '<i class="fas fa-id-card me-1"></i>الدور: —';
                phonesBadge.innerHTML = '<i class="fas fa-phone me-1 text-secondary"></i>الهاتف: —';
            }
        }
    });

    // Update disabled state of remove buttons
    function updateRemoveButtonsState() {
        const stopRows = stopsContainer.querySelectorAll('.stop-row');
        stopRows.forEach(row => {
            const btn = row.querySelector('.remove-stop-btn');
            if (btn) {
                btn.disabled = (stopRows.length <= 1);
            }
        });

        const staffRows = staffContainer.querySelectorAll('.staff-row');
        staffRows.forEach(row => {
            const btn = row.querySelector('.remove-staff-btn');
            if (btn) {
                btn.disabled = (staffRows.length <= 1);
            }
        });
    }

    // Remove buttons actions
    document.addEventListener('click', function(e) {
        if (e.target.closest('.remove-stop-btn')) {
            const row = e.target.closest('.stop-row');
            if (row) {
                row.remove();
                updateRemoveButtonsState();
            }
        }
        else if (e.target.closest('.remove-staff-btn')) {
            const row = e.target.closest('.staff-row');
            if (row) {
                row.remove();
                updateRemoveButtonsState();
            }
        }
    });

    // Add Stop Button Event
    document.getElementById('addStopBtn').addEventListener('click', function() {
        addStopRow();
        updateRemoveButtonsState();
    });

    // Add Staff Button Event
    document.getElementById('addStaffBtn').addEventListener('click', function() {
        addStaffRow();
        updateRemoveButtonsState();
    });

    // Form submission listener to enable disabled selects
    document.getElementById('busForm').addEventListener('submit', function(e) {
        this.querySelectorAll('select:disabled').forEach(select => {
            select.disabled = false;
        });
    });

    // Open Add Bus Modal
    document.getElementById('openAddBusModalBtn').addEventListener('click', function() {
        document.getElementById('busForm').reset();
        document.getElementById('bus_id').value = '';
        document.getElementById('busModalContent').classList.remove('admin-modal-edit');
        document.getElementById('busModalContent').classList.add('admin-modal-create');
        document.getElementById('busModalLabel').innerHTML = '<i class="fas fa-plus-circle me-2"></i>إضافة حافلة جديدة';
        
        const submitBtn = document.getElementById('saveBusSubmitBtn');
        submitBtn.className = 'btn btn-success';
        submitBtn.innerHTML = '<i class="fas fa-check-circle me-1"></i>حفظ';
        
        stopsContainer.innerHTML = '';
        staffContainer.innerHTML = '';
        
        // Add default rows
        addStopRow();
        addStaffRow();
        
        updateRemoveButtonsState();
        
        resetModalTabs();
        
        busModal.show();
    });

    // Edit Bus - Load details via AJAX
    document.querySelectorAll('.edit-bus-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const busId = this.dataset.id;
            document.getElementById('busModalContent').classList.remove('admin-modal-create');
            document.getElementById('busModalContent').classList.add('admin-modal-edit');
            
            // Set Loading state
            stopsContainer.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted">جاري تحميل مسار خط السير...</p></div>';
            staffContainer.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted">جاري تحميل طاقم الحافلة...</p></div>';
            
            resetModalTabs();
            
            busModal.show();
            
            try {
                const response = await fetch(`buses.php?ajax=get_bus&id=${busId}`);
                const data = await response.json();
                
                if (!data.success) {
                    alert(data.message);
                    busModal.hide();
                    return;
                }
                
                // Set main details
                document.getElementById('bus_id').value = data.bus.id;
                document.getElementById('bus_number').value = data.bus.bus_number;
                document.getElementById('bus_capacity').value = data.bus.capacity || '';
                document.getElementById('bus_status').value = data.bus.status || 'active';
                document.getElementById('bus_notes').value = data.bus.notes || '';
                
                document.getElementById('busModalLabel').innerHTML = `<i class="fas fa-edit me-2"></i>تعديل حافلة: ${data.bus.bus_number}`;
                
                const submitBtn = document.getElementById('saveBusSubmitBtn');
                submitBtn.className = 'btn btn-primary';
                submitBtn.innerHTML = '<i class="fas fa-save me-1"></i>حفظ التعديلات';
                
                // Load Stops
                stopsContainer.innerHTML = '';
                if (data.stops && data.stops.length > 0) {
                    // Populate stops in parallel
                    const promises = data.stops.map(async (stopData) => {
                        const row = addStopRow();
                        await loadStopRowData(row, stopData);
                    });
                    await Promise.all(promises);
                } else {
                    addStopRow();
                }
                
                // Load Staff
                staffContainer.innerHTML = '';
                if (data.staff && data.staff.length > 0) {
                    data.staff.forEach(st => {
                        const row = addStaffRow();
                        const select = row.querySelector('.staff-select');
                        select.value = st.id;
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                } else {
                    addStaffRow();
                }
                
                updateRemoveButtonsState();
                
            } catch (e) {
                console.error(e);
                alert('حدث خطأ أثناء تحميل بيانات الحافلة.');
                busModal.hide();
            }
        });
    });

    // Delete Button Event (Trigger Confirmation Modal)
    document.querySelectorAll('.delete-bus-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const busId = this.dataset.id;
            const busNum = this.dataset.number;
            
            document.getElementById('delete_bus_id').value = busId;
            document.getElementById('delete_bus_number').textContent = busNum;
            
            deleteConfirmModal.show();
        });
    });

    // Toggle Status Event (Trigger Confirmation Modal)
    document.querySelectorAll('.toggle-status-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const busId = this.dataset.id;
            const busNum = this.dataset.number;
            const currentStatus = this.dataset.status;
            
            document.getElementById('toggle_bus_id').value = busId;
            
            const modalContent = document.getElementById('toggleStatusModalContent');
            const title = document.getElementById('toggleModalTitle');
            const icon = document.getElementById('toggleModalIcon');
            const text = document.getElementById('toggleModalText');
            const submitBtn = document.getElementById('toggleModalSubmitBtn');
            
            if (currentStatus === 'active') {
                modalContent.classList.remove('admin-modal-create');
                modalContent.classList.add('admin-modal-warning');
                title.innerHTML = '<i class="fas fa-ban me-2"></i>تعطيل حافلة';
                icon.className = 'fas fa-ban text-warning admin-modal-icon-lg';
                text.innerHTML = `هل أنت متأكد من تعطيل الحافلة رقم <span class="fw-bold text-primary">${busNum}</span>؟`;
                submitBtn.className = 'btn btn-warning';
                submitBtn.innerHTML = '<i class="fas fa-ban me-1"></i>تعطيل';
            } else {
                modalContent.classList.remove('admin-modal-warning');
                modalContent.classList.add('admin-modal-create');
                title.innerHTML = '<i class="fas fa-check me-2"></i>تفعيل حافلة';
                icon.className = 'fas fa-check-circle text-success admin-modal-icon-lg';
                text.innerHTML = `هل أنت متأكد من تفعيل الحافلة رقم <span class="fw-bold text-primary">${busNum}</span>؟`;
                submitBtn.className = 'btn btn-success';
                submitBtn.innerHTML = '<i class="fas fa-check me-1"></i>تفعيل';
            }
            
            toggleStatusModal.show();
        });
    });

    // ======= Initialize Tooltips =======
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // ======= Table Column Settings =======
    (function() {
        var STORAGE_KEY = 'buses_table_columns';
        var checkboxes = document.querySelectorAll('#busTableSettingsModal .form-check-input');

        // Load saved state
        var saved = {};
        try { saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); } catch(e) {}

        function applyCol(colId, visible) {
            var th = document.querySelector('#busesTable thead th[data-col="' + colId + '"]');
            var tds = document.querySelectorAll('#busesTable tbody td[data-col="' + colId + '"]');
            if (th) th.style.display = visible ? '' : 'none';
            tds.forEach(function(td) { td.style.display = visible ? '' : 'none'; });
        }

        checkboxes.forEach(function(cb) {
            var colId = cb.id;
            // Apply saved state (default: true/checked)
            var isVisible = saved.hasOwnProperty(colId) ? saved[colId] : true;
            cb.checked = isVisible;
            applyCol(colId, isVisible);

            // Immediate apply on change
            cb.addEventListener('change', function() {
                applyCol(colId, this.checked);
                saved[colId] = this.checked;
                localStorage.setItem(STORAGE_KEY, JSON.stringify(saved));
            });
        });
    })();
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>
