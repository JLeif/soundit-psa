<footer class="site-footer mt-auto py-3 text-center small">
    &copy; {{ date('Y') }} {{ config('app.name') }}
    {{-- Reads the service, not a cache key. This previously read Cache::get('psa_version_current')
         directly, so it depended on a key the service no longer writes; once current() stopped
         caching, the badge would have vanished on every page with nothing reporting why. The
         guard is on the sentinel, not on truthiness: an array of empty strings is truthy, which
         is how this rendered a bare "v" in production. --}}
    @php $__version = app(\App\Services\VersionService::class)->current(); @endphp
    @if($__version['commit_short'] !== \App\Services\VersionService::UNKNOWN)
        <span class="ms-2 text-muted">
            <a href="{{ route('about') }}" class="text-reset" style="text-decoration: none;">v{{ $__version['commit_short'] }}</a>
        </span>
    @endif
</footer>
