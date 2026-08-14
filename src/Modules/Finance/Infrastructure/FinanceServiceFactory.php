<?php

declare(strict_types=1);

namespace EduCore\Modules\Finance\Infrastructure;

use EduCore\Modules\AcademicStructure\Infrastructure\PdoAcademicYearQuery;
use EduCore\Modules\Finance\Application\ControlAccountService;
use EduCore\Modules\Finance\Application\ArchiveService;
use EduCore\Modules\Finance\Application\BudgetService;
use EduCore\Modules\Finance\Application\BusFeeScheduleService;
use EduCore\Modules\Finance\Application\DailySettlementService;
use EduCore\Modules\Finance\Application\DiscountService;
use EduCore\Modules\Finance\Application\FeePlanService;
use EduCore\Modules\Finance\Application\ExportService;
use EduCore\Modules\Finance\Application\FinanceApprovalWorkflowService;
use EduCore\Modules\Finance\Application\FinanceDashboardService;
use EduCore\Modules\Finance\Application\FinanceAdminReadService;
use EduCore\Modules\Finance\Application\FinancePeriodService;
use EduCore\Modules\Finance\Application\JournalEntryService;
use EduCore\Modules\Finance\Application\ImportService;
use EduCore\Modules\Finance\Application\LegacyFinanceMigrationService;
use EduCore\Modules\Finance\Application\LegacyFeeDefinitionService;
use EduCore\Modules\Finance\Application\LegacyCollectionCompatibilityService;
use EduCore\Modules\Finance\Application\LegacyStaffFinanceCompatibilityService;
use EduCore\Modules\Finance\Application\ManualJournalService;
use EduCore\Modules\Finance\Application\PaymentAllocationService;
use EduCore\Modules\Finance\Application\PayrollRunService;
use EduCore\Modules\Finance\Application\ReceiptService;
use EduCore\Modules\Finance\Application\ReconciliationService;
use EduCore\Modules\Finance\Application\ReportService;
use EduCore\Modules\Finance\Application\StaffAdvanceService;
use EduCore\Modules\Finance\Application\StaffCompensationService;
use EduCore\Modules\Finance\Application\StudentChargeService;
use EduCore\Modules\Finance\Application\SubledgerPostingService;
use EduCore\Modules\Finance\Application\UnappliedCreditService;
use EduCore\Modules\Finance\Application\VoucherService;
use EduCore\Modules\Finance\Application\VoucherImportOperation;
use EduCore\Modules\Finance\Contracts\LegacyFinanceSource;
use EduCore\Modules\Finance\Domain\Policy\AccountMappingPolicy;
use EduCore\Modules\Finance\Domain\Policy\DiscountCombinationPolicy;
use EduCore\Modules\Finance\Domain\Policy\PayrollCalculationPolicy;
use EduCore\Modules\Finance\Domain\Policy\SiblingDiscountPolicy;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoAccountMappingLineRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoAdjustmentRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoArchiveRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoBankAccountRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoBudgetRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoBusFeeScheduleRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoCashboxRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoChargeRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoControlAccountRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoDiscountRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoFeePlanRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoFinanceApprovalRequestRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoFinancePeriodRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoFinanceTransactionManager;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoJournalEntryRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoLegacyCompatibilityRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoLegacyFinanceReadQuery;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoImportBatchRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoPaymentAllocationRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoReceiptRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoRefundRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoFinanceReportQuery;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoFinanceReconciliationQuery;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoFinanceAdminQuery;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoPayrollRunRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoStaffAdvanceMovementRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoStaffAdvanceRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoStaffCompensationRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoStudentContractRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoStudentFinanceAccountRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoSubledgerAccountRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoSubledgerLineRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoSubledgerTransactionRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoUnappliedCreditRepository;
use EduCore\Modules\Finance\Infrastructure\Pdo\PdoVoucherRepository;
use EduCore\Modules\Operations\Audit\AuditEventWriter;
use EduCore\Modules\Students\Infrastructure\PdoStudentEnrollmentQuery;
use EduCore\Modules\Students\Infrastructure\PdoStudentFinanceReadQuery;
use EduCore\Modules\Staff\Infrastructure\PdoStaffFinanceReadQuery;
use EduCore\Modules\Transport\Infrastructure\PdoBusSubscriptionQuery;
use PDO;

/** Small composition root shared by CLI and finance entrypoint adapters. */
final class FinanceServiceFactory
{
    private PdoFinanceTransactionManager $transactions;
    private PdoChargeRepository $charges;
    private PdoSubledgerAccountRepository $subledgerAccounts;
    private PdoStudentFinanceAccountRepository $studentAccounts;
    private PdoReceiptRepository $receipts;
    private PdoUnappliedCreditRepository $credits;
    private JournalEntryService $journals;
    private SubledgerPostingService $posting;

