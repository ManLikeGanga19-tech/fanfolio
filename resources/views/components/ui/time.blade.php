@props(['at', 'format' => 'j M, H:i'])
{{-- D-15: a stored UTC time, shown in the signed-in user's time zone, with a machine-readable datetime. --}}
@if ($at)
    <time datetime="{{ $at->toIso8601String() }}" {{ $attributes }}>{{ $at->copy()->setTimezone(\Filament\Support\Facades\FilamentTimezone::get())->format($format) }}</time>
@endif
