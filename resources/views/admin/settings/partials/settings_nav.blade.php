<link rel="stylesheet" href="{{ asset('assets/css/settings-workspace.css') }}?v=20261015-1">
<nav class="settings-navigation mb-4" aria-label="Settings sections">
    @foreach(app(\App\Support\Settings\SettingsNavigation::class)->items(auth()->user()) as $item)
    <a href="{{ route($item['route']) }}" class="settings-navigation__link {{ $active === $item['key'] ? 'is-active' : '' }}" @if($active === $item['key']) aria-current="page" @endif>
        <i class="bi {{ $item['icon'] }}" aria-hidden="true"></i><span>{{ get_phrase($item['label']) }}</span>
    </a>
    @endforeach
</nav>
