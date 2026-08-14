<?php

declare(strict_types=1);

$staffShiftCompatibilityMode = defined('STAFF_SHIFTS_COMPATIBILITY_MODE');
$compatibilityFormAction = 'staff_shifts.php';

if (!$staffShiftCompatibilityMode) {
    $page_title = 'سياسات الدوام والتقويم';
    $custom_page_title = true;

    require_once '../config/database.php';
    require_once '../classes/utilities.php';
    require_once '../includes/session_config.php';
    require_once '../includes/csrf.php';
    require_once '../vendor/autoload.php';
    require_once '../src/Modules/Operations/Audit/AuditService.php';
    Utilities::validateSession('admin');
    requireCsrfPost();

    $database = new Database();
    $db = $database->getConnection();
    $actorId = (int) ($_SESSION['user_id'] ?? 0);
    $success_message = $_SESSION['success_message'] ?? null;
    $error_message = $_SESSION['error_message'] ?? null;
    $oldPolicyInput = is_array($_SESSION['hr_policy_calendar_old'] ?? null)
        ? $_SESSION['hr_policy_calendar_old']
        : [];
    unset($_SESSION['success_message'], $_SESSION['error_message'], $_SESSION['hr_policy_calendar_old']);

    $policyCommand = null;
    $policyAdminQuery = null;
    $policyImpact = null;
    $effectiveScheduleQuery = null;
    $effectiveResolution = null;
    $scopeOptionQuery = null;
    $scopeOptions = ['org_unit' => [], 'job_title' => [], 'group' => [], 'staff' => []];
    $legacyShiftSnapshot = [];
    $servicesReady = false;
    try {
        $factoryClass = \EduCore\Modules\Attendance\Infrastructure\AttendanceModuleFactory::class;
        if (!class_exists($factoryClass)) {
            throw new RuntimeException('Attendance policy module factory is unavailable.');
        }
        $moduleFactory = new $factoryClass(
            $db,
            new \EduCore\Modules\Operations\Audit\AuditService($db)
        );
        $policyCommand = $moduleFactory->schedulePolicyCommand();
        $policyAdminQuery = $moduleFactory->schedulePolicyAdminQuery();
        $policyImpact = $moduleFactory->schedulePolicyImpact();
        $effectiveScheduleQuery = $moduleFactory->effectiveSchedule();
        $scopeOptionQuery = $moduleFactory->scheduleScopeOptions();
        $legacyShiftSnapshot = $moduleFactory->legacyStaffShiftCompatibility()->viewData();
        $servicesReady = true;
    } catch (Throwable $exception) {
        $reference = 'HRP-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        error_log($reference . ' schedule policy initialization error: ' . $exception->getMessage());
        $error_message = $error_message
            ?: 'لم تكتمل تهيئة سياسات الدوام بعد. طبّق تحديثات قاعدة البيانات ثم أعد المحاولة. مرجع المتابعة: ' . $reference;
    }

    $normalizePolicyPayload = static function (array $input): array {
        $code = strtoupper(trim((string) ($input['policy_code'] ?? '')));
        $name = trim((string) ($input['policy_name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $validFrom = trim((string) ($input['valid_from'] ?? ''));
        $validTo = trim((string) ($input['valid_to'] ?? ''));
        $timezone = trim((string) ($input['timezone'] ?? 'Africa/Cairo'));
        $scopeType = (string) ($input['scope_type'] ?? 'global');
        $scopeId = (int) ($input['scope_id'] ?? 0);
        $priority = (int) ($input['priority'] ?? 0);
        $seasonStart = trim((string) ($input['season_start_mmdd'] ?? ''));
        $seasonEnd = trim((string) ($input['season_end_mmdd'] ?? ''));
        $editVersionId = (int) ($input['edit_version_id'] ?? 0);
        $existingPolicyId = (int) ($input['existing_policy_id'] ?? 0);
        $supersedesVersionId = (int) ($input['supersedes_version_id'] ?? 0);
        if ($code === '' || preg_match('/^[A-Z0-9_-]{3,50}$/', $code) !== 1) {
            throw new InvalidArgumentException('أدخل كود سياسة من 3 إلى 50 حرفًا باستخدام الحروف اللاتينية والأرقام والشرطة فقط.');
        }
        if ($name === '' || mb_strlen($name, 'UTF-8') > 200) {
            throw new InvalidArgumentException('اسم السياسة مطلوب ويجب ألا يتجاوز 200 حرف.');
        }
        if (mb_strlen($description, 'UTF-8') > 1000) {
            throw new InvalidArgumentException('وصف السياسة يجب ألا يتجاوز 1000 حرف.');
        }
        $fromDate = DateTimeImmutable::createFromFormat('!Y-m-d', $validFrom);
        $toDate = $validTo === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $validTo);
        if ($fromDate === false) {
            throw new InvalidArgumentException('اختر تاريخ بداية سريان صحيحًا.');
        }
        if ($validTo !== '' && $toDate === false) {
            throw new InvalidArgumentException('تاريخ نهاية السريان غير صحيح.');
        }
        if ($toDate instanceof DateTimeImmutable && $toDate < $fromDate) {
            throw new InvalidArgumentException('نهاية السريان لا يمكن أن تسبق تاريخ البداية.');
        }
        $validToExclusive = $toDate instanceof DateTimeImmutable
            ? $toDate->modify('+1 day')->format('Y-m-d')
            : null;
        if (!in_array($scopeType, ['global', 'org_unit', 'job_title', 'group', 'staff'], true)) {
            throw new InvalidArgumentException('اختر نطاقًا صحيحًا للسياسة.');
        }
        if ($scopeType !== 'global' && ($scopeId <= 0 || $scopeId > 2147483647)) {
            throw new InvalidArgumentException('حدد معرف الجهة أو المجموعة أو العامل الذي ستطبق عليه السياسة.');
        }
        if ($priority < 0 || $priority > 65535) {
            throw new InvalidArgumentException('أولوية السياسة يجب أن تكون بين صفر و65535.');
        }
        if ($editVersionId > 0 && $existingPolicyId > 0) {
            throw new InvalidArgumentException('لا يمكن خلط تعديل المسودة مع إنشاء نسخة جديدة. أعد فتح المسودة من القائمة.');
        }
        if ($editVersionId <= 0 && (($existingPolicyId > 0) !== ($supersedesVersionId > 0))) {
            throw new InvalidArgumentException('بيانات إنشاء النسخة الجديدة غير مكتملة. أعد فتح السياسة من زر «إنشاء نسخة جديدة».');
        }
        $validMonthDay = static function (string $value): bool {
            if (preg_match('/^(0[1-9]|1[0-2])-([0-2][0-9]|3[01])$/', $value) !== 1) {
                return false;
            }
            [$month, $day] = array_map('intval', explode('-', $value));
            return checkdate($month, $day, 2000);
        };
        if (($seasonStart === '') !== ($seasonEnd === '')) {
            throw new InvalidArgumentException('أدخل بداية الموسم ونهايته معًا أو اتركهما معًا.');
        }
        if ($seasonStart !== '' && (!$validMonthDay($seasonStart) || !$validMonthDay($seasonEnd))) {
            throw new InvalidArgumentException('اكتب الموسم بصيغة شهر-يوم مثل 09-01.');
        }

        $days = [];
        $workingDayCount = 0;
        $postedDays = is_array($input['days'] ?? null) ? $input['days'] : [];
        foreach ([1, 2, 3, 4, 5, 6, 7] as $weekday) {
            $day = is_array($postedDays[$weekday] ?? null) ? $postedDays[$weekday] : [];
            $working = isset($day['is_working_day']);
            $lateGrace = (int) ($day['late_grace_minutes'] ?? 15);
            $earlyGrace = (int) ($day['early_grace_minutes'] ?? 0);
            $entryBefore = (int) ($day['entry_window_before_minutes'] ?? 120);
            $entryAfter = (int) ($day['entry_window_after_minutes'] ?? 180);
            $exitBefore = (int) ($day['exit_window_before_minutes'] ?? 180);
            $exitAfter = (int) ($day['exit_window_after_minutes'] ?? 120);
            foreach ([$lateGrace, $earlyGrace] as $graceValue) {
                if ($graceValue < 0 || $graceValue > 240) {
                    throw new InvalidArgumentException('سماح الحضور والانصراف يجب أن يكون بين صفر و240 دقيقة.');
                }
            }
            foreach ([$entryBefore, $entryAfter, $exitBefore, $exitAfter] as $windowValue) {
                if ($windowValue < 0 || $windowValue > 1440) {
                    throw new InvalidArgumentException('كل نافذة التقاط بصمة يجب أن تكون بين صفر و1440 دقيقة.');
                }
            }

            $segments = [];
            $requiredMinutes = 0;
            $rawSegments = is_array($day['segments'] ?? null) ? $day['segments'] : [];
            if (count($rawSegments) > 20) {
                throw new InvalidArgumentException('الحد الأقصى 20 مقطع دوام لليوم الواحد.');
            }
            if ($working) {
                $workingDayCount++;
                foreach ($rawSegments as $index => $rawSegment) {
                    if (!is_array($rawSegment)) {
                        continue;
                    }
                    $start = trim((string) ($rawSegment['start_time'] ?? ''));
                    $end = trim((string) ($rawSegment['end_time'] ?? ''));
                    if ($start === '' && $end === '') {
                        continue;
                    }
                    $type = (string) ($rawSegment['segment_type'] ?? 'work');
                    $startOffset = (int) ($rawSegment['start_day_offset'] ?? 0);
                    $endOffset = (int) ($rawSegment['end_day_offset'] ?? $startOffset);
                    if (!in_array($type, ['work', 'paid_break', 'unpaid_break', 'on_call', 'overtime'], true)) {
                        throw new InvalidArgumentException('اختر نوعًا صحيحًا لكل مقطع دوام.');
                    }
                    if (preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $start) !== 1 || preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $end) !== 1) {
                        throw new InvalidArgumentException('راجع وقت البداية والنهاية في مقاطع أيام العمل.');
                    }
                    if ($startOffset < 0 || $startOffset > 2 || $endOffset < $startOffset || $endOffset > 2) {
                        throw new InvalidArgumentException('إزاحة يوم المقطع غير صحيحة. استخدم اليوم نفسه أو يومًا من اليومين التاليين.');
                    }
                    [$startHour, $startMinute] = array_map('intval', explode(':', $start));
                    [$endHour, $endMinute] = array_map('intval', explode(':', $end));
                    $duration = ($endOffset * 1440 + $endHour * 60 + $endMinute)
                        - ($startOffset * 1440 + $startHour * 60 + $startMinute);
                    if ($duration <= 0 || $duration > 2880) {
                        throw new InvalidArgumentException('نهاية كل مقطع يجب أن تأتي بعد بدايته.');
                    }
                    $countsRequired = isset($rawSegment['counts_required_minutes']);
                    if ($countsRequired) {
                        $requiredMinutes += $duration;
                    }
                    $segments[] = [
                        'sequence_no' => count($segments) + 1,
                        'segment_type' => $type,
                        'start_time' => $start,
                        'end_time' => $end,
                        'start_day_offset' => $startOffset,
                        'end_day_offset' => $endOffset,
                        'counts_required_minutes' => $countsRequired,
                    ];
                }
                if ($segments === []) {
                    throw new InvalidArgumentException('أضف مقطع عمل واحدًا على الأقل لكل يوم عمل.');
                }
            }
            $firstSegment = $segments[0] ?? null;
            $lastSegment = $segments === [] ? null : $segments[count($segments) - 1];
            $days[] = [
                'weekday' => $weekday,
                'is_working_day' => $working,
                'start_time' => $firstSegment['start_time'] ?? null,
                'end_time' => $lastSegment['end_time'] ?? null,
                'end_day_offset' => (int) ($lastSegment['end_day_offset'] ?? 0),
                'required_minutes' => $working ? $requiredMinutes : 0,
                'late_grace_minutes' => $lateGrace,
                'early_grace_minutes' => $earlyGrace,
                'entry_window_before_minutes' => $entryBefore,
                'entry_window_after_minutes' => $entryAfter,
                'exit_window_before_minutes' => $exitBefore,
                'exit_window_after_minutes' => $exitAfter,
                'segments' => $segments,
            ];
        }
        if ($workingDayCount === 0) {
            throw new InvalidArgumentException('حدد يوم عمل واحدًا على الأقل في السياسة.');
        }

        $payload = [
            'policy' => [
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'status' => 'active',
            ],
            'version' => [
                'valid_from' => $validFrom,
                'valid_to' => $validToExclusive,
                'timezone' => $timezone !== '' ? $timezone : 'Africa/Cairo',
                'rounding_rule' => (string) ($input['rounding_rule'] ?? 'none'),
                'season_start_mmdd' => $seasonStart !== '' ? $seasonStart : null,
                'season_end_mmdd' => $seasonEnd !== '' ? $seasonEnd : null,
            ],
            'days' => $days,
            'scopes' => [[
                'scope_type' => $scopeType,
                'scope_id' => $scopeType === 'global' ? null : $scopeId,
                'priority' => $priority,
                'valid_from' => $validFrom,
                'valid_to' => $validToExclusive,
            ]],
        ];

        return \EduCore\Modules\Attendance\Presentation\SchedulePolicyAdminRequestMapper::attachVersionLineage(
            $payload,
            $existingPolicyId,
            $supersedesVersionId
        );
    };

    $safeErrorMessage = static function (Throwable $exception, string $reference): string {
        $code = strtoupper($exception->getMessage());
        $scheduleMessages = [
            'SCHEDULE_TIMEZONE_INVALID' => 'المنطقة الزمنية غير صحيحة.',
            'SCHEDULE_SEASON_RANGE_INCOMPLETE' => 'أدخل بداية الموسم ونهايته معًا.',
            'SCHEDULE_SEASON_DATE_INVALID' => 'تاريخ الموسم غير صحيح؛ استخدم صيغة شهر-يوم.',
            'SCHEDULE_WEEKDAY_DUPLICATE' => 'يوجد يوم مكرر داخل السياسة.',
            'SCHEDULE_WINDOW_INVALID' => 'نهاية الدوام يجب أن تأتي بعد البداية أو تُحدد في اليوم التالي.',
            'SCHEDULE_SEGMENT_BOUNDARY_MISMATCH' => 'حدود المقاطع لا تطابق بداية الدوام ونهايته.',
            'SCHEDULE_REQUIRED_MINUTES_MISMATCH' => 'الدقائق المطلوبة لا تطابق مجموع مقاطع العمل المحتسبة.',
            'SCHEDULE_SEGMENT_OVERLAP' => 'توجد مقاطع دوام متداخلة؛ عدّل أوقاتها قبل الحفظ.',
            'SCHEDULE_SEGMENT_WINDOW_INVALID' => 'نهاية أحد المقاطع لا تأتي بعد بدايته.',
            'SCHEDULE_SEGMENT_TYPE_INVALID' => 'نوع أحد مقاطع الدوام غير صحيح.',
            'SCHEDULE_ENTRY_WINDOW_INVALID' => 'نافذة التقاط بصمة الدخول غير صحيحة.',
            'SCHEDULE_EXIT_WINDOW_INVALID' => 'نافذة التقاط بصمة الخروج غير صحيحة.',
            'SCHEDULE_LATE_GRACE_INVALID' => 'فترة سماح الحضور غير صحيحة.',
            'SCHEDULE_EARLY_GRACE_INVALID' => 'فترة سماح الانصراف غير صحيحة.',
            'SCHEDULE_SCOPE_INVALID' => 'نطاق السياسة غير صحيح أو غير متاح.',
            'SCHEDULE_POLICY_OVERLAP' => 'تتداخل هذه السياسة مع سياسة أخرى في النطاق والفترة نفسيهما.',
            'SCHEDULE_POLICY_CODE_INVALID' => 'كود السياسة غير صحيح؛ استخدم حروفًا لاتينية وأرقامًا وشرطة فقط.',
            'SCHEDULE_POLICY_CODE_EXISTS' => 'كود السياسة مستخدم بالفعل لسياسة أخرى؛ اختر كودًا مختلفًا.',
            'SCHEDULE_POLICY_NAME_INVALID' => 'اسم السياسة مطلوب ويجب ألا يتجاوز 200 حرف.',
            'SCHEDULE_POLICY_DESCRIPTION_INVALID' => 'وصف السياسة يجب ألا يتجاوز 1000 حرف.',
            'SCHEDULE_VALID_FROM_INVALID' => 'تاريخ بداية سريان السياسة غير صحيح.',
            'SCHEDULE_VALID_TO_INVALID' => 'تاريخ نهاية سريان السياسة غير صحيح.',
            'SCHEDULE_EFFECTIVE_RANGE_INVALID' => 'فترة سريان السياسة غير صحيحة؛ يجب أن تأتي النهاية بعد البداية.',
            'SCHEDULE_DAYS_REQUIRED' => 'أضف أيام الدوام قبل حفظ السياسة.',
            'SCHEDULE_SCOPES_REQUIRED' => 'حدد نطاق تطبيق واحدًا على الأقل للسياسة.',
            'SCHEDULE_SUPERSEDES_REQUIRED' => 'حدد النسخة المنشورة السابقة عند إنشاء نسخة جديدة.',
            'SCHEDULE_SUPERSEDES_INVALID' => 'النسخة السابقة لا تنتمي إلى السياسة نفسها أو لم تعد صالحة للنسخ.',
            'SCHEDULE_SUCCESSOR_RANGE_INVALID' => 'يجب أن يبدأ سريان النسخة الجديدة بعد بداية النسخة المنشورة السابقة.',
            'SCHEDULE_VERSION_STALE' => 'حفظ مستخدم آخر تعديلات على هذه المسودة. حدّث الصفحة وراجع النسخة الجديدة قبل الحفظ.',
            'SCHEDULE_VERSION_IMMUTABLE' => 'لا يمكن تعديل النسخة بعد نشرها؛ أنشئ منها نسخة جديدة.',
            'SCHEDULE_VERSION_NOT_FOUND' => 'مسودة السياسة المطلوب تعديلها غير موجودة.',
            'SCHEDULE_LOCK_VERSION_INVALID' => 'بيانات قفل المسودة غير صحيحة. أعد فتح المسودة من القائمة.',
            'SCHEDULE_PUBLICATION_CONFLICT' => 'تتعارض هذه السياسة عند النشر مع سياسة منشورة أخرى في النطاق والفترة نفسيهما.',
            'CALENDAR_EXCEPTION_DATE_INVALID' => 'اختر تاريخًا صحيحًا لاستثناء التقويم.',
            'CALENDAR_EXCEPTION_SCOPE_INVALID' => 'حدد نطاقًا صحيحًا لاستثناء التقويم.',
            'CALENDAR_EXCEPTION_PRIORITY_INVALID' => 'أولوية استثناء التقويم يجب أن تكون بين صفر و65535.',
            'CALENDAR_EXCEPTION_TYPE_INVALID' => 'اختر نوع استثناء تقويم صحيحًا.',
            'CALENDAR_EXCEPTION_STATUS_INVALID' => 'حالة استثناء التقويم غير صحيحة.',
            'CALENDAR_EXCEPTION_OVERRIDE_INVALID' => 'تفاصيل سياسة الدوام البديلة في الاستثناء غير صحيحة.',
            'CALENDAR_EXCEPTION_OVERRIDE_REQUIRED' => 'اختر سياسة الدوام البديلة لهذا النوع من الاستثناءات.',
            'CALENDAR_EXCEPTION_REASON_INVALID' => 'اكتب سببًا واضحًا لاستثناء التقويم لا يتجاوز 1000 حرف.',
            'CALENDAR_EXCEPTION_NOT_FOUND' => 'استثناء التقويم المطلوب غير موجود.',
            'CALENDAR_EXCEPTION_STALE' => 'تغير استثناء التقويم أثناء العمل عليه. حدّث الصفحة ثم أعد المحاولة.',
            'CALENDAR_EXCEPTION_NOT_ACTIVE' => 'لا يمكن إيقاف هذا الاستثناء لأنه لم يعد فعالًا. حدّث الصفحة ثم أعد المحاولة.',
            'CALENDAR_EXCEPTION_REQUIRES_SUPERSESSION' => 'يوجد استثناء تقويم فعال لهذا التاريخ والنطاق؛ أنشئ استثناءً بديلًا ليحفظ السجل السابق.',
            'CALENDAR_SCHEDULE_VERSION_NOT_PUBLISHED' => 'سياسة الدوام البديلة المختارة غير منشورة؛ انشرها أولًا أو اختر نسخة منشورة.',
            'CALENDAR_SUPERSESSION_SCOPE_MISMATCH' => 'لا يمكن استبدال استثناء تقويم بنطاق مختلف عن الاستثناء الأصلي.',
            'CALENDAR_SUPERSESSION_INVALID' => 'تعذر ربط الاستثناء البديل بالسجل الحالي. حدّث الصفحة ثم أعد المحاولة.',
            'CALENDAR_OVERRIDE_INVALID' => 'بيانات تجاوز التقويم غير صحيحة.',
            'IDEMPOTENCY_CONFLICT' => 'أُعيد إرسال الطلب ببيانات مختلفة. حدّث الصفحة ثم حاول مرة أخرى.',
        ];
        foreach ($scheduleMessages as $domainCode => $message) {
            if (str_contains($code, $domainCode)) {
                return $message;
            }
        }
        if (str_contains($code, 'OVERLAP') || str_contains($code, 'AMBIGUOUS')) {
            return 'تتعارض السياسة مع سياسة أخرى في النطاق والفترة نفسيهما. غيّر النطاق أو الأولوية أو فترة السريان.';
        }
        if (str_contains($code, 'LOCK') || str_contains($code, 'VERSION')) {
            return 'تغيرت السياسة أثناء العمل عليها. حدّث الصفحة ثم أعد المحاولة.';
        }
        if ($exception instanceof InvalidArgumentException
            && preg_match('/[\x{0600}-\x{06FF}]/u', $exception->getMessage()) === 1) {
            return $exception->getMessage();
        }
        return 'تعذر تنفيذ العملية الآن. راجع البيانات وحاول مرة أخرى. مرجع المتابعة: ' . $reference;
    };

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if (!$servicesReady || $policyCommand === null) {
                throw new RuntimeException('Schedule policy command service is unavailable.');
            }
            $idempotencyKey = trim((string) ($_POST['idempotency_key'] ?? ''));
            if (preg_match('/^[a-f0-9]{32,64}$/', $idempotencyKey) !== 1) {
                throw new InvalidArgumentException('انتهت صلاحية النموذج. حدّث الصفحة ثم أعد المحاولة.');
            }

            if (isset($_POST['save_schedule_policy_draft'])) {
                $payload = $normalizePolicyPayload($_POST);
                $policyScope = is_array($payload['scopes'][0] ?? null) ? $payload['scopes'][0] : [];
                $policyScopeDate = new DateTimeImmutable((string) ($payload['version']['valid_from'] ?? ''));
                if ($scopeOptionQuery === null || !$scopeOptionQuery->isSelectable(
                    (string) ($policyScope['scope_type'] ?? ''),
                    isset($policyScope['scope_id']) ? (int) $policyScope['scope_id'] : null,
                    $policyScopeDate
                )) {
                    throw new InvalidArgumentException('النطاق المختار غير موجود أو غير نشط في تاريخ بداية السياسة.');
                }
                $editVersionId = (int) ($_POST['edit_version_id'] ?? 0);
                $expectedLockVersion = (int) ($_POST['expected_lock_version'] ?? 0);
                if ($editVersionId > 0) {
                    if ($expectedLockVersion <= 0
                        || (int) ($_POST['existing_policy_id'] ?? 0) > 0) {
                        throw new InvalidArgumentException('بيانات تعديل المسودة غير مكتملة أو متعارضة مع إنشاء نسخة جديدة. أعد فتح المسودة.');
                    }
                    $policyCommand->updateDraft(
                        $editVersionId,
                        $actorId,
                        $payload,
                        $expectedLockVersion,
                        $idempotencyKey
                    );
                    $_SESSION['success_message'] = 'تم حفظ تعديلات مسودة سياسة الدوام.';
                } else {
                    $policyCommand->createDraft($actorId, $payload, $idempotencyKey);
                    $_SESSION['success_message'] = 'تم حفظ مسودة سياسة الدوام. راجع أثرها قبل النشر.';
                }
            } elseif (isset($_POST['publish_schedule_policy'])) {
                $versionId = (int) ($_POST['version_id'] ?? 0);
                if ($versionId <= 0) {
                    throw new InvalidArgumentException('تعذر تحديد نسخة السياسة المطلوب نشرها.');
                }
                $policyCommand->publish(
                    $versionId,
                    $actorId,
                    new DateTimeImmutable('now', new DateTimeZone('Africa/Cairo')),
                    $idempotencyKey
                );
                $_SESSION['success_message'] = 'تم نشر السياسة وأصبحت جاهزة للتطبيق في فترة سريانها.';
            } elseif (isset($_POST['save_calendar_exception'])) {
                if (!method_exists($policyCommand, 'saveCalendarException')) {
                    throw new RuntimeException('Calendar exception command is unavailable.');
                }
                $calendarDate = (string) ($_POST['calendar_date'] ?? '');
                $exceptionType = (string) ($_POST['exception_type'] ?? '');
                $scopeType = (string) ($_POST['scope_type'] ?? 'global');
                $scopeId = (int) ($_POST['scope_id'] ?? 0);
                $scheduleVersionId = (int) ($_POST['schedule_policy_version_id'] ?? 0);
                $priority = filter_var($_POST['priority'] ?? 0, FILTER_VALIDATE_INT);
                $reason = trim((string) ($_POST['reason'] ?? ''));
                if (DateTimeImmutable::createFromFormat('!Y-m-d', $calendarDate) === false || $reason === '' || mb_strlen($reason, 'UTF-8') > 1000) {
                    throw new InvalidArgumentException('اختر تاريخ الاستثناء واكتب سببًا واضحًا له.');
                }
                if (!in_array($exceptionType, ['holiday', 'closure', 'partial_day', 'makeup_day', 'override'], true)) {
                    throw new InvalidArgumentException('اختر نوع استثناء تقويم صحيحًا.');
                }
                if (!in_array($scopeType, ['global', 'org_unit', 'job_title', 'group', 'staff'], true)
                    || ($scopeType !== 'global' && ($scopeId <= 0 || $scopeId > 2147483647))) {
                    throw new InvalidArgumentException('حدد نطاقًا صحيحًا لاستثناء التقويم.');
                }
                if ($priority === false || $priority < 0 || $priority > 65535) {
                    throw new InvalidArgumentException('أولوية استثناء التقويم يجب أن تكون بين صفر و65535.');
                }
                if (!in_array($exceptionType, ['holiday', 'closure'], true) && $scheduleVersionId <= 0) {
                    throw new InvalidArgumentException('اختر سياسة الدوام البديلة لليوم الجزئي أو اليوم البديل أو التجاوز.');
                }
                $calendarScopeId = $scopeType === 'global' ? null : $scopeId;
                if ($scopeOptionQuery === null || !$scopeOptionQuery->isSelectable(
                    $scopeType,
                    $calendarScopeId,
                    new DateTimeImmutable($calendarDate)
                )) {
                    throw new InvalidArgumentException('نطاق الاستثناء المختار غير موجود أو غير نشط في تاريخ الاستثناء.');
                }
                $policyCommand->saveCalendarException($actorId, [
                    'calendar_date' => $calendarDate,
                    'scope_type' => $scopeType,
                    'scope_id' => $scopeType === 'global' ? null : $scopeId,
                    'priority' => $priority,
                    'exception_type' => $exceptionType,
                    'schedule_policy_version_id' => $scheduleVersionId > 0 ? $scheduleVersionId : null,
                    'reason' => $reason,
                    'status' => 'active',
                ], $idempotencyKey);
                $_SESSION['success_message'] = 'تم تسجيل استثناء التقويم وسيؤخذ في الحسبان عند حساب الحضور.';
            } elseif (isset($_POST['retire_calendar_exception'])) {
                $exceptionId = filter_var($_POST['exception_id'] ?? null, FILTER_VALIDATE_INT);
                $expectedLockVersion = filter_var($_POST['expected_lock_version'] ?? null, FILTER_VALIDATE_INT);
                if ($exceptionId === false || $exceptionId === null || $exceptionId <= 0
                    || $expectedLockVersion === false || $expectedLockVersion === null || $expectedLockVersion <= 0) {
                    throw new InvalidArgumentException('تعذر تحديد استثناء التقويم المطلوب إيقافه. حدّث الصفحة ثم أعد المحاولة.');
                }
                $policyCommand->retireCalendarException(
                    $actorId,
                    (int) $exceptionId,
                    (int) $expectedLockVersion,
                    $idempotencyKey
                );
                $_SESSION['success_message'] = 'تم إيقاف استثناء التقويم مع الاحتفاظ بسجلّه للمراجعة والتقارير السابقة.';
            } elseif (isset($_POST['preview_schedule_policy'])) {
                $versionId = (int) ($_POST['version_id'] ?? 0);
                $asOf = (string) ($_POST['as_of'] ?? date('Y-m-d'));
                if ($versionId <= 0 || DateTimeImmutable::createFromFormat('!Y-m-d', $asOf) === false) {
                    throw new InvalidArgumentException('اختر نسخة سياسة وتاريخ معاينة صحيحين.');
                }
                header('Location: hr_policy_calendar.php?preview_version_id=' . $versionId . '&as_of=' . rawurlencode($asOf));
                exit();
            }
            header('Location: hr_policy_calendar.php');
            exit();
        } catch (Throwable $exception) {
            $reference = 'HRP-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            error_log($reference . ' schedule policy action error: ' . $exception->getMessage());
            $_SESSION['error_message'] = $safeErrorMessage($exception, $reference);
            if (isset($_POST['save_schedule_policy_draft'])) {
                $_SESSION['hr_policy_calendar_old'] = $_POST;
            }
            header('Location: hr_policy_calendar.php');
            exit();
        }
    }

    $filters = [
        'q' => trim((string) ($_GET['q'] ?? '')),
        'state' => trim((string) ($_GET['state'] ?? '')),
        'scope_type' => trim((string) ($_GET['scope_type'] ?? '')),
    ];
    $policies = [];
    $calendarExceptions = [];
    $preview = null;
    $hydratePolicySource = static function (array $source): array {
        $schedule = $source['schedule'] ?? $source['schedule_payload'] ?? [];
        if (is_string($schedule)) {
            $schedule = json_decode($schedule, true) ?: [];
        }
        $schedule = is_array($schedule) ? $schedule : [];
        $days = [];
        foreach ((array) ($schedule['days'] ?? $source['days'] ?? []) as $day) {
            if (is_array($day) && isset($day['weekday'])) {
                $days[(int) $day['weekday']] = $day;
            }
        }
        $scopes = is_array($source['scopes'] ?? null) ? $source['scopes'] : [];
        $scope = is_array($scopes[0] ?? null) ? $scopes[0] : [];
        $validFromRaw = trim((string) ($source['valid_from'] ?? date('Y-m-d')));
        $validToRaw = trim((string) ($source['valid_to'] ?? ''));

        return [
            'policy_code' => (string) ($source['code'] ?? $source['policy_code'] ?? ''),
            'policy_name' => (string) ($source['name'] ?? $source['policy_name'] ?? ''),
            'description' => (string) ($source['description'] ?? ''),
            'valid_from' => (new DateTimeImmutable($validFromRaw))->format('Y-m-d'),
            'valid_to' => $validToRaw === '' ? '' : (new DateTimeImmutable($validToRaw))->modify('-1 day')->format('Y-m-d'),
            'timezone' => (string) ($schedule['timezone'] ?? $source['timezone'] ?? 'Africa/Cairo'),
            'season_start_mmdd' => (string) ($schedule['season_start_mmdd'] ?? $source['season_start_mmdd'] ?? ''),
            'season_end_mmdd' => (string) ($schedule['season_end_mmdd'] ?? $source['season_end_mmdd'] ?? ''),
            'scope_type' => (string) ($scope['scope_type'] ?? 'global'),
            'scope_id' => (int) ($scope['scope_id'] ?? 0),
            'priority' => (int) ($scope['priority'] ?? 0),
            'rounding_rule' => (string) ($schedule['rounding_rule'] ?? $source['rounding_rule'] ?? 'none'),
            'days' => $days,
        ];
    };
    if ($servicesReady && $policyAdminQuery !== null) {
        try {
            $policies = (array) $policyAdminQuery->listPolicies($filters);
            if ($scopeOptionQuery !== null) {
                $scopeOptions = $scopeOptionQuery->options();
            }
            $resolveStaffId = filter_var($_GET['resolve_staff_user_id'] ?? null, FILTER_VALIDATE_INT);
            $resolveDateRaw = trim((string) ($_GET['resolve_date'] ?? ''));
            if ($resolveStaffId !== false && $resolveStaffId !== null && $resolveStaffId > 0 && $resolveDateRaw !== '') {
                $resolveDate = DateTimeImmutable::createFromFormat('!Y-m-d', $resolveDateRaw);
                if ($resolveDate === false || $resolveDate->format('Y-m-d') !== $resolveDateRaw) {
                    throw new InvalidArgumentException('اختر تاريخًا صحيحًا لعرض الدوام الفعلي.');
                }
                if ($scopeOptionQuery === null || !$scopeOptionQuery->isSelectable('staff', (int) $resolveStaffId, $resolveDate)) {
                    throw new InvalidArgumentException('العامل المختار غير موجود أو غير نشط في التاريخ المحدد.');
                }
                if ($effectiveScheduleQuery === null) {
                    throw new RuntimeException('Effective schedule query is unavailable.');
                }
                $effectiveResolution = $effectiveScheduleQuery->forStaffDate((int) $resolveStaffId, $resolveDate);
            } elseif (($resolveStaffId !== false && $resolveStaffId !== null) || $resolveDateRaw !== '') {
                throw new InvalidArgumentException('اختر العامل والتاريخ معًا لعرض الدوام الفعلي.');
            }
            $calendarExceptions = (array) $policyAdminQuery->listCalendarExceptions([
                'date_from' => (string) ($_GET['calendar_from'] ?? date('Y-m-01')),
                'date_to' => (string) ($_GET['calendar_to'] ?? date('Y-m-t')),
            ]);
            $editVersionId = (int) ($_GET['edit_version_id'] ?? 0);
            $cloneVersionId = (int) ($_GET['clone_version_id'] ?? 0);
            if ($editVersionId > 0 && $cloneVersionId > 0) {
                throw new InvalidArgumentException('اختر تعديل المسودة أو إنشاء نسخة جديدة، وليس العمليتين معًا.');
            }
            if ($editVersionId > 0 && $oldPolicyInput === []) {
                $editSource = (array) $policyAdminQuery->findVersion($editVersionId);
                if ($editSource === [] || (string) ($editSource['state'] ?? '') !== 'draft') {
                    throw new InvalidArgumentException('لا يمكن تعديل هذه النسخة لأنها غير موجودة أو لم تعد مسودة.');
                }
                $oldPolicyInput = $hydratePolicySource($editSource) + [
                    'edit_version_id' => $editVersionId,
                    'expected_lock_version' => (int) ($editSource['lock_version'] ?? 0),
                    'existing_policy_id' => 0,
                    'supersedes_version_id' => (int) ($editSource['supersedes_id'] ?? 0),
                ];
                $success_message = 'تم تحميل المسودة في المحرر. سيُرفض الحفظ إذا عدّلها مستخدم آخر قبل إرسالك.';
            }
            if ($cloneVersionId > 0 && $oldPolicyInput === []) {
                $cloneSource = (array) $policyAdminQuery->findVersion($cloneVersionId);
                if ($cloneSource === [] || (string) ($cloneSource['state'] ?? '') !== 'published') {
                    throw new InvalidArgumentException('لا يمكن إنشاء نسخة جديدة إلا من سياسة منشورة موجودة.');
                }
                $cloneInput = $hydratePolicySource($cloneSource);
                $exclusiveEnd = trim((string) ($cloneSource['valid_to'] ?? ''));
                $predecessorStart = new DateTimeImmutable((string) ($cloneSource['valid_from'] ?? date('Y-m-d')));
                if ($exclusiveEnd !== '') {
                    $cloneValidFrom = (new DateTimeImmutable($exclusiveEnd))->format('Y-m-d');
                } else {
                    $minimumStart = $predecessorStart->modify('+1 day');
                    $today = new DateTimeImmutable('today', new DateTimeZone('Africa/Cairo'));
                    $cloneValidFrom = ($today > $minimumStart ? $today : $minimumStart)->format('Y-m-d');
                }
                $oldPolicyInput = $cloneInput + [
                    'existing_policy_id' => (int) ($cloneSource['policy_id'] ?? 0),
                    'supersedes_version_id' => $cloneVersionId,
                ];
                $oldPolicyInput['valid_from'] = $cloneValidFrom;
                $oldPolicyInput['valid_to'] = '';
                $success_message = 'تم تحميل النسخة الحالية في المحرر. غيّر فترة السريان ثم احفظها كمسودة جديدة؛ النسخة المنشورة الأصلية لن تتغير.';
            }
            $previewVersionId = (int) ($_GET['preview_version_id'] ?? 0);
            if ($previewVersionId > 0 && $policyImpact !== null) {
                $asOf = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($_GET['as_of'] ?? date('Y-m-d')));
                if ($asOf === false) {
                    throw new InvalidArgumentException('تاريخ المعاينة غير صحيح.');
                }
                $preview = $policyImpact->previewDraft($previewVersionId, $asOf, 100);
            }
        } catch (Throwable $exception) {
            $reference = 'HRP-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            error_log($reference . ' schedule policy read error: ' . $exception->getMessage());
            $error_message = $safeErrorMessage($exception, $reference);
        }
    }

    $publishedCount = count(array_filter($policies, static fn (array $row): bool => ($row['state'] ?? '') === 'published'));
    $draftCount = count(array_filter($policies, static fn (array $row): bool => ($row['state'] ?? '') === 'draft'));
    $conflictCount = (int) ($preview['summary']['conflict_count'] ?? 0);
    $policyIdempotency = bin2hex(random_bytes(16));
    $calendarIdempotency = bin2hex(random_bytes(16));
    $calendarRetireIdempotency = bin2hex(random_bytes(16));
    $publishIdempotency = bin2hex(random_bytes(16));
    $previewIdempotency = bin2hex(random_bytes(16));
    $formatPreviewResolution = static function (mixed $resolution): string {
        if (!is_array($resolution) || (int) ($resolution['policy_id'] ?? 0) <= 0) {
            return 'لا توجد سياسة دوام فعالة';
        }
        $scopeType = (string) ($resolution['scope_type'] ?? 'global');
        $scopeId = (int) ($resolution['scope_id'] ?? 0);
        $scopeLabel = match ($scopeType) {
            'global' => 'عام',
            'org_unit' => 'قوة/وحدة #' . $scopeId,
            'job_title' => 'مسمى وظيفي #' . $scopeId,
            'group' => 'مجموعة #' . $scopeId,
            'staff' => 'عامل #' . $scopeId,
            default => 'نطاق غير محدد',
        };
        $label = (string) ($resolution['policy_name'] ?? 'سياسة دوام');
        $label .= ' — نسخة ' . (int) ($resolution['version_id'] ?? 0) . ' — ' . $scopeLabel;
        $workingDays = array_filter(
            (array) ($resolution['schedule']['days'] ?? []),
            static fn (mixed $day): bool => is_array($day) && !empty($day['is_working_day'])
        );
        if ($workingDays !== []) {
            $firstDay = reset($workingDays);
            if (is_array($firstDay) && !empty($firstDay['start_time']) && !empty($firstDay['end_time'])) {
                $label .= ' — ' . (string) $firstDay['start_time'] . ' إلى ' . (string) $firstDay['end_time'];
                if ((int) ($firstDay['end_day_offset'] ?? 0) > 0) {
                    $label .= ' (ينتهي في اليوم التالي)';
                }
            }
        }
        return $label;
    };
    $formatPreviewExplanation = static function (mixed $explanation): string {
        if (!is_array($explanation)) {
            return is_scalar($explanation) ? (string) $explanation : 'تغير مصدر سياسة الدوام الفعلية.';
        }
        $reasonCode = (string) ($explanation['impact_reason_code'] ?? $explanation['reason_code'] ?? '');
        $reason = match ($reasonCode) {
            'EFFECTIVE_SCHEDULE_WOULD_CHANGE' => 'ستتغير سياسة الدوام الفعلية بعد النشر.',
            'CALENDAR_EXCEPTION_APPLIED' => 'طُبق استثناء تقويم على هذا التاريخ.',
            default => 'تم الاختيار وفق النطاق والأولوية وفترة السريان.',
        };
        if (!empty($explanation['calendar_exception_id'])) {
            $reason .= ' استثناء التقويم #' . (int) $explanation['calendar_exception_id'] . '.';
        }
        return $reason;
    };
}

