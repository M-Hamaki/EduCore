<?php

declare(strict_types=1);

namespace EduCore\Modules\Transport\Infrastructure;

use EduCore\Modules\AcademicStructure\Infrastructure\PdoAcademicYearQuery;
use EduCore\Modules\Operations\Audit\AuditService;
use EduCore\Modules\Students\StudentOperationalGuard;
use EduCore\Modules\Transport\Application\LegacyStudentBusAssignmentService;
use PDO;

final class TransportServiceFactory
{
    public static function legacyStudentBusAssignmentService(PDO $db): LegacyStudentBusAssignmentService
    {
        return new LegacyStudentBusAssignmentService(
            new PdoStudentBusAssignmentRepository($db),
            new StudentOperationalGuard($db),
            new PdoTransportTransactionManager($db),
            new AuditService($db),
            (new PdoAcademicYearQuery($db))->currentId()
        );
    }
}
