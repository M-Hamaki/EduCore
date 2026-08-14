<?php if ($edit_class): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const stageSelect = document.getElementById('recordStageId');
    const gradeSelect = document.getElementById('recordGradeId');
    const classSelect = document.getElementById('recordClassId');
    const dateInput = document.getElementById('recordDate');

    if (stageSelect) {
        stageSelect.value = '<?php echo intval($edit_stage); ?>';
        filterRecordGrades('<?php echo intval($edit_stage); ?>');
    }
    if (gradeSelect) {
        gradeSelect.value = '<?php echo intval($edit_grade); ?>';
        filterRecordClasses('<?php echo intval($edit_grade); ?>');
    }
    if (classSelect) {
        classSelect.value = '<?php echo intval($edit_class); ?>';
    }
    <?php if ($edit_date): ?>
    if (dateInput) {
        dateInput.value = '<?php echo htmlspecialchars($edit_date); ?>';
    }
    <?php endif; ?>
    if (classSelect && classSelect.value) {
        loadStudentsForRecord();
    }
});
</script>
<?php endif; ?>
