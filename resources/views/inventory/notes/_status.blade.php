@php($colour = str_starts_with($label, 'Not') ? 'warning' : (str_starts_with($label, 'Part') ? 'info' : ($label === 'Cancelled' ? 'danger' : 'success')))
<span class="badge text-bg-{{ $colour }} align-middle">{{ $label }}</span>
