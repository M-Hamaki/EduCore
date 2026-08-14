<?php
if (!function_exists('asset_url')) {
    require_once __DIR__ . '/template_helper.php';
}
?>
<div id="undoToast" class="toast align-items-center border-0 position-fixed bottom-0 end-0 m-3" role="alert" style="z-index:9999;" data-bs-autohide="true" data-bs-delay="4000">
    <div class="d-flex">
        <div class="toast-body" id="undoToastBody"></div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="إغلاق"></button>
    </div>
</div>
<script src="<?php echo asset_url('../assets/js/undo-toast.js'); ?>"></script>
