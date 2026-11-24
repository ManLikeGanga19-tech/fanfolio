{{-- Account hub · Currency (decision D-21): which currency the approximate amount next to credits uses. --}}
<x-layouts.app title="Currency">
    <div class="mx-auto flex max-w-md flex-col gap-4 py-8">
        <div class="flex items-center gap-2">
            <a href="{{ route('account') }}" class="flex size-11 items-center justify-center rounded-md text-ink hover:bg-surface-raised" aria-label="Back to account"><x-ui.icon name="arrow-left" :size="20" /></a>
            <h1 class="text-h2">Currency</h1>
        </div>
        <p class="text-ink-muted">Prices are in credits (100 credits = $1). Choose the currency for the approximate amount shown next to them. You are always charged in US dollars.</p>

        <form method="POST" action="{{ route('account.currency') }}" class="flex flex-col gap-4">
            @csrf
            <fieldset class="flex flex-col gap-2">
                <legend class="sr-only">Show amounts in</legend>
                @foreach ($options as $code => $label)
                    <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-md border border-border bg-surface px-3.5 has-checked:border-brand">
                        <input type="radio" name="currency" value="{{ $code }}" class="size-4 accent-brand" @checked($current === $code)>
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </fieldset>
            @error('currency')<p class="text-small text-danger" role="alert">{{ $message }}</p>@enderror
            <x-ui.button type="submit" variant="brand">Save</x-ui.button>
        </form>
    </div>
</x-layouts.app>
