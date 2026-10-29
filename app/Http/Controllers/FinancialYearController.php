<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use App\Models\FinancialYear;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class FinancialYearController extends Controller
{
    public function index()
    {
        $years = FinancialYear::with('periods')->orderByDesc('start_date')->get();

        return view('financial-years.index', compact('years'));
    }

    /** Opens the year following the latest one. */
    public function store(CurrentCompany $current)
    {
        $latest = FinancialYear::orderByDesc('start_date')->first();
        $start = $latest ? $latest->end_date->copy()->addDay() : Carbon::create(now()->year, 4, 1);

        $year = FinancialYear::open($current->get(), $start, makeCurrent: false);

        return back()->with('success', "Financial year {$year->name} opened.");
    }

    public function makeCurrent(FinancialYear $financialYear)
    {
        FinancialYear::query()->update(['is_current' => false]);
        $financialYear->update(['is_current' => true]);

        return back()->with('success', "{$financialYear->name} is now the current financial year.");
    }

    public function toggleLock(Request $request, FinancialYear $financialYear)
    {
        $lock = ! $financialYear->is_locked;
        $financialYear->update([
            'is_locked' => $lock,
            'locked_at' => $lock ? now() : null,
            'locked_by' => $lock ? $request->user()->id : null,
        ]);

        return back()->with('success', "Financial year {$financialYear->name} ".($lock ? 'locked' : 'unlocked').'.');
    }

    public function togglePeriodLock(Request $request, AccountingPeriod $period)
    {
        $lock = ! $period->is_locked;
        $period->update([
            'is_locked' => $lock,
            'locked_at' => $lock ? now() : null,
            'locked_by' => $lock ? $request->user()->id : null,
        ]);

        return back()->with('success', "Period {$period->name} ".($lock ? 'locked' : 'unlocked').'.');
    }
}
