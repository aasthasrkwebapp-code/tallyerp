<?php

use App\Http\Controllers\Accounting\VoucherController;
use App\Http\Controllers\Accounting\RecurringVoucherController;
use App\Http\Controllers\Accounting\VoucherTypeController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Banking\BankReconciliationController;
use App\Http\Controllers\Banking\ChequeController;
use App\Http\Controllers\Billing\InvoiceController;
use App\Http\Controllers\Billing\OrderAdvanceController;
use App\Http\Controllers\Billing\OrderController;
use App\Http\Controllers\Billing\PartyController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\FinancialYearController;
use App\Http\Controllers\Gst\GstReportController;
use App\Http\Controllers\Inventory\InventoryMasterController;
use App\Http\Controllers\Inventory\BomController;
use App\Http\Controllers\Inventory\StockItemController;
use App\Http\Controllers\Inventory\GoodsInTransitController;
use App\Http\Controllers\Inventory\StockJournalController;
use App\Http\Controllers\Inventory\DeliveryNoteController;
use App\Http\Controllers\Inventory\GrniController;
use App\Http\Controllers\Inventory\StockReportController;
use App\Http\Controllers\Masters\AccountGroupController;
use App\Http\Controllers\Masters\CostCentreController;
use App\Http\Controllers\Masters\LedgerController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\Payroll\AttendanceController;
use App\Http\Controllers\Payroll\EmployeeController;
use App\Http\Controllers\Payroll\EmployeeLoanController;
use App\Http\Controllers\Payroll\PayHeadController;
use App\Http\Controllers\Payroll\PayrollFileController;
use App\Http\Controllers\Payroll\PayrollRunController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Reports\AccountingReportController;
use App\Http\Controllers\Reports\BudgetController;
use App\Http\Controllers\Reports\OutstandingReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\Transport\TransportExpenseController;
use App\Http\Controllers\Transport\TransportMasterController;
use App\Http\Controllers\Transport\TransportReportController;
use App\Http\Controllers\Transport\TripController;
use App\Http\Controllers\Data\BackupController;
use App\Http\Controllers\Data\IntegrityController;
use App\Http\Controllers\Data\ImportController;
use App\Http\Controllers\Data\ExportController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : redirect()->route('login'));

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Company list and creation work without an active company.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
    Route::get('/companies/create', [CompanyController::class, 'create'])->name('companies.create');
    Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
    Route::get('/companies/{company}', [CompanyController::class, 'show'])->name('companies.show');
    Route::get('/companies/{company}/edit', [CompanyController::class, 'edit'])->name('companies.edit');
    Route::put('/companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
    Route::delete('/companies/{company}', [CompanyController::class, 'destroy'])->name('companies.destroy');
    Route::post('/companies/{company}/switch', [CompanyController::class, 'switch'])->name('companies.switch');
});

