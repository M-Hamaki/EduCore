<?php
require_once '../includes/session_config.php';
require_once '../classes/utilities.php';

Utilities::validateSession('teacher');

header('Location: assessment_marks.php');
exit();
