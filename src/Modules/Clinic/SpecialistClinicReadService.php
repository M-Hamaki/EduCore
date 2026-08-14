<?php

declare(strict_types=1);

namespace EduCore\Modules\Clinic;

use EduCore\Modules\Staff\SpecialistAcademicScopeService;
use PDO;

require_once dirname(__DIR__) . '/Staff/SpecialistAcademicScopeService.php';

final class SpecialistClinicReadService
{
    public function __construct(private PDO $db, private SpecialistAcademicScopeService $scope)
    {
    }

    /** @return array{classes:array<int,array<string,mixed>>,visits:array<int,array<string,mixed>>} */
    public function visits(int $specialistId, int $yearId, array $filters = []): array
    {
        $classIds = $this->scope->allowedClassIdsForSpecialist($specialistId, $yearId);
        $selectedClass = max(0, (int)($filters['class_id'] ?? 0));
        if ($selectedClass > 0) {
            $this->scope->assertClassAllowed($specialistId, $yearId, $selectedClass);
            $classIds = [$selectedClass];
        }
        $classes = $this->classes($specialistId, $yearId);
        if ($classIds === []) return ['classes'=>$classes,'visits'=>[]];
        $marks=implode(',',array_fill(0,count($classIds),'?'));
        $where=["se.academic_year_id=?","se.enrollment_status='enrolled'","se.class_id IN ({$marks})"];
        $params=array_merge([$yearId],$classIds);
        foreach(['date_from'=>'>=','date_to'=>'<='] as $key=>$operator){
            $value=(string)($filters[$key]??'');
            if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$value)){$where[]="v.visit_at {$operator} ?";$params[]=$value.($key==='date_from'?' 00:00:00':' 23:59:59');}
        }
        $stmt=$this->db->prepare("SELECT v.id,v.visit_at,v.complaint,v.health_condition,v.diagnosis,v.action_taken,v.treatment_taken,v.notes,
            u.id student_id,u.name student_name,sp.student_code,c.name class_name,g.grade_name
            FROM student_clinic_visits v JOIN users u ON u.id=v.student_id AND u.role='student'
            JOIN student_enrollments se ON se.student_id=u.id
            LEFT JOIN student_profiles sp ON sp.user_id=u.id
            JOIN classes c ON c.id=se.class_id LEFT JOIN grades g ON g.id=c.grade_id
            WHERE ".implode(' AND ',$where)." ORDER BY v.visit_at DESC LIMIT 500");
        $stmt->execute($params);
        return ['classes'=>$classes,'visits'=>$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]];
    }

    /** @return array<int,array<string,mixed>> */
    private function classes(int $specialistId,int $yearId):array
    {
        $ids=$this->scope->allowedClassIdsForSpecialist($specialistId,$yearId);
        if($ids===[])return[];
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $stmt=$this->db->prepare("SELECT c.id,c.name,g.grade_name FROM classes c JOIN grades g ON g.id=c.grade_id WHERE c.id IN ({$marks}) ORDER BY g.grade_order,c.display_order,c.name");
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    }
}
