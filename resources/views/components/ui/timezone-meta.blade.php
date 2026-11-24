{{-- D-15: the saved time zone; resources/js/timezone.js sends the browser's when it differs. --}}
@auth
    <meta name="ff-timezone" content="{{ auth()->user()->timezone ?? '' }}" data-url="{{ route('account.timezone') }}">
@endauth
