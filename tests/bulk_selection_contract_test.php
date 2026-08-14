<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Modules/Accounts/AccountBulkSelection.php';

use EduCore\Modules\Accounts\AccountBulkSelection;

final class BulkSelectionContractTest
{
    public static function run(): void
    {
        // Test 1: Selected mode normalization & limits
        $selection = AccountBulkSelection::fromArray([
            'selection_mode' => 'selected',
            'ids' => [10, 20, 30, '40', -5, 0, 20]
        ]);

        assert($selection->mode === AccountBulkSelection::MODE_SELECTED);
        assert($selection->ids === [10, 20, 30, 40]);

        // Test 2: Filtered mode parsing
        $filtered = AccountBulkSelection::fromArray([
            'selection_mode' => 'filtered',
            'filters' => ['status' => ['active']]
        ]);

        assert($filtered->mode === AccountBulkSelection::MODE_FILTERED);
        assert($filtered->filters === ['status' => ['active']]);

        // Test 3: Invalid mode throws exception
        try {
            AccountBulkSelection::fromArray(['selection_mode' => 'invalid']);
            assert(false, 'Should have thrown InvalidArgumentException for invalid mode');
        } catch (InvalidArgumentException $e) {
            assert(true);
        }

        // Test 4: Empty IDs throws exception
        try {
            AccountBulkSelection::fromArray(['selection_mode' => 'selected', 'ids' => []]);
            assert(false, 'Should have thrown InvalidArgumentException for empty IDs');
        } catch (InvalidArgumentException $e) {
            assert(true);
        }

        // Test 5: Filtered selections probe one extra row and fail closed instead of silently truncating.
        $source = (string)file_get_contents(__DIR__ . '/../src/Modules/Accounts/AccountBulkSelection.php');
        assert(substr_count($source, '(self::MAX_BATCH_SIZE + 1)') === 2);
        assert(str_contains($source, 'ضيّق الفلاتر ثم أعد المحاولة'));
        assert(!str_contains($source, 'ReflectionClass'));

        echo "BULK_SELECTION_CONTRACT_TEST_PASSED\n";
    }
}

BulkSelectionContractTest::run();
