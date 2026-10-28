@props(['name' => 'grid'])
@php
$paths = [
'grid' => 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
'network' => 'M9 3h6v6H9z M2 15h6v6H2z M16 15h6v6h-6z M12 9v3 M5 15v-3h14v3',
'check' => 'm5 12 4 4L19 6',
'clipboard' => 'M9 5H5v16h14V5h-4 M9 3h6v4H9z M8 12h8 M8 16h5',
'chart' => 'M4 3v18h17 M8 16v-4 M13 16V8 M18 16V5',
'file' => 'M14 2H5v20h14V7z M14 2v6h5 M8 12h8 M8 16h8',
'users' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M16 4a4 4 0 0 1 0 8 M22 21v-2a4 4 0 0 0-3-3.87 M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0',
'settings' => 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M9 3h6l1 3 3 1 2 5-2 5-3 1-1 3H9l-1-3-3-1-2-5 2-5 3-1z',
'chevron' => 'm6 9 6 6 6-6', 'menu' => 'M4 6h16 M4 12h16 M4 18h16', 'close' => 'm6 6 12 12 M18 6 6 18',
'arrow' => 'M4 12h16 m-6-6 6 6-6 6', 'plus' => 'M12 5v14 M5 12h14',
'info' => 'M12 11v6 M12 7h.01 M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0',
'alert' => 'm12 3 10 18H2z M12 9v4 M12 17h.01', 'clock' => 'M12 8v4l3 2 M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0',
'logout' => 'M9 21H3V3h6 M9 12h12 m-4-4 4 4-4 4', 'search' => 'm21 21-5-5 M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0',
];
@endphp
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" {{ $attributes->class('ui-icon') }}><path d="{{ $paths[$name] ?? $paths['grid'] }}" /></svg>