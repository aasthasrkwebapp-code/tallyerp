<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderAdvance;
use App\Models\OrderAdvanceAdjustment;
use App\Services\Billing\OrderAdvanceService;
use Illuminate\Http\Request;

class OrderAdvanceController extends Controller
{
    public function __construct(protected OrderAdvanceService $advances) {}

    public function store(Request $request, Order $order)
    {
        $this->can($request, $order, 'create');
        $data = $request->validate([
            'date' => ['required', 'date'],
            'amount' => ['required', 'string', 'max:20'],
            'account_id' => ['required', 'integer'],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);
        $advance = $this->advances->record($order, $data, $request->user());

        return redirect()->route('orders.show', $order)->with('success', 'Advance of '.\App\Support\Money::format($advance->amount).' posted as '.$advance->voucher->voucher_number.'.');
    }

    public function adjust(Request $request, OrderAdvance $advance)
    {
        $this->can($request, $advance->order, 'create');
        $data = $request->validate([
            'invoice_id' => ['required', 'integer'],
            'amount' => ['required', 'string', 'max:20'],
            'date' => ['required', 'date'],
        ]);
        $adjustment = $this->advances->adjust($advance, $data, $request->user());

        return redirect()->route('orders.show', $advance->order)->with('success', 'Advance adjusted by journal '.$adjustment->voucher->voucher_number.'.');
    }

    public function cancel(Request $request, OrderAdvance $advance)
    {
        $this->can($request, $advance->order, 'delete');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->advances->cancel($advance, $request->user(), $data['reason']);

        return redirect()->route('orders.show', $advance->order)->with('success', 'Advance cancelled.');
    }

    public function cancelAdjustment(Request $request, OrderAdvanceAdjustment $adjustment)
    {
        $order = $adjustment->advance->order;
        $this->can($request, $order, 'delete');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->advances->cancelAdjustment($adjustment, $request->user(), $data['reason']);

        return redirect()->route('orders.show', $order)->with('success', 'Adjustment cancelled.');
    }

    protected function can(Request $request, Order $order, string $action): void
    {
        abort_unless($request->user()->can($order->config()['module'].'.'.$action), 403);
    }
}
