{{-- Canvas AdminOverview: the wordmark with the panel's name as a label. --}}
<span class="ff-brand inline-flex items-baseline gap-2 text-h3 text-ink">
    {{ config('fanfolio.brand.name') }}
    <span @class(['font-sans text-label uppercase tracking-wide', 'text-danger' => $panel === 'Admin', 'text-brand' => $panel !== 'Admin'])>{{ $panel }}</span>
</span>
