<x-layouts.app :title="$title">
    <article class="max-w-2xl py-8">
        <h1 class="text-h1">{{ $title }}</h1>
        <p class="mt-4 text-ink-muted">{{ config('fanfolio.legal.legal_text') }}</p>
    </article>
</x-layouts.app>
