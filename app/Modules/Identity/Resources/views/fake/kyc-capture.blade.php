{{-- Decision D-14, development only: stands in for the verification partner's hosted capture page.
     Any ID photo and any selfie are accepted. The image fields have no name, so the browser never sends
     them: previews stay on this device (rule 10). Only the outcome is submitted. --}}
<x-layouts.auth title="Verification (development stand-in)">
    <div class="flex flex-col gap-8" data-fake-kyc>
        <p class="rounded-md border border-warning p-4 text-small" role="note">
            Development stand-in for the verification partner. Anything is accepted, and nothing you capture leaves this device.
        </p>

        <section class="flex flex-col gap-3" aria-labelledby="id-heading">
            <p class="text-label text-ink-muted">STEP 2 OF 4 · IDENTITY DOCUMENT</p>
            <h1 id="id-heading" class="text-h1">Scan your ID</h1>
            <label class="flex flex-col gap-2">
                <span class="text-label">Front of your ID</span>
                {{-- No name attribute: never uploaded. --}}
                <input type="file" accept="image/*" capture="environment" data-preview-target="id-preview"
                    class="min-h-11 rounded-md border border-border-strong bg-surface-raised p-2 text-small text-ink">
            </label>
            <img id="id-preview" alt="Preview of your ID photo" class="hidden max-h-56 rounded-md object-contain">
        </section>

        <section class="flex flex-col gap-3" aria-labelledby="selfie-heading">
            <p class="text-label text-ink-muted">STEP 3 OF 4 · PROVE IT'S YOU</p>
            <h2 id="selfie-heading" class="text-h2">Selfie liveness check</h2>
            <p class="text-small text-ink-muted">Look at the camera and slowly turn your head left, then right.</p>

            <video data-camera class="hidden aspect-[3/4] w-full max-w-xs rounded-lg bg-surface object-cover" autoplay playsinline muted aria-label="Live camera"></video>
            <canvas data-snapshot class="hidden w-full max-w-xs rounded-lg" aria-label="Your selfie"></canvas>
            <p data-camera-status class="text-small text-ink-muted" aria-live="polite"></p>

            <div class="flex flex-col gap-2">
                <x-ui.button type="button" data-camera-start>Start camera</x-ui.button>
                <x-ui.button type="button" data-camera-take hidden>Take selfie</x-ui.button>
            </div>

            {{-- Where the live camera is not available (for example plain http on a phone): the phone's own camera. --}}
            <label class="flex flex-col gap-2">
                <span class="text-label">Or take a selfie with the camera app</span>
                <input type="file" accept="image/*" capture="user" data-preview-target="selfie-preview"
                    class="min-h-11 rounded-md border border-border-strong bg-surface-raised p-2 text-small text-ink">
            </label>
            <img id="selfie-preview" alt="Preview of your selfie" class="hidden max-h-56 rounded-md object-contain">
        </section>

        <form method="POST" action="{{ route('fake.kyc.capture', ['ref' => $ref]) }}" class="flex flex-col gap-4">
            @csrf
            <div class="flex flex-col gap-1">
                <label for="field-outcome" class="text-label">Result to return (development)</label>
                <select id="field-outcome" name="outcome" class="min-h-11 rounded-md border border-border-strong bg-surface-raised px-3 py-2 text-ink">
                    @foreach ($outcomes as $outcome)
                        <option value="{{ $outcome }}" @selected($outcome === 'pass')>{{ ucfirst($outcome) }}</option>
                    @endforeach
                </select>
            </div>
            <x-ui.button variant="brand" type="submit">Finish</x-ui.button>
        </form>
    </div>
</x-layouts.auth>
