@php($badge = ['draft' => 'secondary', 'pending_approval' => 'warning', 'posted' => 'success', 'cancelled' => 'danger'][$status] ?? 'secondary')
<span class="badge text-bg-{{ $badge }}">{{ \App\Models\Voucher::STATUSES[$status] ?? $status }}</span>
