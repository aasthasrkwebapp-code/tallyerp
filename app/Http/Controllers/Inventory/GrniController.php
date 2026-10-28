<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\GrniProvision;
use App\Services\Inventory\GrniService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class GrniController extends Controller
{
    public function __construct(protected GrniService $grni) {}

    /** Unbilled goods receipt notes on a date, with the provisions posted so far. */
    public function index(Request $request)
    {
        $filters = $request->validate(['date' => ['nullable', 'date']]);
        $date = Carbon::parse($filters['date'] ?? now()->subMonthNoOverflow()->endOfMonth())->toDateString();

        return view('inventory.grni.index', [
            'date' => $date,
            'preview' => $this->grni->preview($date),
            'posted' => GrniProvision::query()->where('status', 'posted')->where('date', $date)->first(),
            'provisions' => GrniProvision::query()->with('voucher', 'reversal')->orderByDesc('date')->orderByDesc('id')->paginate(20),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['date' => ['required', 'date'], 'reverse' => ['nullable', 'boolean']]);
        $provision = $this->grni->post($data['date'], (bool) ($data['reverse'] ?? false), $request->user());

        return redirect()->route('grni.index', ['date' => $provision->date->toDateString()])
            ->with('success', 'Provision of '.\App\Support\Money::format($provision->amount).' posted as '.$provision->voucher->voucher_number.($provision->reversal ? ', reversed on '.$provision->reversal->date->format('d M Y').' by '.$provision->reversal->voucher_number : '').'.');
    }

    public function cancel(Request $request, GrniProvision $grniProvision)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->grni->cancel($grniProvision, $request->user(), $data['reason']);

        return redirect()->route('grni.index', ['date' => $grniProvision->date->toDateString()])->with('success', 'Provision cancelled with its vouchers.');
    }
}