require_once '../includes/admin_header.php';

if ($staffShiftCompatibilityMode):
?>
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-business-time me-2"></i>إعدادات الدوام المتوافقة</h1>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2">
        <a href="staff_attendance.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-right me-1"></i>العودة للحضور</a>
        <a href="hr_policy_calendar.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-calendar-alt me-1"></i>السياسات والتقويم</a>
    </div>
</div>

<?php if ($success_message): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars((string) $success_message, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
</div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars((string) $error_message, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
</div>
<?php endif; ?>

<div class="alert alert-info">
    <i class="fas fa-route me-2"></i>
    هذه الواجهة تحافظ على الحقول القديمة أثناء الانتقال. أنشئ السياسات المؤرخة وعاين أثرها من صفحة «السياسات والتقويم» قبل اعتمادها رسميًا.
</div>

<div class="card shadow admin-card-surface mb-4">
    <div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="fas fa-clock me-2"></i>الدوام الافتراضي العام</h5></div>
    <div class="card-body">
        <form method="POST" action="<?php echo htmlspecialchars($compatibilityFormAction, ENT_QUOTES, 'UTF-8'); ?>" class="row g-3 align-items-end">
            <?php echo csrfField(); ?>
            <div class="col-md-3"><label class="form-label" for="legacyDefaultStart">بداية الدوام</label><input id="legacyDefaultStart" type="time" class="form-control" name="default_shift_start" value="<?php echo htmlspecialchars($defaultShiftStart, ENT_QUOTES, 'UTF-8'); ?>" required></div>
            <div class="col-md-3"><label class="form-label" for="legacyDefaultEnd">نهاية الدوام</label><input id="legacyDefaultEnd" type="time" class="form-control" name="default_shift_end" value="<?php echo htmlspecialchars($defaultShiftEnd, ENT_QUOTES, 'UTF-8'); ?>" required></div>
            <div class="col-md-3"><label class="form-label" for="legacyDefaultGrace">فترة السماح (دقيقة)</label><input id="legacyDefaultGrace" type="number" min="0" class="form-control" name="default_shift_grace_minutes" value="<?php echo (int) $defaultGrace; ?>" required></div>
            <div class="col-md-3"><button type="submit" name="save_default_shift" class="btn btn-primary w-100"><i class="fas fa-save me-1"></i>حفظ الإعدادات</button></div>
        </form>
    </div>
</div>

<div class="card shadow admin-card-surface mb-4">
    <div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="fas fa-user-cog me-2"></i>دوام مخصص للعامل</h5></div>
    <div class="card-body">
        <form method="POST" action="<?php echo htmlspecialchars($compatibilityFormAction, ENT_QUOTES, 'UTF-8'); ?>" class="row g-3 align-items-end">
            <?php echo csrfField(); ?>
            <div class="col-md-3"><label class="form-label" for="legacyStaffUser">العامل</label><select id="legacyStaffUser" class="form-select" name="user_id" required><option value="">اختر العامل...</option><?php foreach ($staffList as $staff): ?><option value="<?php echo (int) $staff['id']; ?>"><?php echo htmlspecialchars((string) $staff['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label" for="legacyShiftStart">بداية الدوام</label><input id="legacyShiftStart" type="time" class="form-control" name="shift_start" value="<?php echo htmlspecialchars($defaultShiftStart, ENT_QUOTES, 'UTF-8'); ?>" required></div>
            <div class="col-md-2"><label class="form-label" for="legacyShiftEnd">نهاية الدوام</label><input id="legacyShiftEnd" type="time" class="form-control" name="shift_end" value="<?php echo htmlspecialchars($defaultShiftEnd, ENT_QUOTES, 'UTF-8'); ?>" required></div>
            <div class="col-md-2"><label class="form-label" for="legacyShiftGrace">السماح بالدقائق</label><input id="legacyShiftGrace" type="number" min="0" class="form-control" name="grace_minutes" value="<?php echo (int) $defaultGrace; ?>" required></div>
            <div class="col-md-3"><label class="form-label" for="legacyShiftNotes">ملاحظات</label><input id="legacyShiftNotes" type="text" class="form-control" name="notes" placeholder="اختياري"></div>
            <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="legacyShiftActive" checked><label class="form-check-label" for="legacyShiftActive">مفعل</label></div></div>
            <div class="col-md-3"><button type="submit" name="save_shift_override" class="btn btn-success w-100"><i class="fas fa-plus-circle me-1"></i>حفظ دوام العامل</button></div>
        </form>
    </div>
</div>

<div class="admin-list-surface">
    <div class="admin-table-wrap">
        <table class="table table-hover table-striped admin-data-table">
            <thead><tr><th>#</th><th>العامل</th><th>الدوام</th><th>السماح</th><th>الحالة</th><th>ملاحظات</th><th>الإجراءات</th></tr></thead>
            <tbody>
            <?php if ($overrides === []): ?>
                <tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-info-circle me-1"></i>لا توجد دوامات مخصصة حتى الآن.</td></tr>
            <?php else: foreach ($overrides as $index => $row): ?>
                <tr>
                    <td><?php echo $index + 1; ?></td>
                    <td><?php echo htmlspecialchars((string) $row['staff_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars(substr((string) $row['shift_start'], 0, 5) . ' - ' . substr((string) $row['shift_end'], 0, 5), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int) $row['grace_minutes']; ?> دقيقة</td>
                    <td><span class="badge <?php echo (int) $row['is_active'] === 1 ? 'bg-success' : 'bg-warning text-dark'; ?>"><?php echo (int) $row['is_active'] === 1 ? 'مفعل' : 'غير مفعل'; ?></span></td>
                    <td><?php echo htmlspecialchars((string) ($row['notes'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><button type="button" class="btn btn-action-pills btn-delete" data-bs-toggle="modal" data-bs-target="#deleteShiftOverrideModal" data-shift-id="<?php echo (int) $row['id']; ?>" data-staff-name="<?php echo htmlspecialchars((string) $row['staff_name'], ENT_QUOTES, 'UTF-8'); ?>" title="حذف"><i class="fas fa-trash"></i></button></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="deleteShiftOverrideModal" tabindex="-1" aria-labelledby="deleteShiftOverrideTitle" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
        <form method="POST" action="<?php echo htmlspecialchars($compatibilityFormAction, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo csrfField(); ?>
            <input type="hidden" name="id" id="deleteShiftOverrideId" value="">
            <div class="modal-header"><h5 class="modal-title" id="deleteShiftOverrideTitle"><i class="fas fa-trash me-2"></i>حذف الدوام المخصص</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
            <div class="modal-body"><div class="text-center mb-3"><i class="fas fa-user-clock text-danger fa-3x"></i></div><p class="text-center">هل تريد حذف الدوام المخصص للعامل <span class="fw-bold text-primary" id="deleteShiftOverrideName"></span>؟</p><div class="alert alert-warning mb-0"><i class="fas fa-info-circle me-2"></i>سيعود العامل إلى الدوام الافتراضي أو السياسة الفعالة التالية.</div></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" name="delete_shift_override" class="btn btn-danger"><i class="fas fa-trash me-1"></i>حذف</button></div>
        </form>
    </div></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var deleteModal = document.getElementById('deleteShiftOverrideModal');
    if (deleteModal) {
        deleteModal.addEventListener('show.bs.modal', function (event) {
            var trigger = event.relatedTarget;
            document.getElementById('deleteShiftOverrideId').value = trigger ? trigger.getAttribute('data-shift-id') : '';
            document.getElementById('deleteShiftOverrideName').textContent = trigger ? trigger.getAttribute('data-staff-name') : '';
        });
    }
});
</script>
<?php require_once '../includes/admin_footer.php'; return; endif; ?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="fas fa-calendar-check me-2"></i>سياسات الدوام والتقويم</h1>
    <div class="btn-toolbar mb-2 mb-md-0 gap-2"><a href="staff_shifts.php" class="btn btn-outline-secondary"><i class="fas fa-history me-1"></i>الإعدادات المتوافقة</a><a href="staff_attendance.php" class="btn btn-outline-primary"><i class="fas fa-user-clock me-1"></i>متابعة الحضور</a></div>
</div>

<?php if ($legacyShiftSnapshot !== []): ?>
<div class="alert alert-light border"><i class="fas fa-shield-halved me-2"></i>إعداد الرجوع المتوافق الحالي: <?php echo htmlspecialchars((string) $legacyShiftSnapshot['defaultShiftStart'], ENT_QUOTES, 'UTF-8'); ?>–<?php echo htmlspecialchars((string) $legacyShiftSnapshot['defaultShiftEnd'], ENT_QUOTES, 'UTF-8'); ?>، سماح <?php echo (int) $legacyShiftSnapshot['defaultGrace']; ?> دقيقة. يبقى متاحًا أثناء مراحل الظل والمقارنة.</div>
<?php endif; ?>

<?php if ($success_message): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars((string) $success_message, ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button></div><?php endif; ?>
<?php if ($error_message): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars((string) $error_message, ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button></div><?php endif; ?>

<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <?php foreach ([['#3b82f6','#2563eb','fa-layer-group',count($policies),'إجمالي السياسات'],['#10b981','#059669','fa-check-circle',$publishedCount,'منشورة'],['#f59e0b','#d97706','fa-pen-ruler',$draftCount,'مسودات'],['#ef4444','#dc2626','fa-triangle-exclamation',$conflictCount,'تعارضات المعاينة']] as $stat): ?>
    <div class="col"><div class="stat-card" style="--card-gradient: linear-gradient(135deg, <?php echo $stat[0]; ?>, <?php echo $stat[1]; ?>);"><div class="stat-card-icon"><i class="fas <?php echo $stat[2]; ?>"></i></div><div class="stat-card-info"><div class="stat-card-number counter" data-target="<?php echo (int) $stat[3]; ?>">0</div><div class="stat-card-label"><?php echo htmlspecialchars($stat[4], ENT_QUOTES, 'UTF-8'); ?></div><div class="stat-card-sub"><i class="fas fa-calendar-day"></i> حسب الفلاتر الحالية</div></div></div></div>
    <?php endforeach; ?>
</div>

<div class="card shadow admin-card-surface mb-4" id="effectiveScheduleResolver">
    <div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="fas fa-route me-2"></i>عرض الدوام الفعلي وسبب الاختيار</h5></div>
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-6"><label class="form-label" for="resolveStaffUserId">العامل</label><select id="resolveStaffUserId" class="form-select" name="resolve_staff_user_id" required><option value="">اختر العامل</option><?php foreach ((array) ($scopeOptions['staff'] ?? []) as $staffOption): $staffOptionId = (int) ($staffOption['id'] ?? 0); ?><option value="<?php echo $staffOptionId; ?>" <?php echo (int) ($_GET['resolve_staff_user_id'] ?? 0) === $staffOptionId ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) ($staffOption['label'] ?? ('#' . $staffOptionId)), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label" for="resolveDate">التاريخ</label><input id="resolveDate" type="date" class="form-control" name="resolve_date" value="<?php echo htmlspecialchars((string) ($_GET['resolve_date'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8'); ?>" required></div>
            <div class="col-md-3 d-flex gap-2"><button type="submit" class="btn btn-outline-primary"><i class="fas fa-magnifying-glass me-1"></i>عرض السبب</button><a href="hr_policy_calendar.php#effectiveScheduleResolver" class="btn btn-outline-secondary"><i class="fas fa-rotate-left me-1"></i>مسح</a></div>
        </form>
        <?php if (is_array($effectiveResolution)):
            $effectiveSelected = is_array($effectiveResolution['selected'] ?? null) ? $effectiveResolution['selected'] : null;
            $effectiveConflicts = (array) ($effectiveResolution['conflicts'] ?? []);
            $effectiveSchedule = $effectiveSelected['schedule'] ?? null;
            $effectiveRequiredMinutes = $effectiveSchedule instanceof \EduCore\Modules\Attendance\Domain\Schedule\WorkSchedule && $resolveDate instanceof DateTimeImmutable
                ? $effectiveSchedule->requiredMinutes($resolveDate)
                : 0;
            $effectiveSegments = $effectiveSchedule instanceof \EduCore\Modules\Attendance\Domain\Schedule\WorkSchedule && $resolveDate instanceof DateTimeImmutable
                ? $effectiveSchedule->segmentsForDate($resolveDate)
                : [];
            $effectiveUnpaidBreaks = count(array_filter($effectiveSegments, static fn (array $segment): bool => (string) ($segment['segment_type'] ?? '') === 'unpaid_break'));
            $effectiveOvertime = is_array($effectiveSelected['approved_overtime'] ?? null) ? $effectiveSelected['approved_overtime'] : [];
        ?>
        <div class="alert <?php echo $effectiveSelected !== null && $effectiveConflicts === [] ? 'alert-success' : 'alert-danger'; ?> mt-3 mb-0" id="effectiveScheduleResult"
             data-effective-schedule-change-request-id="<?php echo (int) ($effectiveSelected['schedule_change_request_id'] ?? 0); ?>"
             data-effective-required-minutes="<?php echo (int) $effectiveRequiredMinutes; ?>"
             data-effective-segment-count="<?php echo count($effectiveSegments); ?>"
             data-effective-unpaid-break-count="<?php echo (int) $effectiveUnpaidBreaks; ?>"
             data-effective-approved-overtime-count="<?php echo count($effectiveOvertime); ?>">
            <div><strong>حالة الحل:</strong> <?php echo htmlspecialchars((string) ($effectiveResolution['status'] ?? 'unresolved'), ENT_QUOTES, 'UTF-8'); ?></div>
            <div><strong>سبب الدوام الفعلي:</strong> <?php echo htmlspecialchars((string) (($effectiveResolution['explanation']['reason_code'] ?? null) ?: ($effectiveResolution['reason_code'] ?? 'SCHEDULE_UNRESOLVED')), ENT_QUOTES, 'UTF-8'); ?></div>
            <?php if ($effectiveSelected !== null): ?><div><strong>مصدر السياسة:</strong> <?php echo htmlspecialchars((string) ($effectiveSelected['policy_name'] ?? $effectiveSelected['policy_code'] ?? ('نسخة #' . (int) ($effectiveSelected['version_id'] ?? 0))), ENT_QUOTES, 'UTF-8'); ?> — <span data-effective-version-id="<?php echo (int) ($effectiveSelected['version_id'] ?? 0); ?>">نسخة <?php echo (int) ($effectiveSelected['version_id'] ?? 0); ?></span> — نطاق <?php echo htmlspecialchars((string) ($effectiveSelected['scope_type'] ?? 'global'), ENT_QUOTES, 'UTF-8'); ?> — أولوية <?php echo (int) ($effectiveSelected['scope_priority'] ?? $effectiveSelected['priority'] ?? 0); ?></div><?php endif; ?>
            <?php if ($effectiveSelected !== null): ?><div><strong>تفاصيل الاحتساب:</strong> <?php echo (int) $effectiveRequiredMinutes; ?> دقيقة مطلوبة، <?php echo count($effectiveSegments); ?> مقاطع، <?php echo (int) $effectiveUnpaidBreaks; ?> استراحة غير مدفوعة، <?php echo count($effectiveOvertime); ?> طلب إضافي معتمد.</div><?php endif; ?>
            <?php if ($effectiveConflicts !== []): ?><div><strong>تعارضات متساوية:</strong> <?php echo count($effectiveConflicts); ?>. لن يحتسب النظام يوم الحضور حتى تصحيح التعارض.</div><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<form method="GET" class="admin-filter-bar">
    <div class="admin-filter-controls"><input type="search" class="form-control" name="q" value="<?php echo htmlspecialchars($filters['q'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="ابحث بالكود أو الاسم"><select class="form-select" name="state"><option value="">كل الحالات</option><option value="draft" <?php echo $filters['state'] === 'draft' ? 'selected' : ''; ?>>مسودة</option><option value="published" <?php echo $filters['state'] === 'published' ? 'selected' : ''; ?>>منشورة</option><option value="retired" <?php echo $filters['state'] === 'retired' ? 'selected' : ''; ?>>متوقفة</option></select><select class="form-select" name="scope_type"><option value="">كل النطاقات</option><?php foreach (['global'=>'عام','org_unit'=>'قوة/وحدة','job_title'=>'مسمى وظيفي','group'=>'مجموعة','staff'=>'عامل'] as $value => $label): ?><option value="<?php echo $value; ?>" <?php echo $filters['scope_type'] === $value ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div>
    <div class="admin-filter-actions"><button type="submit" class="btn btn-light btn-sm"><i class="fas fa-search me-1"></i>بحث</button><a href="hr_policy_calendar.php" class="btn btn-light btn-sm"><i class="fas fa-rotate-left me-1"></i>إعادة تعيين</a></div>
</form>

<div class="admin-list-surface mb-4"><div class="admin-table-wrap"><table class="table table-hover table-striped admin-data-table"><thead><tr><th>السياسة</th><th>النسخة</th><th>النطاق</th><th>السريان</th><th>الحالة</th><th>الإجراءات</th></tr></thead><tbody>
<?php if ($policies === []): ?><tr><td colspan="6" class="text-center text-muted py-4"><i class="fas fa-calendar-plus me-1"></i>لا توجد سياسات مطابقة. أنشئ مسودة من النموذج أدناه.</td></tr><?php else: foreach ($policies as $policy): $versionId = (int) ($policy['version_id'] ?? $policy['latest_version_id'] ?? 0); $validToDisplay = empty($policy['valid_to']) ? 'مفتوح' : (new DateTimeImmutable((string) $policy['valid_to']))->modify('-1 day')->format('Y-m-d'); ?>
<tr><td><strong><?php echo htmlspecialchars((string) ($policy['name'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong><div class="small text-muted"><?php echo htmlspecialchars((string) ($policy['code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div></td><td><?php echo htmlspecialchars((string) ($policy['version_no'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string) ($policy['scope_label'] ?? $policy['scope_type'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string) ($policy['valid_from'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?> — <?php echo htmlspecialchars($validToDisplay, ENT_QUOTES, 'UTF-8'); ?></td><td><span class="badge <?php echo ($policy['state'] ?? '') === 'published' ? 'bg-success' : 'bg-warning text-dark'; ?>"><?php echo htmlspecialchars((string) ($policy['state'] ?? 'draft'), ENT_QUOTES, 'UTF-8'); ?></span></td><td>
<form method="POST" class="d-inline"><?php echo csrfField(); ?><input type="hidden" name="idempotency_key" value="<?php echo $previewIdempotency; ?>"><input type="hidden" name="version_id" value="<?php echo $versionId; ?>"><input type="hidden" name="as_of" value="<?php echo date('Y-m-d'); ?>"><button type="submit" name="preview_schedule_policy" class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="معاينة الأثر"><i class="fas fa-magnifying-glass-chart"></i></button></form>
<?php if (($policy['state'] ?? '') === 'published'): ?><a href="hr_policy_calendar.php?clone_version_id=<?php echo $versionId; ?>" class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="إنشاء نسخة جديدة"><i class="fas fa-code-branch"></i></a><?php endif; ?>
<?php if (($policy['state'] ?? '') === 'draft'): ?><a href="hr_policy_calendar.php?edit_version_id=<?php echo $versionId; ?>" class="btn btn-action-pills btn-edit me-1" data-bs-toggle="tooltip" title="تعديل المسودة"><i class="fas fa-pen"></i></a><button type="button" class="btn btn-action-pills btn-activate" data-bs-toggle="modal" data-bs-target="#publishSchedulePolicyModal" data-version-id="<?php echo $versionId; ?>" data-policy-name="<?php echo htmlspecialchars((string) ($policy['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" title="نشر"><i class="fas fa-check"></i></button><?php endif; ?>
</td></tr><?php endforeach; endif; ?></tbody></table></div></div>

<?php if (is_array($preview)): $summary = (array) ($preview['summary'] ?? []); ?>
<div class="card shadow admin-card-surface mb-4"><div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="fas fa-route me-2"></i>سبب الدوام الفعلي وأثر السياسة</h5></div><div class="card-body"><div class="alert <?php echo ((int) ($summary['conflict_count'] ?? 0)) > 0 ? 'alert-danger' : 'alert-info'; ?>"><strong>مصدر السياسة:</strong> النسخة التي تمت معاينتها في تاريخ السريان المحدد. عدد العاملين المتأثرين: <?php echo (int) ($summary['affected'] ?? 0); ?> من <?php echo (int) ($summary['population'] ?? 0); ?>، والتعارضات: <?php echo (int) ($summary['conflict_count'] ?? 0); ?>.</div><div class="admin-list-surface"><div class="admin-table-wrap"><table class="table table-hover table-striped admin-data-table"><thead><tr><th>العامل</th><th>الدوام الحالي</th><th>الدوام المقترح</th><th>سبب الاختيار</th></tr></thead><tbody><?php foreach ((array) ($preview['affected_staff'] ?? []) as $affected): ?><tr><td><?php echo (int) ($affected['staff_id'] ?? 0); ?></td><td><?php echo htmlspecialchars($formatPreviewResolution($affected['current'] ?? null), ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($formatPreviewResolution($affected['proposed'] ?? null), ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($formatPreviewExplanation($affected['explanation'] ?? null), ENT_QUOTES, 'UTF-8'); ?></td></tr><?php endforeach; ?></tbody></table></div></div></div></div>
<?php else: ?><div class="alert alert-secondary mb-4"><i class="fas fa-lightbulb me-2"></i><strong>سبب الدوام الفعلي:</strong> احفظ المسودة ثم استخدم «معاينة الأثر» لرؤية مصدر السياسة والنطاق وتاريخ السريان وأي تعارض قبل النشر.</div><?php endif; ?>

<?php
$policyFormScopeType = (string) ($oldPolicyInput['scope_type'] ?? 'global');
$policyFormRoundingRule = (string) ($oldPolicyInput['rounding_rule'] ?? 'none');
$policyFormDays = is_array($oldPolicyInput['days'] ?? null) ? $oldPolicyInput['days'] : [];
$policyFormHydrated = $oldPolicyInput !== [];
?>

<div class="card shadow admin-card-surface mb-4"><div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="fas fa-pen-ruler me-2"></i><?php echo !empty($oldPolicyInput['edit_version_id']) ? 'تعديل مسودة سياسة الدوام' : (!empty($oldPolicyInput['existing_policy_id']) ? 'إنشاء نسخة جديدة من السياسة' : 'إنشاء مسودة سياسة دوام'); ?></h5></div><div class="card-body"><form method="POST"><input type="hidden" name="idempotency_key" value="<?php echo $policyIdempotency; ?>"><input type="hidden" name="edit_version_id" value="<?php echo (int) ($oldPolicyInput['edit_version_id'] ?? 0); ?>"><input type="hidden" name="expected_lock_version" value="<?php echo (int) ($oldPolicyInput['expected_lock_version'] ?? 0); ?>"><input type="hidden" name="existing_policy_id" value="<?php echo (int) ($oldPolicyInput['existing_policy_id'] ?? 0); ?>"><input type="hidden" name="supersedes_version_id" value="<?php echo (int) ($oldPolicyInput['supersedes_version_id'] ?? 0); ?>"><?php echo csrfField(); ?><div class="row g-3">
<div class="col-md-3"><label class="form-label" for="policyCode">كود السياسة</label><input id="policyCode" class="form-control" name="policy_code" maxlength="50" value="<?php echo htmlspecialchars((string) ($oldPolicyInput['policy_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="SHIFT-GLOBAL" required></div><div class="col-md-5"><label class="form-label" for="policyName">اسم السياسة</label><input id="policyName" class="form-control" name="policy_name" maxlength="200" value="<?php echo htmlspecialchars((string) ($oldPolicyInput['policy_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required></div><div class="col-md-4"><label class="form-label" for="policyTimezone">المنطقة الزمنية</label><input id="policyTimezone" class="form-control" name="timezone" value="<?php echo htmlspecialchars((string) ($oldPolicyInput['timezone'] ?? 'Africa/Cairo'), ENT_QUOTES, 'UTF-8'); ?>" readonly></div>
<div class="col-md-3"><label class="form-label" for="policyFrom">بداية السريان</label><input id="policyFrom" type="date" class="form-control" name="valid_from" value="<?php echo htmlspecialchars((string) ($oldPolicyInput['valid_from'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8'); ?>" required></div>
<div class="col-md-3"><label class="form-label" for="policyTo">آخر يوم سريان (شامل)</label><input id="policyTo" type="date" class="form-control" name="valid_to" value="<?php echo htmlspecialchars((string) ($oldPolicyInput['valid_to'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
<div class="col-md-3"><label class="form-label" for="policyScope">النطاق</label><select id="policyScope" class="form-select" name="scope_type"><?php foreach (['global'=>'عام','org_unit'=>'قوة/وحدة','job_title'=>'مسمى وظيفي','group'=>'مجموعة','staff'=>'عامل'] as $value => $label): ?><option value="<?php echo $value; ?>" <?php echo $policyFormScopeType === $value ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><label class="form-label" for="policyScopeSearch">بحث النطاق</label><input id="policyScopeSearch" type="search" class="form-control" placeholder="اكتب الاسم أو الكود" autocomplete="off"></div>
<div class="col-md-4"><label class="form-label" for="policyScopeId">الجهة أو المسمى أو المجموعة أو العامل</label><select id="policyScopeId" class="form-select js-scope-option-picker" name="scope_id"><option value="">اختر نطاقًا مسمى</option><?php foreach ($scopeOptions as $scopeType => $options): foreach ($options as $option): $optionId = (int) ($option['id'] ?? 0); ?><option value="<?php echo $optionId; ?>" data-scope-type="<?php echo htmlspecialchars((string) $scopeType, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $policyFormScopeType === $scopeType && (int) ($oldPolicyInput['scope_id'] ?? 0) === $optionId ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) ($option['label'] ?? ('#' . $optionId)), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; endforeach; ?></select></div>
<div class="col-md-1"><label class="form-label" for="policyPriority">الأولوية</label><input id="policyPriority" type="number" min="0" max="65535" class="form-control" name="priority" value="<?php echo (int) ($oldPolicyInput['priority'] ?? 0); ?>"></div>
<div class="col-md-2"><label class="form-label" for="roundingRule">قاعدة التقريب</label><select id="roundingRule" class="form-select" name="rounding_rule"><?php foreach (['none'=>'بدون تقريب','nearest_5'=>'أقرب 5 دقائق','nearest_15'=>'أقرب 15 دقيقة'] as $value => $label): ?><option value="<?php echo $value; ?>" <?php echo $policyFormRoundingRule === $value ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><label class="form-label" for="seasonStart">بداية الموسم</label><input id="seasonStart" class="form-control" name="season_start_mmdd" value="<?php echo htmlspecialchars((string) ($oldPolicyInput['season_start_mmdd'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" inputmode="numeric" placeholder="09-01" pattern="(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])"></div>
<div class="col-md-2"><label class="form-label" for="seasonEnd">نهاية الموسم</label><input id="seasonEnd" class="form-control" name="season_end_mmdd" value="<?php echo htmlspecialchars((string) ($oldPolicyInput['season_end_mmdd'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" inputmode="numeric" placeholder="05-31" pattern="(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])"></div>
<div class="col-md-6"><label class="form-label" for="policyDescription">وصف السياسة</label><input id="policyDescription" class="form-control" name="description" maxlength="1000" value="<?php echo htmlspecialchars((string) ($oldPolicyInput['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="متى ولماذا تستخدم هذه السياسة؟"></div>
</div>
<div class="table-responsive mt-4">
<table class="table table-hover table-striped align-middle">
<thead><tr><th>اليوم</th><th>عمل</th><th>السماح حضور/انصراف</th><th>نافذة الدخول قبل/بعد</th><th>نافذة الخروج قبل/بعد</th><th>المقاطع</th></tr></thead>
<tbody>
<?php foreach ([6=>'السبت',7=>'الأحد',1=>'الإثنين',2=>'الثلاثاء',3=>'الأربعاء',4=>'الخميس',5=>'الجمعة'] as $weekday => $dayName):
    $dayInput = is_array($policyFormDays[$weekday] ?? null) ? $policyFormDays[$weekday] : [];
    $workingValue = $dayInput['is_working_day'] ?? null;
    $workingDefault = array_key_exists('is_working_day', $dayInput)
        ? !in_array($workingValue, [false, 0, '0', '', null], true)
        : in_array($weekday, [7, 1, 2, 3, 4], true);
    $lateGraceValue = (int) ($dayInput['late_grace_minutes'] ?? 15);
    $earlyGraceValue = (int) ($dayInput['early_grace_minutes'] ?? 0);
    $entryBeforeValue = (int) ($dayInput['entry_window_before_minutes'] ?? 120);
    $entryAfterValue = (int) ($dayInput['entry_window_after_minutes'] ?? 180);
    $exitBeforeValue = (int) ($dayInput['exit_window_before_minutes'] ?? 180);
    $exitAfterValue = (int) ($dayInput['exit_window_after_minutes'] ?? 120);
    $daySegments = is_array($dayInput['segments'] ?? null) ? array_values($dayInput['segments']) : [];
    $segmentRowCount = max(3, min(20, count($daySegments)));
?>
<tr>
<td><input type="hidden" name="days[<?php echo $weekday; ?>][weekday]" value="<?php echo $weekday; ?>"><strong><?php echo $dayName; ?></strong></td>
<td><input class="form-check-input" type="checkbox" name="days[<?php echo $weekday; ?>][is_working_day]" <?php echo $workingDefault ? 'checked' : ''; ?> aria-label="<?php echo $dayName; ?> يوم عمل"></td>
<td><div class="input-group input-group-sm"><input type="number" min="0" max="240" class="form-control" name="days[<?php echo $weekday; ?>][late_grace_minutes]" value="<?php echo $lateGraceValue; ?>" aria-label="سماح الحضور"><input type="number" min="0" max="240" class="form-control" name="days[<?php echo $weekday; ?>][early_grace_minutes]" value="<?php echo $earlyGraceValue; ?>" aria-label="سماح الانصراف"></div></td>
<td><div class="input-group input-group-sm"><input type="number" min="0" max="1440" class="form-control" name="days[<?php echo $weekday; ?>][entry_window_before_minutes]" value="<?php echo $entryBeforeValue; ?>" aria-label="نافذة الدخول قبل"><input type="number" min="0" max="1440" class="form-control" name="days[<?php echo $weekday; ?>][entry_window_after_minutes]" value="<?php echo $entryAfterValue; ?>" aria-label="نافذة الدخول بعد"></div></td>
<td><div class="input-group input-group-sm"><input type="number" min="0" max="1440" class="form-control" name="days[<?php echo $weekday; ?>][exit_window_before_minutes]" value="<?php echo $exitBeforeValue; ?>" aria-label="نافذة الخروج قبل"><input type="number" min="0" max="1440" class="form-control" name="days[<?php echo $weekday; ?>][exit_window_after_minutes]" value="<?php echo $exitAfterValue; ?>" aria-label="نافذة الخروج بعد"></div></td>
<td>
<details><summary class="btn btn-outline-secondary btn-sm"><i class="fas fa-timeline me-1"></i>تحرير المقاطع</summary>
<div class="mt-2 schedule-segment-container" data-weekday="<?php echo $weekday; ?>">
<?php for ($segmentIndex = 0; $segmentIndex < $segmentRowCount; $segmentIndex++):
    $segmentInput = is_array($daySegments[$segmentIndex] ?? null) ? $daySegments[$segmentIndex] : [];
    $primaryDefault = !$policyFormHydrated && $segmentIndex === 0;
    $segmentType = (string) ($segmentInput['segment_type'] ?? 'work');
    if (!in_array($segmentType, ['work', 'paid_break', 'unpaid_break', 'on_call', 'overtime'], true)) {
        $segmentType = 'work';
    }
    $segmentStart = (string) ($segmentInput['start_time'] ?? ($primaryDefault ? '07:30' : ''));
    $segmentEnd = (string) ($segmentInput['end_time'] ?? ($primaryDefault ? '14:30' : ''));
    $segmentStartOffset = (int) ($segmentInput['start_day_offset'] ?? 0);
    $segmentEndOffset = (int) ($segmentInput['end_day_offset'] ?? 0);
    $countsValue = $segmentInput['counts_required_minutes'] ?? null;
    $segmentCounts = array_key_exists('counts_required_minutes', $segmentInput)
        ? !in_array($countsValue, [false, 0, '0', '', null], true)
        : $primaryDefault;
?>
<div class="row g-2 mb-2 schedule-segment-row" data-segment-index="<?php echo $segmentIndex; ?>">
<div class="col-md-2"><select class="form-select form-select-sm" name="days[<?php echo $weekday; ?>][segments][<?php echo $segmentIndex; ?>][segment_type]" aria-label="نوع المقطع"><?php foreach (['work'=>'عمل','paid_break'=>'استراحة مدفوعة','unpaid_break'=>'استراحة غير مدفوعة','on_call'=>'استدعاء','overtime'=>'وقت إضافي'] as $value => $label): ?><option value="<?php echo $value; ?>" <?php echo $segmentType === $value ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><input type="time" class="form-control form-control-sm" name="days[<?php echo $weekday; ?>][segments][<?php echo $segmentIndex; ?>][start_time]" value="<?php echo htmlspecialchars($segmentStart, ENT_QUOTES, 'UTF-8'); ?>" aria-label="بداية المقطع"></div>
<div class="col-md-2"><input type="time" class="form-control form-control-sm" name="days[<?php echo $weekday; ?>][segments][<?php echo $segmentIndex; ?>][end_time]" value="<?php echo htmlspecialchars($segmentEnd, ENT_QUOTES, 'UTF-8'); ?>" aria-label="نهاية المقطع"></div>
<div class="col-md-2"><select class="form-select form-select-sm" name="days[<?php echo $weekday; ?>][segments][<?php echo $segmentIndex; ?>][start_day_offset]" aria-label="يوم بداية المقطع"><?php foreach ([0=>'بداية: نفس اليوم',1=>'بداية: التالي',2=>'بداية: بعد يومين'] as $value => $label): ?><option value="<?php echo $value; ?>" <?php echo $segmentStartOffset === $value ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><select class="form-select form-select-sm" name="days[<?php echo $weekday; ?>][segments][<?php echo $segmentIndex; ?>][end_day_offset]" aria-label="يوم نهاية المقطع"><?php foreach ([0=>'نهاية: نفس اليوم',1=>'نهاية: التالي',2=>'نهاية: بعد يومين'] as $value => $label): ?><option value="<?php echo $value; ?>" <?php echo $segmentEndOffset === $value ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div>
<div class="col-md-1"><div class="form-check"><input class="form-check-input" type="checkbox" name="days[<?php echo $weekday; ?>][segments][<?php echo $segmentIndex; ?>][counts_required_minutes]" <?php echo $segmentCounts ? 'checked' : ''; ?> id="segmentCounts<?php echo $weekday . '_' . $segmentIndex; ?>"><label class="form-check-label small" for="segmentCounts<?php echo $weekday . '_' . $segmentIndex; ?>">يحتسب</label></div></div>
<div class="col-md-1"><button type="button" class="btn btn-secondary btn-sm js-remove-schedule-segment" title="إزالة المقطع"><i class="fas fa-times"></i></button></div>
</div>
<?php endfor; ?>
<div class="alert alert-warning py-2 d-none js-segment-limit" role="status"><i class="fas fa-triangle-exclamation me-1"></i>الحد الأقصى 20 مقطعًا لليوم الواحد.</div>
<button type="button" class="btn btn-outline-primary btn-sm js-add-schedule-segment" data-weekday="<?php echo $weekday; ?>"><i class="fas fa-plus-circle me-1"></i>إضافة مقطع</button>
<div class="form-text">تُحسب الدقائق المطلوبة تلقائيًا من المقاطع المحددة «يحتسب». اجعل نهاية آخر مقطع في اليوم التالي للوردية الليلية.</div>
</div></details>
</td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<div class="d-flex justify-content-end mt-3"><button type="submit" name="save_schedule_policy_draft" class="btn <?php echo !empty($oldPolicyInput['edit_version_id']) ? 'btn-primary' : 'btn-success'; ?>"><i class="fas fa-save me-1"></i><?php echo !empty($oldPolicyInput['edit_version_id']) ? 'حفظ التعديلات' : 'حفظ المسودة'; ?></button></div></form></div></div>

<template id="scheduleSegmentTemplate">
<div class="row g-2 mb-2 schedule-segment-row" data-segment-index="__INDEX__">
<div class="col-md-2"><select class="form-select form-select-sm" name="days[__DAY__][segments][__INDEX__][segment_type]" aria-label="نوع المقطع"><option value="work">عمل</option><option value="paid_break">استراحة مدفوعة</option><option value="unpaid_break">استراحة غير مدفوعة</option><option value="on_call">استدعاء</option><option value="overtime">وقت إضافي</option></select></div>
<div class="col-md-2"><input type="time" class="form-control form-control-sm" name="days[__DAY__][segments][__INDEX__][start_time]" aria-label="بداية المقطع"></div>
<div class="col-md-2"><input type="time" class="form-control form-control-sm" name="days[__DAY__][segments][__INDEX__][end_time]" aria-label="نهاية المقطع"></div>
<div class="col-md-2"><select class="form-select form-select-sm" name="days[__DAY__][segments][__INDEX__][start_day_offset]" aria-label="يوم بداية المقطع"><option value="0">بداية: نفس اليوم</option><option value="1">بداية: التالي</option><option value="2">بداية: بعد يومين</option></select></div>
<div class="col-md-2"><select class="form-select form-select-sm" name="days[__DAY__][segments][__INDEX__][end_day_offset]" aria-label="يوم نهاية المقطع"><option value="0">نهاية: نفس اليوم</option><option value="1">نهاية: التالي</option><option value="2">نهاية: بعد يومين</option></select></div>
<div class="col-md-1"><div class="form-check"><input class="form-check-input" type="checkbox" name="days[__DAY__][segments][__INDEX__][counts_required_minutes]" id="segmentCounts__DAY_____INDEX__"><label class="form-check-label small" for="segmentCounts__DAY_____INDEX__">يحتسب</label></div></div>
<div class="col-md-1"><button type="button" class="btn btn-secondary btn-sm js-remove-schedule-segment" title="إزالة المقطع"><i class="fas fa-times"></i></button></div>
</div>
</template>

<div class="modal fade" id="removeScheduleSegmentModal" tabindex="-1" aria-labelledby="removeScheduleSegmentTitle" aria-hidden="true"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete"><div class="modal-header"><h5 class="modal-title" id="removeScheduleSegmentTitle"><i class="fas fa-times-circle me-2"></i>إزالة مقطع الدوام</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-timeline text-danger fa-3x"></i></div><p class="text-center">يحتوي هذا المقطع على بيانات. هل تريد إزالته من المسودة؟</p><div class="alert alert-warning mb-0"><i class="fas fa-info-circle me-2"></i>لن تُحفظ الإزالة إلا عند حفظ المسودة.</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="button" class="btn btn-danger" id="confirmRemoveScheduleSegment"><i class="fas fa-trash me-1"></i>إزالة</button></div></div></div></div>

<div class="card shadow admin-card-surface mb-4"><div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="fas fa-calendar-day me-2"></i>إضافة استثناء تقويم</h5></div><div class="card-body"><form method="POST" class="row g-3 align-items-end"><?php echo csrfField(); ?><input type="hidden" name="idempotency_key" value="<?php echo $calendarIdempotency; ?>">
<div class="col-md-2"><label class="form-label" for="calendarDate">التاريخ</label><input id="calendarDate" type="date" class="form-control" name="calendar_date" required></div>
<div class="col-md-2"><label class="form-label" for="exceptionType">النوع</label><select id="exceptionType" class="form-select" name="exception_type"><option value="holiday">عطلة</option><option value="closure">إغلاق</option><option value="partial_day">يوم جزئي</option><option value="makeup_day">يوم بديل</option><option value="override">تطبيق سياسة بديلة</option></select></div>
<div class="col-md-2"><label class="form-label" for="exceptionScope">النطاق</label><select id="exceptionScope" class="form-select" name="scope_type"><option value="global">عام</option><option value="org_unit">قوة/وحدة</option><option value="job_title">مسمى</option><option value="group">مجموعة</option><option value="staff">عامل</option></select></div>
<div class="col-md-2"><label class="form-label" for="exceptionScopeSearch">بحث النطاق</label><input id="exceptionScopeSearch" type="search" class="form-control" placeholder="اكتب الاسم أو الكود" autocomplete="off"></div>
<div class="col-md-4"><label class="form-label" for="exceptionScopeId">الجهة أو المسمى أو المجموعة أو العامل</label><select id="exceptionScopeId" class="form-select js-scope-option-picker" name="scope_id"><option value="">اختر نطاقًا مسمى</option><?php foreach ($scopeOptions as $scopeType => $options): foreach ($options as $option): $optionId = (int) ($option['id'] ?? 0); ?><option value="<?php echo $optionId; ?>" data-scope-type="<?php echo htmlspecialchars((string) $scopeType, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) ($option['label'] ?? ('#' . $optionId)), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; endforeach; ?></select></div>
<div class="col-md-4"><label class="form-label" for="exceptionReason">السبب</label><input id="exceptionReason" class="form-control" name="reason" maxlength="1000" required></div>
<div class="col-md-4"><label class="form-label" for="exceptionPolicyVersion">سياسة الدوام البديلة</label><select id="exceptionPolicyVersion" class="form-select" name="schedule_policy_version_id"><option value="">لا تستخدم للعطلة أو الإغلاق</option><?php foreach ($policies as $policy): $exceptionVersionId = (int) ($policy['version_id'] ?? $policy['id'] ?? 0); if ($exceptionVersionId <= 0 || (string) ($policy['state'] ?? '') !== 'published') { continue; } ?><option value="<?php echo $exceptionVersionId; ?>"><?php echo htmlspecialchars((string) ($policy['name'] ?? $policy['code'] ?? ('نسخة ' . $exceptionVersionId)), ENT_QUOTES, 'UTF-8'); ?> — v<?php echo (int) ($policy['version_no'] ?? 0); ?></option><?php endforeach; ?></select><div class="form-text">مطلوبة لليوم الجزئي واليوم البديل وتطبيق سياسة بديلة.</div></div>
<div class="col-md-2"><label class="form-label" for="exceptionPriority">الأولوية</label><input id="exceptionPriority" type="number" min="0" max="65535" class="form-control" name="priority" value="0"></div>
<div class="col-12 d-flex justify-content-end"><button type="submit" name="save_calendar_exception" class="btn btn-success"><i class="fas fa-plus-circle me-1"></i>إضافة الاستثناء</button></div></form></div></div>

<div class="admin-list-surface"><div class="admin-table-wrap"><table class="table table-hover table-striped admin-data-table"><thead><tr><th>التاريخ</th><th>النوع</th><th>النطاق</th><th>السبب</th><th>الحالة</th><th>الإجراء</th></tr></thead><tbody><?php if ($calendarExceptions === []): ?><tr><td colspan="6" class="text-center text-muted py-4">لا توجد استثناءات في الفترة الحالية.</td></tr><?php else: foreach ($calendarExceptions as $exception): $calendarExceptionId = (int) ($exception['id'] ?? 0); $calendarLockVersion = (int) ($exception['lock_version'] ?? 0); $calendarExceptionLabel = trim((string) ($exception['calendar_date'] ?? '') . ' — ' . (string) ($exception['reason'] ?? '')); ?><tr><td><?php echo htmlspecialchars((string) ($exception['calendar_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string) ($exception['exception_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string) ($exception['scope_label'] ?? $exception['scope_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string) ($exception['reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string) ($exception['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td><td><?php if (($exception['status'] ?? '') === 'active' && $calendarExceptionId > 0 && $calendarLockVersion > 0): ?><button type="button" class="btn btn-action-pills btn-deactivate" data-bs-toggle="modal" data-bs-target="#retireCalendarExceptionModal" data-exception-id="<?php echo $calendarExceptionId; ?>" data-lock-version="<?php echo $calendarLockVersion; ?>" data-calendar-label="<?php echo htmlspecialchars($calendarExceptionLabel, ENT_QUOTES, 'UTF-8'); ?>" title="إيقاف الاستثناء"><i class="fas fa-ban"></i></button><?php else: ?><span class="text-muted">—</span><?php endif; ?></td></tr><?php endforeach; endif; ?></tbody></table></div></div>

<div class="modal fade" id="retireCalendarExceptionModal" tabindex="-1" aria-labelledby="retireCalendarExceptionTitle" aria-hidden="true"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium admin-modal-delete"><form method="POST"><?php echo csrfField(); ?><input type="hidden" name="idempotency_key" value="<?php echo $calendarRetireIdempotency; ?>"><input type="hidden" name="exception_id" id="retireCalendarExceptionId" value=""><input type="hidden" name="expected_lock_version" id="retireCalendarExceptionLockVersion" value=""><div class="modal-header"><h5 class="modal-title" id="retireCalendarExceptionTitle"><i class="fas fa-ban me-2"></i>إيقاف استثناء تقويم</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-calendar-xmark text-danger fa-3x"></i></div><p class="text-center">هل تريد إيقاف الاستثناء <span class="fw-bold text-primary" id="retireCalendarExceptionLabel"></span>؟</p><div class="alert alert-warning mb-0"><i class="fas fa-info-circle me-2"></i>لن يُحذف السجل؛ سيُحفظ في التاريخ ولن يؤثر بعد الآن في احتساب الحضور.</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" name="retire_calendar_exception" class="btn btn-danger"><i class="fas fa-ban me-1"></i>إيقاف الاستثناء</button></div></form></div></div></div>

<div class="modal fade" id="publishSchedulePolicyModal" tabindex="-1" aria-labelledby="publishSchedulePolicyTitle" aria-hidden="true"><div class="modal-dialog"><div class="modal-content admin-modal admin-modal-premium"><form method="POST"><?php echo csrfField(); ?><input type="hidden" name="idempotency_key" value="<?php echo $publishIdempotency; ?>"><input type="hidden" name="version_id" id="publishSchedulePolicyVersion" value=""><div class="modal-header"><h5 class="modal-title" id="publishSchedulePolicyTitle"><i class="fas fa-check-circle me-2"></i>نشر سياسة الدوام</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div><div class="modal-body"><div class="text-center mb-3"><i class="fas fa-calendar-check text-success fa-3x"></i></div><p class="text-center">هل راجعت أثر السياسة <span class="fw-bold text-primary" id="publishSchedulePolicyName"></span> وتريد نشرها؟</p><div class="alert alert-warning"><i class="fas fa-info-circle me-2"></i>النسخة المنشورة لا تعدل؛ أي تغيير لاحق ينشئ نسخة جديدة.</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>إلغاء</button><button type="submit" name="publish_schedule_policy" class="btn btn-primary"><i class="fas fa-check me-1"></i>نشر</button></div></form></div></div></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var publishModal = document.getElementById('publishSchedulePolicyModal');
    if (publishModal) publishModal.addEventListener('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        document.getElementById('publishSchedulePolicyVersion').value = trigger ? trigger.getAttribute('data-version-id') : '';
        document.getElementById('publishSchedulePolicyName').textContent = trigger ? trigger.getAttribute('data-policy-name') : '';
    });

    var retireCalendarModal = document.getElementById('retireCalendarExceptionModal');
    if (retireCalendarModal) retireCalendarModal.addEventListener('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        document.getElementById('retireCalendarExceptionId').value = trigger ? trigger.getAttribute('data-exception-id') : '';
        document.getElementById('retireCalendarExceptionLockVersion').value = trigger ? trigger.getAttribute('data-lock-version') : '';
        document.getElementById('retireCalendarExceptionLabel').textContent = trigger ? trigger.getAttribute('data-calendar-label') : '';
    });

    function bindScopePicker(typeId, searchId, pickerId) {
        var type = document.getElementById(typeId);
        var search = document.getElementById(searchId);
        var picker = document.getElementById(pickerId);
        if (!type || !search || !picker) return;

        function refreshScopeOptions(clearMismatchedSelection) {
            var selectedType = type.value;
            var query = search.value.trim().toLocaleLowerCase('ar');
            var globalScope = selectedType === 'global';
            Array.from(picker.options).forEach(function (option, index) {
                if (index === 0) return;
                var matchesType = option.getAttribute('data-scope-type') === selectedType;
                var matchesSearch = query === '' || option.textContent.toLocaleLowerCase('ar').includes(query);
                option.disabled = !matchesType;
                option.hidden = !matchesType || !matchesSearch;
            });
            if (clearMismatchedSelection && picker.selectedOptions.length
                && picker.selectedOptions[0].getAttribute('data-scope-type') !== selectedType) {
                picker.value = '';
            }
            picker.disabled = globalScope;
            picker.required = !globalScope;
            search.disabled = globalScope;
            if (globalScope) {
                picker.value = '';
                search.value = '';
            }
        }

        type.addEventListener('change', function () { refreshScopeOptions(true); });
        search.addEventListener('input', function () { refreshScopeOptions(false); });
        refreshScopeOptions(false);
    }

    bindScopePicker('policyScope', 'policyScopeSearch', 'policyScopeId');
    bindScopePicker('exceptionScope', 'exceptionScopeSearch', 'exceptionScopeId');

    var segmentTemplate = document.getElementById('scheduleSegmentTemplate');
    var removeSegmentElement = document.getElementById('removeScheduleSegmentModal');
    var removeSegmentModal = removeSegmentElement ? new bootstrap.Modal(removeSegmentElement) : null;
    var pendingSegmentRow = null;

    function segmentHasData(row) {
        var start = row.querySelector('input[name$="[start_time]"]');
        var end = row.querySelector('input[name$="[end_time]"]');
        var counts = row.querySelector('input[name$="[counts_required_minutes]"]');
        var type = row.querySelector('select[name$="[segment_type]"]');
        var startOffset = row.querySelector('select[name$="[start_day_offset]"]');
        var endOffset = row.querySelector('select[name$="[end_day_offset]"]');
        return Boolean((start && start.value) || (end && end.value) || (counts && counts.checked)
            || (type && type.value !== 'work') || (startOffset && startOffset.value !== '0')
            || (endOffset && endOffset.value !== '0'));
    }

    document.addEventListener('click', function (event) {
        var addButton = event.target.closest('.js-add-schedule-segment');
        if (addButton && segmentTemplate) {
            var container = addButton.closest('.schedule-segment-container');
            var rows = Array.from(container.querySelectorAll('.schedule-segment-row'));
            var limitMessage = container.querySelector('.js-segment-limit');
            if (rows.length >= 20) {
                limitMessage.classList.remove('d-none');
                return;
            }
            limitMessage.classList.add('d-none');
            var nextIndex = rows.reduce(function (maximum, row) {
                return Math.max(maximum, Number(row.getAttribute('data-segment-index')) || 0);
            }, -1) + 1;
            var html = segmentTemplate.innerHTML
                .replaceAll('__DAY__', addButton.getAttribute('data-weekday'))
                .replaceAll('__INDEX__', String(nextIndex));
            limitMessage.insertAdjacentHTML('beforebegin', html);
            return;
        }

        var removeButton = event.target.closest('.js-remove-schedule-segment');
        if (!removeButton) return;
        var row = removeButton.closest('.schedule-segment-row');
        if (!segmentHasData(row)) {
            row.remove();
            return;
        }
        pendingSegmentRow = row;
        if (removeSegmentModal) removeSegmentModal.show();
    });

    var confirmRemove = document.getElementById('confirmRemoveScheduleSegment');
    if (confirmRemove) confirmRemove.addEventListener('click', function () {
        if (pendingSegmentRow) pendingSegmentRow.remove();
        pendingSegmentRow = null;
        if (removeSegmentModal) removeSegmentModal.hide();
    });
});
</script>
<?php require_once '../includes/admin_footer.php'; ?>