// Everything below runs inside the active company.
Route::middleware(['auth', 'verified', 'company'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/search', SearchController::class)->name('search');

    Route::middleware('can:company.edit')->group(function () {
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::put('/settings/preferences', [SettingsController::class, 'updatePreferences'])->name('settings.preferences');
    });

    Route::get('/financial-years', [FinancialYearController::class, 'index'])->name('financial-years.index')->middleware('can:periods.view');
    Route::post('/financial-years', [FinancialYearController::class, 'store'])->name('financial-years.store')->middleware('can:periods.create');
    Route::post('/financial-years/{financialYear}/current', [FinancialYearController::class, 'makeCurrent'])->name('financial-years.current')->middleware('can:periods.create');
    Route::post('/financial-years/{financialYear}/lock', [FinancialYearController::class, 'toggleLock'])->name('financial-years.lock')->middleware('can:periods.lock');
    Route::post('/accounting-periods/{period}/lock', [FinancialYearController::class, 'togglePeriodLock'])->name('accounting-periods.lock')->middleware('can:periods.lock');

    Route::get('/members', [MemberController::class, 'index'])->name('members.index')->middleware('can:users.view');
    Route::post('/members', [MemberController::class, 'store'])->name('members.store')->middleware('can:users.create');
    Route::put('/members/{user}', [MemberController::class, 'update'])->name('members.update')->middleware('can:users.edit');
    Route::delete('/members/{user}', [MemberController::class, 'destroy'])->name('members.destroy')->middleware('can:users.delete');
    Route::post('/members/{user}/reset-two-factor', [MemberController::class, 'resetTwoFactor'])->name('members.reset-two-factor')->middleware('can:users.edit');

    Route::get('/roles/create', [RoleController::class, 'create'])->name('roles.create')->middleware('can:users.create');
    Route::post('/roles', [RoleController::class, 'store'])->name('roles.store')->middleware('can:users.create');
    Route::get('/roles/{role}', [RoleController::class, 'show'])->name('roles.show')->middleware('can:users.view');
    Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit')->middleware('can:users.edit');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update')->middleware('can:users.edit');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy')->middleware('can:users.delete');

    // Accounting masters
    Route::get('/account-groups', [AccountGroupController::class, 'index'])->name('account-groups.index')->middleware('can:masters.view');
    Route::get('/account-groups/create', [AccountGroupController::class, 'create'])->name('account-groups.create')->middleware('can:masters.create');
    Route::post('/account-groups', [AccountGroupController::class, 'store'])->name('account-groups.store')->middleware('can:masters.create');
    Route::get('/account-groups/{accountGroup}/edit', [AccountGroupController::class, 'edit'])->name('account-groups.edit')->middleware('can:masters.edit');
    Route::put('/account-groups/{accountGroup}', [AccountGroupController::class, 'update'])->name('account-groups.update')->middleware('can:masters.edit');
    Route::delete('/account-groups/{accountGroup}', [AccountGroupController::class, 'destroy'])->name('account-groups.destroy')->middleware('can:masters.delete');

    Route::get('/ledgers', [LedgerController::class, 'index'])->name('ledgers.index')->middleware('can:masters.view');
    Route::get('/ledgers/create', [LedgerController::class, 'create'])->name('ledgers.create')->middleware('can:masters.create');
    Route::post('/ledgers', [LedgerController::class, 'store'])->name('ledgers.store')->middleware('can:masters.create');
    Route::get('/ledgers/{ledger}', [LedgerController::class, 'show'])->name('ledgers.show')->middleware('can:masters.view');
    Route::get('/ledgers/{ledger}/edit', [LedgerController::class, 'edit'])->name('ledgers.edit')->middleware('can:masters.edit');
    Route::put('/ledgers/{ledger}', [LedgerController::class, 'update'])->name('ledgers.update')->middleware('can:masters.edit');
    Route::delete('/ledgers/{ledger}', [LedgerController::class, 'destroy'])->name('ledgers.destroy')->middleware('can:masters.delete');

    Route::get('/cost-centres', [CostCentreController::class, 'index'])->name('cost-centres.index')->middleware('can:masters.view');
    Route::post('/cost-centres', [CostCentreController::class, 'store'])->name('cost-centres.store')->middleware('can:masters.create');
    Route::put('/cost-centres/{costCentre}', [CostCentreController::class, 'update'])->name('cost-centres.update')->middleware('can:masters.edit');
    Route::delete('/cost-centres/{costCentre}', [CostCentreController::class, 'destroy'])->name('cost-centres.destroy')->middleware('can:masters.delete');
    Route::post('/cost-categories', [CostCentreController::class, 'storeCategory'])->name('cost-categories.store')->middleware('can:masters.create');

    // Voucher types and vouchers
    Route::get('/voucher-types', [VoucherTypeController::class, 'index'])->name('voucher-types.index')->middleware('can:voucher_types.view');
    Route::get('/voucher-types/create', [VoucherTypeController::class, 'create'])->name('voucher-types.create')->middleware('can:voucher_types.create');
    Route::post('/voucher-types', [VoucherTypeController::class, 'store'])->name('voucher-types.store')->middleware('can:voucher_types.create');
    Route::get('/voucher-types/{voucherType}/edit', [VoucherTypeController::class, 'edit'])->name('voucher-types.edit')->middleware('can:voucher_types.edit');
    Route::put('/voucher-types/{voucherType}', [VoucherTypeController::class, 'update'])->name('voucher-types.update')->middleware('can:voucher_types.edit');

    Route::get('/vouchers', [VoucherController::class, 'index'])->name('vouchers.index')->middleware('can:vouchers.view');
    Route::get('/vouchers/create', [VoucherController::class, 'create'])->name('vouchers.create')->middleware('can:vouchers.create');
    Route::post('/vouchers', [VoucherController::class, 'store'])->name('vouchers.store')->middleware('can:vouchers.create');
    Route::get('/vouchers/{voucher}', [VoucherController::class, 'show'])->name('vouchers.show')->middleware('can:vouchers.view');
    Route::get('/vouchers/{voucher}/edit', [VoucherController::class, 'edit'])->name('vouchers.edit');
    Route::put('/vouchers/{voucher}', [VoucherController::class, 'update'])->name('vouchers.update');
    Route::post('/vouchers/{voucher}/post', [VoucherController::class, 'post'])->name('vouchers.post');
    Route::post('/vouchers/{voucher}/cancel', [VoucherController::class, 'cancel'])->name('vouchers.cancel')->middleware('can:vouchers.cancel');
    Route::post('/vouchers/{voucher}/reverse', [VoucherController::class, 'reverse'])->name('vouchers.reverse')->middleware('can:vouchers.cancel');
    Route::delete('/vouchers/{voucher}', [VoucherController::class, 'destroy'])->name('vouchers.destroy')->middleware('can:vouchers.delete');
    Route::get('/ledgers/{ledger}/open-bills', [VoucherController::class, 'openBills'])->name('ledgers.open-bills')->middleware('can:vouchers.view');

    // Recurring vouchers (templates with a schedule)
    Route::get('/recurring-vouchers', [RecurringVoucherController::class, 'index'])->name('recurring-vouchers.index')->middleware('can:vouchers.view');
    Route::post('/recurring-vouchers/run', [RecurringVoucherController::class, 'runAll'])->name('recurring-vouchers.run-all')->middleware('can:vouchers.create');
    Route::get('/recurring-vouchers/create', [RecurringVoucherController::class, 'create'])->name('recurring-vouchers.create')->middleware('can:vouchers.create');
    Route::post('/recurring-vouchers', [RecurringVoucherController::class, 'store'])->name('recurring-vouchers.store')->middleware('can:vouchers.create');
    Route::get('/recurring-vouchers/{recurringVoucher}', [RecurringVoucherController::class, 'show'])->name('recurring-vouchers.show')->middleware('can:vouchers.view');
    Route::get('/recurring-vouchers/{recurringVoucher}/edit', [RecurringVoucherController::class, 'edit'])->name('recurring-vouchers.edit')->middleware('can:vouchers.create');
    Route::put('/recurring-vouchers/{recurringVoucher}', [RecurringVoucherController::class, 'update'])->name('recurring-vouchers.update')->middleware('can:vouchers.create');
    Route::delete('/recurring-vouchers/{recurringVoucher}', [RecurringVoucherController::class, 'destroy'])->name('recurring-vouchers.destroy')->middleware('can:vouchers.delete');
    Route::post('/recurring-vouchers/{recurringVoucher}/run', [RecurringVoucherController::class, 'run'])->name('recurring-vouchers.run')->middleware('can:vouchers.create');

    // Customers and suppliers (party ledgers with contact and GST details)
    foreach (['customers' => ['customer', 'sales'], 'suppliers' => ['supplier', 'purchases']] as $prefix => [$kind, $module]) {
        Route::prefix($prefix)->name($prefix.'.')->group(function () use ($kind, $module) {
            Route::get('/', [PartyController::class, 'index'])->name('index')->defaults('kind', $kind)->middleware("can:{$module}.view");
            Route::get('/create', [PartyController::class, 'create'])->name('create')->defaults('kind', $kind)->middleware("can:{$module}.create");
            Route::post('/', [PartyController::class, 'store'])->name('store')->defaults('kind', $kind)->middleware("can:{$module}.create");
            Route::get('/{ledger}', [PartyController::class, 'show'])->name('show')->defaults('kind', $kind)->middleware("can:{$module}.view");
            Route::get('/{ledger}/edit', [PartyController::class, 'edit'])->name('edit')->defaults('kind', $kind)->middleware("can:{$module}.edit");
            Route::put('/{ledger}', [PartyController::class, 'update'])->name('update')->defaults('kind', $kind)->middleware("can:{$module}.edit");
            Route::delete('/{ledger}', [PartyController::class, 'destroy'])->name('destroy')->defaults('kind', $kind)->middleware("can:{$module}.delete");
        });
    }

    // Inventory: masters, stock items, stock journals and stock reports
    Route::prefix('inventory')->group(function () {
        $masters = implode('|', array_keys(InventoryMasterController::MASTERS));
        Route::get('/masters/{master}', [InventoryMasterController::class, 'index'])->name('inventory-masters.index')->where('master', $masters)->middleware('can:inventory.view');
        Route::post('/masters/{master}', [InventoryMasterController::class, 'store'])->name('inventory-masters.store')->where('master', $masters)->middleware('can:inventory.create');
        Route::put('/masters/{master}/{id}', [InventoryMasterController::class, 'update'])->name('inventory-masters.update')->where(['master' => $masters, 'id' => '[0-9]+'])->middleware('can:inventory.edit');
        Route::delete('/masters/{master}/{id}', [InventoryMasterController::class, 'destroy'])->name('inventory-masters.destroy')->where(['master' => $masters, 'id' => '[0-9]+'])->middleware('can:inventory.delete');

        Route::get('/items', [StockItemController::class, 'index'])->name('stock-items.index')->middleware('can:inventory.view');
        Route::get('/items/create', [StockItemController::class, 'create'])->name('stock-items.create')->middleware('can:inventory.create');
        Route::post('/items', [StockItemController::class, 'store'])->name('stock-items.store')->middleware('can:inventory.create');
        Route::get('/items/{stockItem}', [StockItemController::class, 'show'])->name('stock-items.show')->middleware('can:inventory.view');
        Route::get('/items/{stockItem}/edit', [StockItemController::class, 'edit'])->name('stock-items.edit')->middleware('can:inventory.edit');
        Route::put('/items/{stockItem}', [StockItemController::class, 'update'])->name('stock-items.update')->middleware('can:inventory.edit');
        Route::delete('/items/{stockItem}', [StockItemController::class, 'destroy'])->name('stock-items.destroy')->middleware('can:inventory.delete');
        Route::get('/items/{stockItem}/bom', [BomController::class, 'edit'])->name('stock-items.bom.edit')->middleware('can:inventory.edit');
        Route::put('/items/{stockItem}/bom', [BomController::class, 'update'])->name('stock-items.bom.update')->middleware('can:inventory.edit');
        Route::delete('/items/{stockItem}/bom', [BomController::class, 'destroy'])->name('stock-items.bom.destroy')->middleware('can:inventory.edit');
        Route::get('/items/{stockItem}/manufacture', [BomController::class, 'create'])->name('stock-items.manufacture')->middleware('can:inventory.create');
        Route::post('/items/{stockItem}/manufacture', [BomController::class, 'store'])->name('stock-items.manufacture.store')->middleware('can:inventory.create');

        Route::get('/journals', [StockJournalController::class, 'index'])->name('stock-journals.index')->middleware('can:inventory.view');
        Route::get('/journals/create', [StockJournalController::class, 'create'])->name('stock-journals.create')->middleware('can:inventory.create');
        Route::post('/journals', [StockJournalController::class, 'store'])->name('stock-journals.store')->middleware('can:inventory.create');
        Route::get('/journals/{stockJournal}', [StockJournalController::class, 'show'])->name('stock-journals.show')->middleware('can:inventory.view');
        Route::post('/journals/{stockJournal}/cancel', [StockJournalController::class, 'cancel'])->name('stock-journals.cancel')->middleware('can:inventory.delete');
        Route::post('/journals/{stockJournal}/receive', [GoodsInTransitController::class, 'receive'])->name('stock-journals.receive')->middleware('can:inventory.create');
        Route::get('/transit', [GoodsInTransitController::class, 'index'])->name('stock-transit')->middleware('can:inventory.view');

        Route::get('/summary', [StockReportController::class, 'summary'])->name('stock-summary')->middleware('can:inventory.view');
        Route::get('/reorder', [StockReportController::class, 'reorder'])->name('stock-reorder')->middleware('can:inventory.view');
        Route::get('/ageing', [StockReportController::class, 'ageing'])->name('stock-ageing')->middleware('can:inventory.view');
        Route::get('/batches', [StockReportController::class, 'batches'])->name('stock-batches')->middleware('can:inventory.view');
        Route::get('/serials', [StockReportController::class, 'serials'])->name('stock-serials')->middleware('can:inventory.view');
    });

    // Payroll: employees, pay heads and monthly payroll
    Route::prefix('payroll')->group(function () {
        Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index')->middleware('can:payroll.view');
        Route::get('/employees/create', [EmployeeController::class, 'create'])->name('employees.create')->middleware('can:payroll.create');
        Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store')->middleware('can:payroll.create');
        Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show')->middleware('can:payroll.view');
        Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit')->middleware('can:payroll.edit');
        Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update')->middleware('can:payroll.edit');
        Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])->name('employees.destroy')->middleware('can:payroll.delete');

        Route::get('/pay-heads', [PayHeadController::class, 'index'])->name('pay-heads.index')->middleware('can:payroll.view');
        Route::post('/pay-heads', [PayHeadController::class, 'store'])->name('pay-heads.store')->middleware('can:payroll.create');
        Route::put('/pay-heads/{payHead}', [PayHeadController::class, 'update'])->name('pay-heads.update')->middleware('can:payroll.edit');
        Route::delete('/pay-heads/{payHead}', [PayHeadController::class, 'destroy'])->name('pay-heads.destroy')->middleware('can:payroll.delete');

        Route::get('/runs', [PayrollRunController::class, 'index'])->name('payroll-runs.index')->middleware('can:payroll.view');
        Route::post('/runs', [PayrollRunController::class, 'store'])->name('payroll-runs.store')->middleware('can:payroll.create');
        Route::get('/runs/{payrollRun}', [PayrollRunController::class, 'show'])->name('payroll-runs.show')->middleware('can:payroll.view');
        Route::put('/runs/{payrollRun}', [PayrollRunController::class, 'update'])->name('payroll-runs.update')->middleware('can:payroll.edit');
        Route::post('/runs/{payrollRun}/post', [PayrollRunController::class, 'post'])->name('payroll-runs.post')->middleware('can:payroll.edit');
        Route::post('/runs/{payrollRun}/cancel', [PayrollRunController::class, 'cancel'])->name('payroll-runs.cancel')->middleware('can:payroll.delete');
        Route::delete('/runs/{payrollRun}', [PayrollRunController::class, 'destroy'])->name('payroll-runs.destroy')->middleware('can:payroll.delete');
        Route::get('/runs/{payrollRun}/payslips/{payslip}', [PayrollRunController::class, 'payslip'])->name('payroll-runs.payslip')->middleware('can:payroll.view');
        Route::post('/runs/{payrollRun}/attendance', [PayrollRunController::class, 'attendance'])->name('payroll-runs.attendance')->middleware('can:payroll.edit');
        Route::get('/runs/{payrollRun}/files', [PayrollFileController::class, 'index'])->name('payroll-runs.files')->middleware('can:payroll.view');
        Route::get('/runs/{payrollRun}/files/bank', [PayrollFileController::class, 'bank'])->name('payroll-runs.bank-file')->middleware('can:payroll.view');
        Route::get('/runs/{payrollRun}/files/ecr', [PayrollFileController::class, 'ecr'])->name('payroll-runs.ecr')->middleware('can:payroll.view');
        Route::get('/runs/{payrollRun}/files/esi', [PayrollFileController::class, 'esi'])->name('payroll-runs.esi-file')->middleware('can:payroll.view');
        Route::post('/runs/{payrollRun}/pay', [PayrollFileController::class, 'pay'])->name('payroll-runs.pay')->middleware('can:payroll.edit');

        Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index')->middleware('can:payroll.view');
        Route::put('/attendance', [AttendanceController::class, 'update'])->name('attendance.update')->middleware('can:payroll.edit');
        Route::get('/attendance/import', [AttendanceController::class, 'import'])->name('attendance.import')->middleware('can:payroll.edit');
        Route::get('/attendance/import/template', [AttendanceController::class, 'importTemplate'])->name('attendance.import-template')->middleware('can:payroll.edit');
        Route::post('/attendance/import', [AttendanceController::class, 'previewImport'])->name('attendance.import.preview')->middleware('can:payroll.edit');
        Route::post('/attendance/import/confirm', [AttendanceController::class, 'confirmImport'])->name('attendance.import.confirm')->middleware('can:payroll.edit');
        Route::get('/leave-register', [AttendanceController::class, 'leaveRegister'])->name('leave-register')->middleware('can:payroll.view');
        Route::get('/leave-types', [AttendanceController::class, 'leaveTypes'])->name('leave-types.index')->middleware('can:payroll.view');
        Route::post('/leave-types', [AttendanceController::class, 'storeLeaveType'])->name('leave-types.store')->middleware('can:payroll.create');
        Route::put('/leave-types/{leaveType}', [AttendanceController::class, 'updateLeaveType'])->name('leave-types.update')->middleware('can:payroll.edit');
        Route::delete('/leave-types/{leaveType}', [AttendanceController::class, 'destroyLeaveType'])->name('leave-types.destroy')->middleware('can:payroll.delete');

        Route::get('/loans', [EmployeeLoanController::class, 'index'])->name('employee-loans.index')->middleware('can:payroll.view');
        Route::get('/loans/create', [EmployeeLoanController::class, 'create'])->name('employee-loans.create')->middleware('can:payroll.create');
        Route::post('/loans', [EmployeeLoanController::class, 'store'])->name('employee-loans.store')->middleware('can:payroll.create');
        Route::get('/loans/{employeeLoan}', [EmployeeLoanController::class, 'show'])->name('employee-loans.show')->middleware('can:payroll.view');
        Route::post('/loans/{employeeLoan}/cancel', [EmployeeLoanController::class, 'cancel'])->name('employee-loans.cancel')->middleware('can:payroll.delete');
    });

    // Transport: vehicles, drivers, routes, trips, expenses and profitability
    Route::prefix('transport')->group(function () {
        $masters = implode('|', array_keys(TransportMasterController::MASTERS));
        Route::get('/masters/{master}', [TransportMasterController::class, 'index'])->name('transport-masters.index')->where('master', $masters)->middleware('can:transport.view');
        Route::post('/masters/{master}', [TransportMasterController::class, 'store'])->name('transport-masters.store')->where('master', $masters)->middleware('can:transport.create');
        Route::put('/masters/{master}/{id}', [TransportMasterController::class, 'update'])->name('transport-masters.update')->where(['master' => $masters, 'id' => '[0-9]+'])->middleware('can:transport.edit');
        Route::delete('/masters/{master}/{id}', [TransportMasterController::class, 'destroy'])->name('transport-masters.destroy')->where(['master' => $masters, 'id' => '[0-9]+'])->middleware('can:transport.delete');

        Route::get('/trips', [TripController::class, 'index'])->name('trips.index')->middleware('can:transport.view');
        Route::get('/trips/create', [TripController::class, 'create'])->name('trips.create')->middleware('can:transport.create');
        Route::post('/trips', [TripController::class, 'store'])->name('trips.store')->middleware('can:transport.create');
        Route::get('/trips/{trip}', [TripController::class, 'show'])->name('trips.show')->middleware('can:transport.view');
        Route::get('/trips/{trip}/edit', [TripController::class, 'edit'])->name('trips.edit')->middleware('can:transport.edit');
        Route::put('/trips/{trip}', [TripController::class, 'update'])->name('trips.update')->middleware('can:transport.edit');
        Route::post('/trips/{trip}/complete', [TripController::class, 'complete'])->name('trips.complete')->middleware('can:transport.edit');
        Route::post('/trips/{trip}/cancel', [TripController::class, 'cancel'])->name('trips.cancel')->middleware('can:transport.delete');
        Route::post('/trips/{trip}/bill', [TripController::class, 'bill'])->name('trips.bill')->middleware('can:transport.edit');

        Route::get('/expenses', [TransportExpenseController::class, 'index'])->name('transport-expenses.index')->middleware('can:transport.view');
        Route::get('/expenses/create', [TransportExpenseController::class, 'create'])->name('transport-expenses.create')->middleware('can:transport.create');
        Route::post('/expenses', [TransportExpenseController::class, 'store'])->name('transport-expenses.store')->middleware('can:transport.create');
        Route::post('/expenses/{transportExpense}/cancel', [TransportExpenseController::class, 'cancel'])->name('transport-expenses.cancel')->middleware('can:transport.delete');

        Route::get('/reports/{report}', TransportReportController::class)->name('transport-reports')->where('report', 'trips|vehicles|routes|customers|drivers')->middleware('can:transport.view');
    });

    // Sales invoices, purchase bills, credit and debit notes (permissions checked per kind)
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
    Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('/invoices/{invoice}/edit', [InvoiceController::class, 'edit'])->name('invoices.edit');
    Route::get('/invoices/{invoice}/print', [InvoiceController::class, 'print'])->name('invoices.print');
    Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
    Route::post('/invoices/{invoice}/post', [InvoiceController::class, 'post'])->name('invoices.post');
    Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
    Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');

    // Sales and purchase orders (permissions checked per kind in the controller)
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/pending', [OrderController::class, 'pending'])->name('orders.pending');
    Route::get('/orders/create', [OrderController::class, 'create'])->name('orders.create');
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('/orders/{order}/edit', [OrderController::class, 'edit'])->name('orders.edit');
    Route::put('/orders/{order}', [OrderController::class, 'update'])->name('orders.update');
    Route::get('/orders/{order}/print', [OrderController::class, 'print'])->name('orders.print');
    Route::get('/orders/{order}/pdf', [OrderController::class, 'pdf'])->name('orders.pdf');
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
    Route::post('/orders/{order}/close', [OrderController::class, 'close'])->name('orders.close');
    Route::post('/orders/{order}/reopen', [OrderController::class, 'reopen'])->name('orders.reopen');
    Route::post('/orders/{order}/convert', [OrderController::class, 'convert'])->name('orders.convert');
    Route::get('/orders/{order}/revisions/{revision}', [OrderController::class, 'revision'])->name('orders.revision')->whereNumber('revision');
    Route::post('/orders/{order}/advances', [OrderAdvanceController::class, 'store'])->name('orders.advances.store');
    Route::post('/orders/advances/{advance}/adjust', [OrderAdvanceController::class, 'adjust'])->name('order-advances.adjust');
    Route::post('/orders/advances/{advance}/cancel', [OrderAdvanceController::class, 'cancel'])->name('order-advances.cancel');
    Route::post('/orders/advance-adjustments/{adjustment}/cancel', [OrderAdvanceController::class, 'cancelAdjustment'])->name('advance-adjustments.cancel');

    // Goods received not invoiced provisions
    Route::get('/grni', [GrniController::class, 'index'])->name('grni.index')->middleware('can:purchases.view');
    Route::post('/grni', [GrniController::class, 'store'])->name('grni.store')->middleware('can:purchases.create');
    Route::post('/grni/{grniProvision}/cancel', [GrniController::class, 'cancel'])->name('grni.cancel')->middleware('can:purchases.delete');

    // Delivery notes and goods receipt notes (permissions checked per kind in the controller)
    Route::get('/notes', [DeliveryNoteController::class, 'index'])->name('notes.index');
    Route::get('/notes/pending', [DeliveryNoteController::class, 'pending'])->name('notes.pending');
    Route::get('/notes/create', [DeliveryNoteController::class, 'create'])->name('notes.create');
    Route::post('/notes', [DeliveryNoteController::class, 'store'])->name('notes.store');
    Route::get('/notes/{note}', [DeliveryNoteController::class, 'show'])->name('notes.show');
    Route::get('/notes/{note}/print', [DeliveryNoteController::class, 'print'])->name('notes.print');
    Route::get('/notes/{note}/pdf', [DeliveryNoteController::class, 'pdf'])->name('notes.pdf');
    Route::post('/notes/{note}/cancel', [DeliveryNoteController::class, 'cancel'])->name('notes.cancel');

    // Banking: reconciliation and statement import
    Route::get('/banking', [BankReconciliationController::class, 'index'])->name('bank-reconciliation.index')->middleware('can:banking.view');
    Route::post('/banking/{ledger}/dates', [BankReconciliationController::class, 'update'])->name('bank-reconciliation.update')->middleware('can:banking.edit');
    Route::post('/banking/{ledger}/import', [BankReconciliationController::class, 'import'])->name('bank-reconciliation.import')->middleware('can:banking.create');
    Route::post('/banking/{ledger}/auto-match', [BankReconciliationController::class, 'autoMatch'])->name('bank-reconciliation.auto-match')->middleware('can:banking.edit');
    Route::post('/bank-statement-lines/{line}/match', [BankReconciliationController::class, 'match'])->name('bank-statement-lines.match')->middleware('can:banking.edit');
    Route::post('/bank-statement-lines/{line}/unmatch', [BankReconciliationController::class, 'unmatch'])->name('bank-statement-lines.unmatch')->middleware('can:banking.edit');
    Route::delete('/bank-statement-lines/{line}', [BankReconciliationController::class, 'destroyLine'])->name('bank-statement-lines.destroy')->middleware('can:banking.delete');

    // Cheque register, cheque books and printing
    Route::get('/cheques', [ChequeController::class, 'index'])->name('cheques.index')->middleware('can:banking.view');
    Route::get('/cheques/print', [ChequeController::class, 'printBatch'])->name('cheques.print-batch')->middleware('can:banking.view');
    Route::get('/cheques/{voucher}/print', [ChequeController::class, 'print'])->name('cheques.print')->middleware('can:banking.view');
    Route::get('/cheque-books', [ChequeController::class, 'books'])->name('cheque-books.index')->middleware('can:banking.view');
    Route::post('/cheque-books', [ChequeController::class, 'storeBook'])->name('cheque-books.store')->middleware('can:banking.create');
    Route::get('/cheque-books/{chequeBook}/edit', [ChequeController::class, 'editBook'])->name('cheque-books.edit')->middleware('can:banking.edit');
    Route::put('/cheque-books/{chequeBook}', [ChequeController::class, 'updateBook'])->name('cheque-books.update')->middleware('can:banking.edit');
    Route::delete('/cheque-books/{chequeBook}', [ChequeController::class, 'destroyBook'])->name('cheque-books.destroy')->middleware('can:banking.delete');
    Route::post('/cheque-books/{chequeBook}/spoil', [ChequeController::class, 'spoil'])->name('cheque-books.spoil')->middleware('can:banking.edit');
    Route::delete('/spoilt-cheques/{spoiltCheque}', [ChequeController::class, 'unspoil'])->name('spoilt-cheques.destroy')->middleware('can:banking.edit');

    // GST returns (working papers)
    Route::middleware('can:gst.view')->prefix('gst')->name('gst.')->group(function () {
        Route::get('/gstr-1', [GstReportController::class, 'gstr1'])->name('gstr1');
        Route::get('/gstr-1/json', [GstReportController::class, 'gstr1Json'])->name('gstr1-json')->middleware('can:reports.export');
        Route::get('/gstr-3b', [GstReportController::class, 'gstr3b'])->name('gstr3b');
        Route::get('/purchase-register', [GstReportController::class, 'purchaseRegister'])->name('purchase-register');
    });

    // Accounting reports
    Route::middleware('can:reports.view')->prefix('reports')->name('reports.')->group(function () {
        Route::get('/trial-balance', [AccountingReportController::class, 'trialBalance'])->name('trial-balance');
        Route::get('/profit-loss', [AccountingReportController::class, 'profitLoss'])->name('profit-loss');
        Route::get('/balance-sheet', [AccountingReportController::class, 'balanceSheet'])->name('balance-sheet');
        Route::get('/cash-flow', [AccountingReportController::class, 'cashFlow'])->name('cash-flow');
        Route::get('/funds-flow', [AccountingReportController::class, 'fundsFlow'])->name('funds-flow');
        Route::get('/ratios', [AccountingReportController::class, 'ratios'])->name('ratios');
        Route::get('/comparison', [AccountingReportController::class, 'comparison'])->name('comparison');
        Route::get('/day-book', [AccountingReportController::class, 'dayBook'])->name('day-book');
        Route::get('/ledger', [AccountingReportController::class, 'ledger'])->name('ledger');
        Route::get('/groups/{group}', [AccountingReportController::class, 'groupSummary'])->name('group-summary');
        Route::get('/outstanding', [OutstandingReportController::class, 'index'])->name('outstanding');

        Route::get('/outstanding/{ledger}', [OutstandingReportController::class, 'party'])->name('outstanding.party');
    });

    // Budgets and budget variance
    Route::prefix('budgets')->name('budgets.')->group(function () {
        Route::get('/', [BudgetController::class, 'index'])->name('index')->middleware('can:reports.view');
        Route::get('/create', [BudgetController::class, 'create'])->name('create')->middleware('can:masters.create');
        Route::post('/', [BudgetController::class, 'store'])->name('store')->middleware('can:masters.create');
        Route::get('/{budget}', [BudgetController::class, 'show'])->name('show')->middleware('can:reports.view');
        Route::get('/{budget}/edit', [BudgetController::class, 'edit'])->name('edit')->middleware('can:masters.edit');
        Route::put('/{budget}', [BudgetController::class, 'update'])->name('update')->middleware('can:masters.edit');
        Route::post('/{budget}/copy', [BudgetController::class, 'copy'])->name('copy')->middleware('can:masters.create');
        Route::get('/{budget}/phasing', [BudgetController::class, 'phasing'])->name('phasing')->middleware('can:masters.edit');
        Route::put('/{budget}/phasing', [BudgetController::class, 'savePhasing'])->name('phasing.update')->middleware('can:masters.edit');
        Route::delete('/{budget}', [BudgetController::class, 'destroy'])->name('destroy')->middleware('can:masters.delete');
    });

    // Data management: backups, restore and integrity checks
    Route::prefix('data')->group(function () {
        Route::middleware('can:data.backup')->group(function () {
            Route::get('/backups', [BackupController::class, 'index'])->name('backups.index');
            Route::post('/backups', [BackupController::class, 'store'])->name('backups.store');
            Route::post('/backups/upload', [BackupController::class, 'upload'])->name('backups.upload');
            Route::put('/backups/schedule', [BackupController::class, 'schedule'])->name('backups.schedule');
            Route::get('/backups/{backupLog}/download', [BackupController::class, 'download'])->name('backups.download');
            Route::post('/backups/{backupLog}/verify', [BackupController::class, 'verify'])->name('backups.verify');
            Route::post('/backups/{backupLog}/restore', [BackupController::class, 'restore'])->name('backups.restore');
            Route::delete('/backups/{backupLog}', [BackupController::class, 'destroy'])->name('backups.destroy');
        });
        Route::middleware('can:data.import')->group(function () {
            Route::get('/import', [ImportController::class, 'index'])->name('imports.index');
            Route::get('/import/template/{type}.{format}', [ImportController::class, 'template'])->name('imports.template')->where('format', 'csv|xlsx');
            Route::post('/import/preview', [ImportController::class, 'preview'])->name('imports.preview');
            Route::post('/import/confirm', [ImportController::class, 'confirm'])->name('imports.confirm');
            Route::get('/import/errors', [ImportController::class, 'errors'])->name('imports.errors');
        });
        Route::get('/export', [ExportController::class, 'index'])->name('exports.index')->middleware('can:data.export');
        Route::get('/export/{type}', [ExportController::class, 'download'])->name('exports.download')->middleware('can:data.export');
        Route::get('/integrity', IntegrityController::class)->name('integrity')->middleware('can:data.view');
    });

    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index')->middleware('can:audit.view');
    Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show'])->name('audit-logs.show')->middleware('can:audit.view');
});

require __DIR__.'/auth.php';