    public function __construct(private PDO $db, private AuditEventWriter $audit)
    {
        $this->transactions = new PdoFinanceTransactionManager($db);
        $this->charges = new PdoChargeRepository($db);
        $this->subledgerAccounts = new PdoSubledgerAccountRepository($db);
        $this->studentAccounts = new PdoStudentFinanceAccountRepository($db);
        $this->receipts = new PdoReceiptRepository($db);
        $this->credits = new PdoUnappliedCreditRepository($db);
        $controlAccounts = new ControlAccountService(new PdoControlAccountRepository($db), new PdoSubledgerLineRepository($db));
        $this->journals = new JournalEntryService(
            new PdoJournalEntryRepository($db),
            new PdoAccountMappingLineRepository($db),
            new AccountMappingPolicy(),
            $controlAccounts
        );
        $this->posting = new SubledgerPostingService(
            $this->transactions,
            $this->subledgerAccounts,
            new PdoSubledgerTransactionRepository($db),
            new PdoSubledgerLineRepository($db),
            $this->journals,
            $audit
        );
    }

    public function studentChargeService(): StudentChargeService
    {
        return new StudentChargeService(
            $this->charges,
            $this->posting,
            $this->journals,
            $this->transactions,
            $this->studentAccounts,
            new PdoStudentContractRepository($this->db),
            $this->subledgerAccounts,
            new PdoFeePlanRepository($this->db),
            new PdoStudentEnrollmentQuery($this->db),
            new PdoBusSubscriptionQuery($this->db),
            $this->audit
        );
    }

    public function feePlanService(): FeePlanService
    {
        return new FeePlanService(new PdoFeePlanRepository($this->db), $this->transactions, $this->audit);
    }

    public function busFeeScheduleService(): BusFeeScheduleService
    {
        return new BusFeeScheduleService(
            new PdoBusFeeScheduleRepository($this->db),
            $this->transactions,
            $this->audit
        );
    }

    public function legacyFeeDefinitionService(): LegacyFeeDefinitionService
    {
        $discountRepository = new PdoDiscountRepository($this->db);
        $busScheduleRepository = new PdoBusFeeScheduleRepository($this->db);
        return new LegacyFeeDefinitionService(
            new PdoAcademicYearQuery($this->db),
            new PdoLegacyFinanceReadQuery($this->db),
            $this->feePlanService(),
            $this->discountService(),
            $discountRepository,
            new BusFeeScheduleService($busScheduleRepository, $this->transactions, $this->audit),
            $busScheduleRepository,
            new PdoLegacyCompatibilityRepository($this->db),
            $this->transactions,
            $this->audit,
            new SiblingDiscountPolicy()
        );
    }

    public function legacyCollectionCompatibilityService(): LegacyCollectionCompatibilityService
    {
        $discountRepository = new PdoDiscountRepository($this->db);
        return new LegacyCollectionCompatibilityService(
            new PdoAcademicYearQuery($this->db),
            new PdoLegacyFinanceReadQuery($this->db),
            new PdoStudentFinanceReadQuery($this->db),
            $this->studentChargeService(),
            $this->paymentAllocationService(),
            $this->receiptService(),
            $this->discountService(),
            $discountRepository,
            new PdoLegacyCompatibilityRepository($this->db),
            $this->approvalWorkflowService(),
            $this->legacyFeeDefinitionService()
        );
    }

    public function legacyStaffFinanceCompatibilityService(): LegacyStaffFinanceCompatibilityService
    {
        return new LegacyStaffFinanceCompatibilityService(
            new PdoStaffFinanceReadQuery($this->db),
            new PdoLegacyFinanceReadQuery($this->db),
            $this->staffCompensationService(),
            new PdoLegacyCompatibilityRepository($this->db)
        );
    }

    public function adminReadService(): FinanceAdminReadService
    {
        return new FinanceAdminReadService(new PdoFinanceAdminQuery($this->db));
    }

    public function dashboardService(): FinanceDashboardService
    {
        return new FinanceDashboardService(new PdoAcademicYearQuery($this->db), new PdoFinanceReportQuery($this->db), new PdoFinanceAdminQuery($this->db));
    }

    public function discountService(): DiscountService
    {
        $discounts = new PdoDiscountRepository($this->db);
        return new DiscountService(
            $discounts,
            $discounts,
            $discounts,
            $this->charges,
            $this->studentAccounts,
            new DiscountCombinationPolicy(),
            $this->transactions,
            $this->audit,
            new PdoAdjustmentRepository($this->db),
            $this->posting,
            $this->journals
        );
    }

