<?php

declare(strict_types=1);

namespace EduCore\Modules\Staff\Contracts;

use DateTimeImmutable;

interface StaffScheduleScopeOptionQuery
{
    /**
     * @return array{org_unit:list<array{id:int,label:string}>,job_title:list<array{id:int,label:string}>,group:list<array{id:int,label:string}>,staff:list<array{id:int,label:string}>}
     */
    public function options(): array;

    public function isSelectable(string $scopeType, ?int $scopeId, DateTimeImmutable $atDate): bool;
}
