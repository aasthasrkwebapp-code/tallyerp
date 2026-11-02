<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveEncashment;
use App\Models\LeaveOpening;
use App\Services\Payroll\AttendanceService;
use App\Services\Payroll\LeaveService;
use App\Support\CsvExport;
use App\Support\CurrentCompany;
use App\Support\Money;
use Illuminate\Http\Request;

/** Opening leave balances and leave encashment. */
class LeaveController extends Controller
{
    public function __construct(protected LeaveService $leave) {}

    public function openings(CurrentCompany $current)
    {
        $types = $this->leave->balanceTypes();
        $employees = Employee::query()->where(fn ($q) => $q->where('is_active', true)->orWhereHas('leaveOpenings'))->orderBy('code')->get();

        return view('payroll.leave.openings', [
            'types' => $types,
            'employees' => $employees,
            'openings' => LeaveOpening::query()->get()->mapWithKeys(fn ($o) => [$o->employee_id.'|'.$o->leave_type_id => \App\Support\Decimal::trim($o->days)]),
            'begin' => $current->get()->books_begin_date,
        ]);
    }

    public function saveOpenings(Request $request)
    {
        $request->validate(['days' => ['nullable', 'array']]);
        $changed = $this->leave->saveOpenings((array) $request->input('days', []));

        return redirect()->route('leave-openings')->with('success', $changed ? "{$changed} opening balance(s) saved." : 'No changes.');
    }

    public function index(Request $request)
    {
        $status = in_array($request->input('status'), ['active', 'cancelled', 'all'], true) ? $request->input('status') : 'active';
        $rows = LeaveEncashment::query()->with('employee', 'leaveType', 'voucher', 'paidAccount')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->orderByDesc('date')->orderByDesc('id')->get();

        if (CsvExport::requested($request)) {
            return CsvExport::download('leave-encashments.csv', ['Date', 'Employee code', 'Employee', 'Leave type', 'Days', 'Rate', 'Amount', 'Voucher', 'Status'],
                $rows->map(fn ($e) => [$e->date->toDateString(), $e->employee->code, $e->employee->name, $e->leaveType->name, $e->days, $e->rate, $e->amount,
                    $e->voucher?->voucher_number, ucfirst($e->status)]));
        }

        return view('payroll.leave.encashments', ['rows' => $rows, 'status' => $status, 'total' => $rows->where('status', 'active')->sum(fn ($e) => Money::toPaise($e->amount))]);
    }

    public function create(Request $request, AttendanceService $attendance)
    {
        $employees = Employee::query()->where('is_active', true)->orderBy('name')->get();
        $types = $this->leave->balanceTypes()->where('is_paid', true)->values();
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('date')) ? $request->input('date') : now()->toDateString();
        $employee = $employees->firstWhere('id', $request->integer('employee_id'));
        $balances = $employee ? $types->mapWithKeys(fn ($t) => [$t->id => $attendance->leaveBalance($employee, $t, $date)['balance'] ?? null]) : collect();

        return view('payroll.leave.encash', [
            'employees' => $employees,
            'types' => $types,
            'employee' => $employee,
            'date' => $date,
            'balances' => $balances,
            'rate' => $employee ? $this->leave->suggestedRate($employee) : 0,
            'paidFrom' => $this->leave->paidFromQuery()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'leave_type_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'days' => ['required', 'string', 'max:6'],
            'rate' => ['required', 'string', 'max:20'],
            'paid_account_id' => ['required', 'integer'],
            'narration' => ['nullable', 'string', 'max:255'],
        ]);
        $encashment = $this->leave->encash($data, $request->user());

        return redirect()->route('leave-encashments.index')->with('success', "Leave encashed for {$encashment->employee->name}: "
            .Money::format($encashment->amount)." paid by voucher {$encashment->voucher->voucher_number}.");
    }

    public function cancel(Request $request, LeaveEncashment $leaveEncashment)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->leave->cancel($leaveEncashment, $request->user(), $data['reason']);

        return back()->with('success', 'Encashment cancelled and its voucher cancelled.');
    }
}
