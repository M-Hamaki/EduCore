<?php

$lessonShareLessonId = isset($lessonShareLessonId) ? (int) $lessonShareLessonId : 0;
?>
<section class="lesson-share-card" id="lessonSharePanel" data-lesson-id="<?php echo $lessonShareLessonId; ?>">
    <div class="lesson-share-card__header">
        <div>
            <h3 class="lesson-share-card__title">
                <i class="fas fa-share-nodes"></i>
                مشاركة الدرس خارج النظام
            </h3>
            <p class="lesson-share-card__description">
                أنشئ رابطًا عامًا لعرض كل المحتوى المولد دون تسجيل دخول. لا تشارك الرابط إذا كان الدرس يتضمن بيانات حساسة.
            </p>
        </div>
        <span class="badge text-bg-secondary" id="lessonShareStatusBadge">غير مشارك</span>
    </div>

    <div class="lesson-share-card__actions">
        <button type="button" class="btn btn-success" id="lessonShareCreateBtn">
            <i class="fas fa-link me-1"></i>
            <span>إنشاء رابط مشاركة</span>
        </button>
        <button type="button" class="btn btn-secondary d-none" id="lessonShareRevokeBtn" data-bs-toggle="modal" data-bs-target="#lessonShareRevokeModal">
            <i class="fas fa-link-slash me-1"></i>إلغاء المشاركة
        </button>
    </div>

    <div class="lesson-share-card__link d-none" id="lessonShareLinkArea">
        <label class="form-label fw-semibold" for="lessonShareUrl">رابط العرض العام</label>
        <input type="text" class="form-control lesson-share-card__input" id="lessonShareUrl" readonly>
        <div class="lesson-share-card__link-actions">
            <button type="button" class="btn btn-outline-primary" id="lessonShareCopyBtn">
                <i class="fas fa-copy me-1"></i>نسخ الرابط
            </button>
            <button type="button" class="btn btn-outline-success" id="lessonShareNativeBtn">
                <i class="fas fa-share-alt me-1"></i>مشاركة
            </button>
            <a class="btn btn-outline-secondary" id="lessonShareOpenBtn" href="#" target="_blank" rel="noopener noreferrer">
                <i class="fas fa-arrow-up-right-from-square me-1"></i>فتح الرابط
            </a>
        </div>
        <div class="lesson-share-card__notice">
            <i class="fas fa-circle-info mt-1"></i>
            <span>الرابط قابل للإلغاء أو التجديد في أي وقت. تجديده يبطل الرابط السابق فورًا.</span>
        </div>
    </div>

    <p class="lesson-share-card__status mt-3 d-none" id="lessonShareMessage" role="status" aria-live="polite"></p>
</section>

<div class="modal fade" id="lessonShareRevokeModal" tabindex="-1" aria-labelledby="lessonShareRevokeTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content admin-modal admin-modal-premium admin-modal-delete">
            <form id="lessonShareRevokeForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="lessonShareRevokeTitle">
                        <i class="fas fa-link-slash me-2"></i>إلغاء مشاركة الدرس
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-link-slash text-danger" style="font-size: 3rem;"></i>
                    </div>
                    <p class="text-center">هل تريد إيقاف الرابط العام لهذا الدرس؟</p>
                    <div class="alert alert-warning mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        سيتوقف الرابط الحالي فورًا، ويمكنك إنشاء رابط جديد لاحقًا.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-link-slash me-1"></i>إيقاف الرابط
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
