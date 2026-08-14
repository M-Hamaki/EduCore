<?php

return static function (PDO $db): void {
    $db->exec('DROP EVENT IF EXISTS `EduCore_AutoBackup_Event`');
    $db->exec('DROP PROCEDURE IF EXISTS `EduCore_AutoBackup_Do`');
};
