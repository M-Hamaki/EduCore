<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff;

use ActivityLog;
use ClassRoom;
use InvalidArgumentException;
use PDO;
use ProfileAttachmentStorage;
use ProfileInputValidator;
use RuntimeException;
use Throwable;
use StaffEmploymentLifecycleService;
use UndoManager;
use User;

final class StaffListPageQuery
{
    private const ACTIVITY_PER_PAGE = 40;

    private User $users;

    public function __construct(PDO $db, User $users)
    {
        ActivityLog::setDb($db);
        $this->users = $users;
    }

    public function load(array $query): array
    {
        $action = $query['action'] ?? '';
        $listMode = $action !== 'view';
        $mainTab = ($query['main_tab'] ?? 'staff') === 'activity_log'
            ? 'activity_log'
            : 'staff';
        $filters = ['target_types' => ['staff', 'teacher', 'specialist']];
        foreach ([
            'log_action' => 'action',
            'log_search' => 'search',
            'log_from' => 'date_from',
            'log_to' => 'date_to',
        ] as $input => $filter) {
            if (!empty($query[$input])) {
                $filters[$filter] = $query[$input];
            }
        }

        $page = max(1, (int) ($query['log_page'] ?? 1));
        $offset = ($page - 1) * self::ACTIVITY_PER_PAGE;
        $total = 0;
        $pages = 1;
        if ($listMode && $mainTab === 'activity_log') {
            $total = ActivityLog::countLogs($filters);
            $pages = max(1, (int) ceil($total / self::ACTIVITY_PER_PAGE));
        }
        if ($page > $pages && $pages > 0) {
            $page = $pages;
            $offset = ($page - 1) * self::ACTIVITY_PER_PAGE;
        }

        $staffTotal = 0;
        if ($listMode) {
            $this->users->readStaffWithProfilesPaginated(0, 0, $staffTotal);
        }
        $filterOptions = $listMode ? $this->users->getStaffListFilterOptions() : ['job_titles' => [], 'forces' => []];
        $canonicalJobTitles = [];
        foreach ($filterOptions['job_titles'] as $jobTitle) {
            $canonical = StaffEmploymentLifecycleService::canonicalJobTitle($jobTitle);
            if ($canonical !== null) {
                $canonicalJobTitles[$canonical] = true;
            }
        }
        $filterOptions['job_titles'] = array_keys($canonicalJobTitles);
        sort($filterOptions['job_titles'], SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'action' => $action,
            'main_tab' => $mainTab,
            'activity_filters' => $filters,
            'activity_page' => $page,
            'activity_per_page' => self::ACTIVITY_PER_PAGE,
            'activity_offset' => $offset,
            'activity_total' => $total,
            'activity_pages' => $pages,
            'activity_logs' => $listMode && $mainTab === 'activity_log'
                ? ActivityLog::getLogs($filters, self::ACTIVITY_PER_PAGE, $offset)
                : [],
            'staff' => [],
            'staff_total' => $staffTotal,
            'staff_server_side' => $listMode,
            'filter_job_titles' => $filterOptions['job_titles'],
            'filter_forces' => $filterOptions['forces'],
        ];
    }
}
