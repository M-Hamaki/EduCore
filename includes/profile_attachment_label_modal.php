<div class="modal fade" id="profileAttachmentLabelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-edit">
            <form id="profileAttachmentLabelForm" novalidate>
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-pen-to-square me-2"></i>تعديل اسم المرفق</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-file-signature text-primary admin-modal-icon-md"></i>
                    </div>
                    <label for="profileAttachmentLabelInput" class="form-label fw-bold">اسم المرفق</label>
                    <input type="text" class="form-control" id="profileAttachmentLabelInput" maxlength="120"
                        autocomplete="off" placeholder="مثال: شهادة الميلاد" required>
                    <div class="invalid-feedback" id="profileAttachmentLabelError"></div>
                    <div class="form-text">سيبقى الملف الأصلي محفوظًا كما هو، ويتغير الاسم الظاهر واسم التنزيل فقط.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-primary" id="profileAttachmentLabelSaveBtn">
                        <i class="fas fa-save me-1"></i>حفظ الاسم
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
