@php($settingsLinks = app(\App\Support\Settings\SettingsNavigation::class)->items($user))
@if(count($settingsLinks))
<li class="nav-section-header">{{ get_phrase('SETTINGS') }}</li>
@foreach($settingsLinks as $item)
<li class="nav-links-li"><div class="iocn-link">
    <a href="{{ route($item['route']) }}" class="{{ request()->routeIs($item['route']) ? 'active' : '' }}" @if(request()->routeIs($item['route'])) aria-current="page" @endif>
        <div class="sidebar_icon"><i class="bi {{ $item['icon'] }}" aria-hidden="true"></i></div><span class="link_name">{{ get_phrase($item['label']) }}</span>
    </a>
</div></li>
@endforeach
@endif
