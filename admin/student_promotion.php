<?php
$page_title = "ترحيل الطلاب";
$custom_page_title = true;

require_once '../config/database.php';
require_once '../classes/utilities.php';
require_once '../includes/session_config.php';
Utilities::validateSession('admin');

header('Location: school_settings.php?active_tab=year_setup');
exit();
