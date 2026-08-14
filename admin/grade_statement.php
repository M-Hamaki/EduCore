<?php
$studentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
header('Location: statements.php?type=grades' . ($studentId ? '&student_id=' . $studentId : ''));
exit;
