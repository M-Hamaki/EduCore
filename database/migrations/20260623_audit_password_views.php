<?php

return static function (PDO $db): void {
    $db->exec("ALTER TABLE audit_logs MODIFY action ENUM('create','update','delete','restore','view') NOT NULL");
};
