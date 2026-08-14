<?php

declare(strict_types=1);

namespace EduCore\Modules\Accounts;

use InvalidArgumentException;

final class SecurePasswordGenerator
{
    public static function generate(int $length = 12): string
    {
        if ($length < 12) {
            throw new InvalidArgumentException('يجب ألا يقل طول كلمة المرور المولدة عن 12 حرفًا.');
        }

        $groups = [
            'abcdefghjkmnpqrstuvwxyz',
            'ABCDEFGHJKMNPQRSTUVWXYZ',
            '23456789',
            '!@#$%*-_',
        ];
        $characters = [];
        foreach ($groups as $group) {
            $characters[] = $group[random_int(0, strlen($group) - 1)];
        }

        $pool = implode('', $groups);
        while (count($characters) < $length) {
            $characters[] = $pool[random_int(0, strlen($pool) - 1)];
        }

        for ($index = count($characters) - 1; $index > 0; $index--) {
            $swapIndex = random_int(0, $index);
            [$characters[$index], $characters[$swapIndex]] = [$characters[$swapIndex], $characters[$index]];
        }

        return implode('', $characters);
    }
}