    public function paymentAllocationService(): PaymentAllocationService
    {
        return new PaymentAllocationService(
            $this->charges,
            $this->credits,
            $this->credits,
            $this->posting,
            $this->journals,
            $this->transactions
        );
    }

    public function receiptService(): ReceiptService
    {
        return new ReceiptService(
            $this->receipts,
            new PdoPaymentAllocationRepository($this->db),
            $this->credits,
            $this->charges,
            new PdoCashboxRepository($this->db),
            $this->posting,
            $this->journals,
            $this->transactions
        );
    }

    public function unappliedCreditService(): UnappliedCreditService
    {
        return new UnappliedCreditService(
            $this->credits,
            new PdoPaymentAllocationRepository($this->db),
            $this->receipts,
            new PdoRefundRepository($this->db),
            new PdoAdjustmentRepository($this->db),
            $this->studentAccounts,
            $this->posting,
            $this->journals,
            $this->transactions
        );
    }

    public function staffCompensationService(): StaffCompensationService
    {
        return new StaffCompensationService(new PdoStaffCompensationRepository($this->db), $this->transactions, $this->audit);
    }

    public function payrollRunService(): PayrollRunService
    {
        return new PayrollRunService(
            new PdoPayrollRunRepository($this->db),
            $this->posting,
            $this->journals,
            new PayrollCalculationPolicy(),
            $this->transactions,
            new PdoCashboxRepository($this->db),
            $this->audit,
            new PdoStaffCompensationRepository($this->db)
        );
    }

    public function staffAdvanceService(): StaffAdvanceService
    {
        return new StaffAdvanceService(
            new PdoStaffAdvanceRepository($this->db),
            new PdoStaffAdvanceMovementRepository($this->db),
            $this->posting,
            $this->journals,
            $this->transactions,
            $this->audit
        );
    }

    public function voucherService(): VoucherService
    {
        return new VoucherService(
            new PdoVoucherRepository($this->db),
            $this->journals,
            $this->transactions,
            $this->audit,
            new PdoCashboxRepository($this->db),
            new PdoBankAccountRepository($this->db)
        );
    }

    public function approvalWorkflowService(): FinanceApprovalWorkflowService
    {
        return new FinanceApprovalWorkflowService(
            new PdoFinanceApprovalRequestRepository($this->db),
            $this->transactions,
            $this->audit,
            $this->receiptService(),
            $this->unappliedCreditService(),
            $this->staffAdvanceService(),
            $this->voucherService(),
            $this->importService(),
            $this->financePeriodService(),
            $this->manualJournalService(),
            $this->payrollRunService(),
            $this->discountService()
        );
    }

    public function financePeriodService(): FinancePeriodService
    {
        return new FinancePeriodService(
            new PdoFinancePeriodRepository($this->db),
            new PdoAcademicYearQuery($this->db),
            $this->transactions,
            $this->audit
        );
    }

    public function manualJournalService(): ManualJournalService
    {
        return new ManualJournalService($this->journals, $this->financePeriodService(), $this->transactions, $this->audit);
    }

    public function importService(): ImportService
    {
        return new ImportService(
            new PdoImportBatchRepository($this->db),
            $this->transactions,
            $this->audit,
            [new VoucherImportOperation($this->voucherService())],
            new PdoAcademicYearQuery($this->db)
        );
    }

    public function exportService(): ExportService
    {
        return new ExportService(
            $this->audit,
            new FinanceExportRenderer(),
            new LocalFinanceExportStorage(dirname(__DIR__, 4))
        );
    }

    public function budgetService(): BudgetService
    {
        return new BudgetService(new PdoBudgetRepository($this->db), $this->transactions, $this->audit);
    }

    public function dailySettlementService(): DailySettlementService
    {
        return new DailySettlementService(new PdoCashboxRepository($this->db), $this->transactions, $this->audit);
    }

    public function archiveService(): ArchiveService
    {
        return new ArchiveService(new PdoArchiveRepository($this->db), $this->transactions, $this->audit);
    }

    public function reportService(): ReportService
    {
        return new ReportService(new PdoFinanceReportQuery($this->db));
    }

    public function reconciliationService(): ReconciliationService
    {
        return new ReconciliationService($this->posting, new PdoControlAccountRepository($this->db), new PdoFinanceReconciliationQuery($this->db));
    }

    public function legacyMigrationService(LegacyFinanceSource $source): LegacyFinanceMigrationService
    {
        return new LegacyFinanceMigrationService(
            $source,
            new PdoAcademicYearQuery($this->db),
            $this->subledgerAccounts,
            $this->studentAccounts,
            $this->charges,
            $this->receipts,
            $this->studentChargeService(),
            $this->paymentAllocationService(),
            $this->receiptService(),
            $this->reconciliationService(),
            $this->transactions
        );
    }
}
