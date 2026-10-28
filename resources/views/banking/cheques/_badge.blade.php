@php($class = ['cleared' => 'success', 'pending' => 'warning', 'stale' => 'danger', 'post_dated' => 'info', 'cancelled' => 'secondary', 'reversed' => 'danger', 'draft' => 'light border', 'spoilt' => 'dark'][$status] ?? 'light')
<span class="badge text-bg-{{ $class }}">{{ \App\Services\Banking\ChequeRegister::STATUSES[$status] ?? $status }}</span>
