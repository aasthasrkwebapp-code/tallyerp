@if ($figures)
    @php($m = fn ($p) => \App\Support\Money::format($p, symbol: true, paiseInput: true))
    <div class="row g-3">
        @foreach ([
            ['Income', $figures['income'], 'graph-up-arrow', route('reports.profit-loss')],
            ['Expenses', $figures['expenses'], 'graph-down-arrow', route('reports.profit-loss')],
            [$figures['profit'] >= 0 ? 'Net profit' : 'Net loss', abs($figures['profit']), 'trophy', route('reports.profit-loss')],
            ['Cash in hand', $figures['cash'], 'cash-coin', route('reports.ledger', ['book' => 'cash'])],
            ['Bank balance', $figures['bank'], 'bank', route('reports.ledger', ['book' => 'bank'])],
            ['Receivables', $figures['receivables'], 'arrow-down-left-circle', route('reports.group-summary', ['group' => \App\Models\AccountGroup::idFor('sundry_debtors')])],
            ['Payables', $figures['payables'], 'arrow-up-right-circle', route('reports.group-summary', ['group' => \App\Models\AccountGroup::idFor('sundry_creditors')])],
        ] as [$label, $value, $icon, $link])
            <div class="col-6 col-md-4 col-xl-3">
                <a href="{{ $link }}" class="erp-card stat-card d-block text-decoration-none text-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <span class="stat-label">{{ $label }}</span>
                        <i class="bi bi-{{ $icon }} text-secondary" aria-hidden="true"></i>
                    </div>
                    <div class="stat-value">{{ $m($value) }}</div>
                </a>
            </div>
        @endforeach
        <div class="col-6 col-md-4 col-xl-3">
            <a href="{{ route('vouchers.index', ['status' => 'pending_approval']) }}" class="erp-card stat-card d-block text-decoration-none text-body">
                <div class="stat-label">Waiting</div>
                <div class="stat-value">{{ $figures['pending'] }} <small class="fs-6 fw-normal text-secondary">pending</small></div>
                <div class="small text-secondary">{{ $figures['drafts'] }} draft(s)</div>
            </a>
        </div>
    </div>
    <p class="small text-secondary mt-2 mb-0">Figures from {{ \Illuminate\Support\Carbon::parse($figures['from'])->format('d M Y') }} to {{ \Illuminate\Support\Carbon::parse($figures['to'])->format('d M Y') }}, posted vouchers only.</p>

    <div class="row g-3 mt-1">
        <div class="col-12 col-xl-7">
            <div class="erp-card h-100">
                <div class="erp-card-header">Income and expenses by month</div>
                <div class="p-3">
                    @php($max = max(1, collect($figures['monthly'])->flatten()->map(fn ($v) => abs($v))->max() ?? 1))
                    @forelse ($figures['monthly'] as $month => $v)
                        <div class="mb-2">
                            <div class="d-flex justify-content-between small"><span>{{ \Illuminate\Support\Carbon::parse($month.'-01')->format('M Y') }}</span>
                                <span class="text-secondary">{{ \App\Support\Money::format($v['income'], paiseInput: true) }} / {{ \App\Support\Money::format($v['expense'], paiseInput: true) }}</span></div>
                            <div class="progress mb-1" style="height: 8px" role="img" aria-label="Income {{ \App\Support\Money::format($v['income'], paiseInput: true) }}">
                                <div class="progress-bar bg-success" style="width: {{ max(0, round($v['income'] / $max * 100)) }}%"></div>
                            </div>
                            <div class="progress" style="height: 8px" role="img" aria-label="Expenses {{ \App\Support\Money::format($v['expense'], paiseInput: true) }}">
                                <div class="progress-bar bg-danger" style="width: {{ max(0, round($v['expense'] / $max * 100)) }}%"></div>
                            </div>
                        </div>
                    @empty
                        <p class="text-secondary small mb-0">No income or expenses posted yet.</p>
                    @endforelse
                    <div class="small text-secondary mt-2"><span class="badge bg-success">&nbsp;</span> Income <span class="badge bg-danger ms-2">&nbsp;</span> Expenses</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-5">
            <div class="erp-card h-100">
                <div class="erp-card-header">Recent vouchers</div>
                <ul class="list-group list-group-flush">
                    @forelse ($figures['recent'] as $v)
                        <li class="list-group-item d-flex justify-content-between gap-2">
                            <a href="{{ route('vouchers.show', $v) }}" class="text-truncate">{{ $v->date->format('d M') }} · {{ $v->type->name }} {{ $v->voucher_number }}</a>
                            <span class="amount">{{ \App\Support\Money::format($v->total) }}</span>
                        </li>
                    @empty
                        <li class="list-group-item text-secondary small">No vouchers yet.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
@endif
