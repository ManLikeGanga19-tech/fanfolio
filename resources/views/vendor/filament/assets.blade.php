{{-- FanFolio override (decision D-07): identical to Filament's view except that its inline
     scripts carry the per-request CSP nonce. tests/Unit/Support/FilamentViewOverridesTest.php fails
     when Filament changes the original, so the copy is re-synced on upgrade. --}}
@if (isset($data))
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        window.filamentData = @js($data)
    </script>
@endif

@foreach ($assets as $asset)
    @if (! $asset->isLoadedOnRequest())
        {{ $asset->getHtml() }}
    @endif
@endforeach

<style>
    :root {
        @foreach ($cssVariables ?? [] as $cssVariableName => $cssVariableValue) --{{ $cssVariableName }}:{{ $cssVariableValue }}; @endforeach
    }

    @foreach ($customColors ?? [] as $customColorName => $customColorShades) .fi-color-{{ $customColorName }} { @foreach ($customColorShades as $customColorShade) --color-{{ $customColorShade }}:var(--{{ $customColorName }}-{{ $customColorShade }}); @endforeach } @endforeach
</style>
