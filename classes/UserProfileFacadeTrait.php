<?php

declare(strict_types=1);

trait UserProfileFacadeTrait
{
    public static function splitDisplayName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return [
            'first_name_ar' => $parts[0] ?? null,
            'second_name_ar' => $parts[1] ?? null,
            'third_name_ar' => $parts[2] ?? null,
            'fourth_name_ar' => $parts[3] ?? null,
            'family_name_ar' => count($parts) > 4 ? implode(' ', array_slice($parts, 4)) : null,
        ];
    }

    public static function joinNameParts(array $parts): string
    {
        $parts = array_values(array_filter(array_map(static fn($part): string => trim((string) $part), $parts)));
        return preg_replace('/\s+/u', ' ', implode(' ', $parts));
    }

    public function getStaffProfile($userId) { return $this->profileStore->getStaffProfile($userId); }
    public function saveStaffProfile($userId, $data) { return $this->profileStore->saveStaffProfile($userId, $data); }
    public function readAllStaffWithProfiles() { return $this->profileStore->readAllStaffWithProfiles(); }
    public function readStaffWithProfilesPaginated(int $limit, int $offset, int &$totalCount, array $filters = [], string $orderBy = 'name', string $orderDirection = 'asc'): array { return $this->profileStore->readStaffWithProfilesPaginated($limit, $offset, $totalCount, $filters, $orderBy, $orderDirection); }
    public function getStaffListFilterOptions(): array { return $this->profileStore->getStaffListFilterOptions(); }
    public function deleteStaffProfileImage($userId) { return $this->profileStore->deleteStaffProfileImage($userId); }
    public static function normalizeArabicName($name) { return UserProfileStore::normalizeArabicName($name); }
    public static function buildSearchKey($first, $second, $third, $fourth, $family) { return UserProfileStore::buildSearchKey($first, $second, $third, $fourth, $family); }
    public static function calculateAgeFromOctober($birthDate) { return UserProfileStore::calculateAgeFromOctober($birthDate); }
    public static function calculateCurrentAge($birthDate) { return UserProfileStore::calculateCurrentAge($birthDate); }
    public function generateStudentCode() { return $this->profileStore->generateStudentCode(); }
    public function generateEmployeeCode() { return $this->profileStore->generateEmployeeCode(); }
    public function generateTeacherCode() { return $this->profileStore->generateTeacherCode(); }
    public function getStudentProfile($userId) { return $this->profileStore->getStudentProfile($userId); }
    public function saveStudentProfile($userId, $data) { return $this->profileStore->saveStudentProfile($userId, $data); }

    public function ensureStudentProfile(int $userId, string $displayName): bool
    {
        return (bool) ($this->profileStore->getStudentProfile($userId)
            ?: $this->profileStore->saveStudentProfile($userId, self::splitDisplayName($displayName)));
    }

    public function getStudentGuardians($studentId) { return $this->profileStore->getStudentGuardians($studentId); }
    public function saveStudentGuardian($data) { return $this->profileStore->saveStudentGuardian($data); }
    public function deleteStudentGuardian($guardianId, $studentId) { return $this->profileStore->deleteStudentGuardian($guardianId, $studentId); }
    public function findPotentialSiblings($userId, $secondNameAr, $thirdNameAr, $familyNameAr) { return $this->profileStore->findPotentialSiblings($userId, $secondNameAr, $thirdNameAr, $familyNameAr); }
    public function findPotentialKinship($userId, $secondNameAr, $thirdNameAr, $familyNameAr) { return $this->profileStore->findPotentialKinship($userId, $secondNameAr, $thirdNameAr, $familyNameAr); }
    public function searchStudentsForSibling($userId, $searchTerm) { return $this->profileStore->searchStudentsForSibling($userId, $searchTerm); }
    public function linkSiblings($studentId, $siblingId, $relationship = 'brother') { return $this->profileStore->linkSiblings($studentId, $siblingId, $relationship); }
    public function unlinkSiblings($studentId, $siblingId) { return $this->profileStore->unlinkSiblings($studentId, $siblingId); }
    public function getStudentSiblings($studentId) { return $this->profileStore->getStudentSiblings($studentId); }
    public function logStudentTransfer($studentId, $fromClassId, $toClassId, $reason = '', $transferredBy = null) { return $this->profileStore->logStudentTransfer($studentId, $fromClassId, $toClassId, $reason, $transferredBy); }
    public function getStudentTransfers($studentId) { return $this->profileStore->getStudentTransfers($studentId); }
    public function getStudentAcademicHistory($studentId) { return $this->profileStore->getStudentAcademicHistory($studentId); }
    public function getStudentsWithProfiles($classId = null) { return $this->profileStore->getStudentsWithProfiles($classId); }
}
