<!-- Air Datepicker JS يُحمَّل الآن مركزياً عبر includes/admin_footer.php -->
<!-- Moment JS & Moment Hijri Plugin -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.4/moment.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/moment-hijri@2.1.2/moment-hijri.min.js"></script>
<script>
const dbStages = <?php echo json_encode($stages); ?>;
const dbGrades = <?php echo json_encode($all_grades); ?>;
const dbClasses = <?php echo json_encode($all_classes); ?>;
const dbStudents = <?php echo json_encode($all_students); ?>;
