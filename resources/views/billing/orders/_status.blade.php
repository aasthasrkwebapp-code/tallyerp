@php($colour = ['Pending' => 'warning', 'Part delivered' => 'info', 'Delivered' => 'success', 'Closed' => 'secondary', 'Cancelled' => 'danger'][$label] ?? 'secondary')
<span class="badge text-bg-{{ $colour }} align-middle">{{ $label }}</span>
