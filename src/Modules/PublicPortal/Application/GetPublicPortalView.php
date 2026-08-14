<?php

declare(strict_types=1);

namespace EduCore\Modules\PublicPortal\Application;

final class GetPublicPortalView
{
    /** @return array{materials_url:string} */
    public function execute(): array
    {
        return [
            'materials_url' => 'materials.php',
        ];
    }
}
