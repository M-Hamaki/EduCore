            </main>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- jQuery loaded in teacher_header.php head -->
    
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="<?php echo asset_url('../assets/js/datatables-ar.js'); ?>"></script>
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- Particles.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/particles.js@2.0.0/particles.min.js"></script>
    <?php if (!function_exists('asset_url')) { require_once __DIR__ . '/template_helper.php'; } ?>
    <script src="<?php echo asset_url('../assets/js/particles_theme.js'); ?>"></script>
    <!-- Custom JS -->
    <script src="<?php echo asset_url('../assets/js/main.js'); ?>"></script>
    <script src="<?php echo asset_url('../assets/js/form-safety.js'); ?>"></script>
    <!-- Air Datepicker (حامل التاريخ الموحد) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/air-datepicker@3.5.0/air-datepicker.css">
    <script src="https://cdn.jsdelivr.net/npm/air-datepicker@3.5.0/air-datepicker.js"></script>
    <script src="<?php echo asset_url('../assets/js/air-datepicker-init.js'); ?>"></script>
    <?php require __DIR__ . '/undo_toast.php'; ?>
<?php include_once __DIR__ . '/push_init.php'; ?>
</body>
</html>
