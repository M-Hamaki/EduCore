<?php

declare(strict_types=1);

namespace EduCore\Modules\BehaviorEvaluation;

use EduCore\Modules\Staff\SpecialistAcademicScopeService;
use PDO;
use RuntimeException;

require_once dirname(__DIR__) . '/Staff/SpecialistAcademicScopeService.php';
require_once dirname(__DIR__, 3) . '/classes/StaffEmploymentLifecycleService.php';

final class SpecialistEvaluationReadService
{
    public function __construct(private PDO $db, private SpecialistAcademicScopeService $scope)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function studentSummaries(int $specialistId, int $yearId, ?int $classId = null): array
    {
        $classIds = $this->classIds($specialistId, $yearId, $classId);
        if ($classIds === []) return [];
        $marks = implode(',', array_fill(0, count($classIds), '?'));
        $stmt = $this->db->prepare("SELECT u.id, u.name, sp.student_code, c.name AS class_name, g.grade_name,
            COUNT(e.id) AS evaluation_count,
            COALESCE(SUM(CASE WHEN e.custom_points IS NOT NULL THEN e.custom_points
                WHEN et.type='positive' THEN et.points ELSE -et.points END),0) AS total_points
            FROM student_enrollments se
            JOIN users u ON u.id=se.student_id AND u.role='student' AND u.status='active' AND u.deleted_at IS NULL
            LEFT JOIN student_profiles sp ON sp.user_id=u.id
            JOIN classes c ON c.id=se.class_id
            LEFT JOIN grades g ON g.id=c.grade_id
            LEFT JOIN evaluations e ON e.student_id=u.id AND e.class_id=se.class_id AND e.deleted_at IS NULL
            LEFT JOIN evaluation_types et ON et.id=e.evaluation_type_id
            WHERE se.academic_year_id=? AND se.enrollment_status='enrolled' AND se.class_id IN ({$marks})
            GROUP BY u.id,u.name,sp.student_code,c.name,g.grade_name,g.grade_order,c.display_order
            ORDER BY g.grade_order,c.display_order,u.name");
        $stmt->execute(array_merge([$yearId], $classIds));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    public function records(int $specialistId, int $yearId, array $filters = []): array
    {
        $classId = max(0, (int)($filters['class_id'] ?? 0));
        $classIds = $this->classIds($specialistId, $yearId, $classId ?: null);
        if ($classIds === []) return [];
        $marks = implode(',', array_fill(0, count($classIds), '?'));
        $where = ["e.class_id IN ({$marks})", "se.academic_year_id=?", "se.enrollment_status='enrolled'", 'se.class_id=e.class_id', 'e.deleted_at IS NULL'];
        $params = array_merge($classIds, [$yearId]);
        foreach (['student_id'=>'e.student_id','teacher_id'=>'e.teacher_id','evaluation_type_id'=>'e.evaluation_type_id'] as $key=>$column) {
            $id = max(0, (int)($filters[$key] ?? 0));
            if ($id > 0) {
                if ($key === 'student_id') $this->scope->assertStudentAllowed($specialistId, $yearId, $id);
                if ($key === 'teacher_id' && !in_array($id, $this->scope->allowedTeacherIds($specialistId, $yearId), true)) throw new RuntimeException('المعلم المطلوب خارج نطاق الأخصائي.');
                $where[] = "{$column}=?"; $params[] = $id;
            }
        }
        foreach (['date_from'=>'>=','date_to'=>'<='] as $key=>$operator) {
            $value = (string)($filters[$key] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $where[] = "e.date_created {$operator} ?";
                $params[] = $value . ($key === 'date_from' ? ' 00:00:00' : ' 23:59:59');
            }
        }
        $stmt = $this->db->prepare("SELECT e.id,e.date_created,e.reason,e.custom_points,
            s.id student_id,s.name student_name,t.id teacher_id,t.name teacher_name,
            c.id class_id,c.name class_name,g.grade_name,et.id evaluation_type_id,et.name evaluation_name,et.type,et.points,
            CASE WHEN e.custom_points IS NOT NULL THEN e.custom_points WHEN et.type='positive' THEN et.points ELSE -et.points END display_points
            FROM evaluations e
            JOIN student_enrollments se ON se.student_id=e.student_id
            JOIN users s ON s.id=e.student_id AND s.role='student'
            JOIN users t ON t.id=e.teacher_id
            JOIN classes c ON c.id=e.class_id
            LEFT JOIN grades g ON g.id=c.grade_id
            JOIN evaluation_types et ON et.id=e.evaluation_type_id
            WHERE ".implode(' AND ', $where)."
            ORDER BY e.date_created DESC LIMIT 500");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    public function teacherSummaries(int $specialistId, int $yearId): array
    {
        $teacherIds = $this->scope->allowedTeacherIds($specialistId, $yearId);
        $classIds = $this->scope->allowedClassIdsForSpecialist($specialistId, $yearId);
        if ($teacherIds === [] || $classIds === []) return [];
        $teacherMarks = implode(',', array_fill(0, count($teacherIds), '?'));
        $classMarks = implode(',', array_fill(0, count($classIds), '?'));
        $stmt = $this->db->prepare("SELECT u.id,u.name,sp.employee_code,sp.job_title,
            COUNT(e.id) evaluation_count,
            SUM(CASE WHEN et.type='positive' OR e.custom_points>0 THEN 1 ELSE 0 END) positive_count,
            SUM(CASE WHEN et.type='negative' OR e.custom_points<0 THEN 1 ELSE 0 END) negative_count
            FROM users u LEFT JOIN staff_profiles sp ON sp.user_id=u.id
            LEFT JOIN evaluations e ON e.teacher_id=u.id AND e.class_id IN ({$classMarks}) AND e.deleted_at IS NULL
            LEFT JOIN evaluation_types et ON et.id=e.evaluation_type_id
            WHERE EXISTS (
                SELECT 1 FROM user_role_assignments ura
                WHERE ura.user_id = u.id AND ura.role_key = 'teacher' AND ura.status = 'active'
            ) AND u.id IN ({$teacherMarks})
            GROUP BY u.id,u.name,sp.employee_code,sp.job_title ORDER BY evaluation_count DESC,u.name");
        $stmt->execute(array_merge($classIds, $teacherIds));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['job_title'] = \StaffEmploymentLifecycleService::canonicalJobTitle($row['job_title'] ?? null);
        }
        unset($row);
        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    public function evaluationTypes(): array
    {
        $stmt = $this->db->query("SELECT id,name,type,points FROM evaluation_types ORDER BY type,name");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array{summary:array<string,int|float>,top_students:array<int,array<string,mixed>>,classes:array<int,array<string,mixed>>,teachers:array<int,array<string,mixed>>,types:array<int,array<string,mixed>>} */
    public function analytics(int $specialistId, int $yearId): array
    {
        $classIds=$this->scope->allowedClassIdsForSpecialist($specialistId,$yearId);
        if($classIds===[])return ['summary'=>['total_evaluations'=>0,'positive_points'=>0,'negative_points'=>0,'net_points'=>0,'students'=>0],'top_students'=>[],'classes'=>[],'teachers'=>[],'types'=>[]];
        $marks=implode(',',array_fill(0,count($classIds),'?'));
        $points="CASE WHEN e.custom_points IS NOT NULL THEN e.custom_points WHEN et.type='positive' THEN et.points ELSE -et.points END";
        $from=" FROM evaluations e
            JOIN student_enrollments se ON se.student_id=e.student_id AND se.class_id=e.class_id AND se.academic_year_id=? AND se.enrollment_status='enrolled'
            JOIN evaluation_types et ON et.id=e.evaluation_type_id";
        $where=" WHERE e.deleted_at IS NULL AND e.class_id IN ({$marks})";
        $params=array_merge([$yearId],$classIds);

        $summaryStmt=$this->db->prepare("SELECT COUNT(*) total_evaluations,COUNT(DISTINCT e.student_id) students,
            COALESCE(SUM(CASE WHEN ({$points})>0 THEN ({$points}) ELSE 0 END),0) positive_points,
            COALESCE(SUM(CASE WHEN ({$points})<0 THEN ABS({$points}) ELSE 0 END),0) negative_points,
            COALESCE(SUM({$points}),0) net_points {$from}{$where}");
        $summaryStmt->execute($params);$summary=$summaryStmt->fetch(PDO::FETCH_ASSOC)?:[];

        $topStmt=$this->db->prepare("SELECT s.name student_name,c.name class_name,COUNT(e.id) evaluation_count,COALESCE(SUM({$points}),0) total_points
            {$from} JOIN users s ON s.id=e.student_id JOIN classes c ON c.id=e.class_id {$where}
            GROUP BY e.student_id,s.name,c.name ORDER BY total_points DESC LIMIT 10");
        $topStmt->execute($params);
        $classStmt=$this->db->prepare("SELECT c.name class_name,COUNT(e.id) evaluation_count,COALESCE(SUM({$points}),0) total_points
            {$from} JOIN classes c ON c.id=e.class_id {$where} GROUP BY e.class_id,c.name ORDER BY total_points DESC");
        $classStmt->execute($params);
        $teacherStmt=$this->db->prepare("SELECT t.name teacher_name,COUNT(e.id) evaluation_count
            {$from} JOIN users t ON t.id=e.teacher_id {$where} GROUP BY e.teacher_id,t.name ORDER BY evaluation_count DESC");
        $teacherStmt->execute($params);
        $typeStmt=$this->db->prepare("SELECT et.name evaluation_name,et.type,COUNT(e.id) usage_count {$from}{$where}
            GROUP BY et.id,et.name,et.type ORDER BY usage_count DESC");
        $typeStmt->execute($params);
        return [
            'summary'=>['total_evaluations'=>(int)($summary['total_evaluations']??0),'positive_points'=>(int)($summary['positive_points']??0),'negative_points'=>(int)($summary['negative_points']??0),'net_points'=>(int)($summary['net_points']??0),'students'=>(int)($summary['students']??0)],
            'top_students'=>$topStmt->fetchAll(PDO::FETCH_ASSOC)?:[],
            'classes'=>$classStmt->fetchAll(PDO::FETCH_ASSOC)?:[],
            'teachers'=>$teacherStmt->fetchAll(PDO::FETCH_ASSOC)?:[],
            'types'=>$typeStmt->fetchAll(PDO::FETCH_ASSOC)?:[],
        ];
    }

    /** @return array<int,int> */
    private function classIds(int $specialistId, int $yearId, ?int $classId): array
    {
        $ids = $this->scope->allowedClassIdsForSpecialist($specialistId, $yearId);
        if ($classId !== null && $classId > 0) {
            $this->scope->assertClassAllowed($specialistId, $yearId, $classId);
            return [$classId];
        }
        return $ids;
    }
}
