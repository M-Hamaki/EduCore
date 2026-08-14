<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff;

use EduCore\Modules\Staff\Presentation\StaffListDataTablePresenter;
use StaffEmploymentLifecycleService;
use User;

final class StaffListDataTableQuery
{
    public function __construct(private User $users, private StaffListDataTablePresenter $presenter) {}

    public function load(array $request): array
    {
        $draw = max(0, (int) ($request['draw'] ?? 0));
        $requestedLength = (int) ($request['length'] ?? 50);
        $length = $requestedLength === -1 ? PHP_INT_MAX : min(500, max(10, $requestedLength));
        $start = max(0, (int) ($request['start'] ?? 0));
        $search = trim((string) ($request['search']['value'] ?? ''));
        $jobTitle = trim((string) ($request['job_title'] ?? ''));
        $filters = [
            'job_title' => $jobTitle,
            'job_titles' => StaffEmploymentLifecycleService::jobTitleFilterValues($jobTitle),
            'force' => $request['force'] ?? '',
            'work_status' => $request['work_status'] ?? '',
            'search' => $search,
        ];
        $column = (int) ($request['order'][0]['column'] ?? 3);
        $direction = strtolower((string) ($request['order'][0]['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $filtered = 0;
        $staff = $this->users->readStaffWithProfilesPaginated($length, $start, $filtered, $filters, $this->order($column), $direction);
        $total = $filtered;
        if ($search !== '') {
            $unfiltered = 0;
            $this->users->readStaffWithProfilesPaginated(0, 0, $unfiltered, [
                'job_title' => $filters['job_title'],
                'job_titles' => $filters['job_titles'],
                'force' => $filters['force'],
                'work_status' => $filters['work_status'],
            ]);
            $total = $unfiltered;
        }
        return ['draw' => $draw, 'recordsTotal' => $total, 'recordsFiltered' => $filtered, 'data' => $this->presenter->rows($staff, $start)];
    }

    private function order(int $column): string
    {
        return [0 => 'id', 1 => 'biometric_id', 2 => 'employee_code', 3 => 'name', 4 => 'job_title', 5 => 'phone_mobile', 6 => 'national_id', 69 => 'current_work_status'][$column] ?? 'name';
    }
}
