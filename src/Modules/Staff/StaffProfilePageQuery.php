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

final class StaffProfilePageQuery
{
    private PDO $db;
    private User $users;
    private StaffEmploymentLifecycleService $employment;

    public function __construct(
        PDO $db,
        User $users,
        ?StaffEmploymentLifecycleService $employment = null
    )
    {
        $this->db = $db;
        $this->users = $users;
        $this->employment = $employment ?? new StaffEmploymentLifecycleService($db);
    }

    public function editData(int $userId): array
    {
        $this->users->id = $userId;
        $this->users->readOneWithoutCredentials();
        $profile = $this->users->getStaffProfile($userId);
        $profile = is_array($profile) ? $profile : [];
        $profile['job_title'] = StaffEmploymentLifecycleService::canonicalJobTitle(
            $profile['job_title'] ?? null
        );

        try {
            $statusStatement = $this->db->prepare(
                "SELECT movement_type, status_after, status_label, status_reason,
                        effective_date, decision_date, decision_no, issuer, contract_type,
                        contract_start, contract_end, job_title, job_grade, department,
                        last_working_day, can_rehire, notes
                 FROM staff_status_history
                 WHERE user_id = ?
                 ORDER BY COALESCE(effective_date, '9999-12-31'), id"
            );
            $statusStatement->execute([$userId]);
            $statusRows = $statusStatement->fetchAll(PDO::FETCH_ASSOC);
            $statusRows = $this->employment->hydrateMissingCurrentSummary($statusRows, $profile);
            $profile['status_history'] = json_encode($statusRows, JSON_UNESCAPED_UNICODE);

            $movementStatement = $this->db->prepare(
                "SELECT movement_type, previous_job_title, new_job_title,
                        previous_job_grade, new_job_grade, previous_department,
                        new_department, previous_contract_type, new_contract_type,
                        decision_date, effective_date, decision_no, issuer, reason, notes
                 FROM staff_job_movements
                 WHERE user_id = ?
                 ORDER BY COALESCE(effective_date, decision_date, '9999-12-31'), id"
            );
            $movementStatement->execute([$userId]);
            $movementRows = $movementStatement->fetchAll(PDO::FETCH_ASSOC);
            if ($movementRows) {
                foreach ($movementRows as &$movementRow) {
                    $movementRow['previous_job_title'] = StaffEmploymentLifecycleService::canonicalJobTitle(
                        $movementRow['previous_job_title'] ?? null
                    );
                    $movementRow['new_job_title'] = StaffEmploymentLifecycleService::canonicalJobTitle(
                        $movementRow['new_job_title'] ?? null
                    );
                }
                unset($movementRow);
                $profile['promotions'] = json_encode($movementRows, JSON_UNESCAPED_UNICODE);
            }
        } catch (Throwable $exception) {
            error_log(
                'Failed to load normalized staff employment history: '
                . $exception->getMessage()
            );
        }

        $attachmentStatement = $this->db->prepare(
            'SELECT * FROM staff_attachments WHERE user_id = ? ORDER BY uploaded_at DESC'
        );
        $attachmentStatement->execute([$userId]);

        return [
            'profile' => $profile,
            'attachments' => $attachmentStatement->fetchAll(PDO::FETCH_ASSOC),
            'extra_phones' => json_decode($profile['extra_phones'] ?? 'null', true) ?? [],
            'extra_data' => json_decode($profile['extra_data'] ?? 'null', true) ?? [],
            'extra_employment_data' => json_decode(
                $profile['extra_employment_data'] ?? 'null',
                true
            ) ?? [],
        ];
    }

    public function viewData(int $userId): array
    {
        $viewUser = new User($this->db);
        $viewUser->id = $userId;
        $viewUser->readOneWithoutCredentials();
        $profile = $viewUser->getStaffProfile($userId);
        if (is_array($profile)) {
            $profile['job_title'] = StaffEmploymentLifecycleService::canonicalJobTitle(
                $profile['job_title'] ?? null
            );
        }

        return [
            'user' => $viewUser,
            'profile' => is_array($profile) ? $profile : null,
        ];
    }
}
