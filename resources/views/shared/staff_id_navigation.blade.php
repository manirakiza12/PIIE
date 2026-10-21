@if(\App\Support\Staff\StaffIdCard::isStaff(auth()->user()))
<li class="nav-links-li {{ request()->routeIs('staff.id_card*') ? 'showMenu' : '' }}">
    <div class="iocn-link">
        <a href="{{ route('staff.id_card') }}" @if(request()->routeIs('staff.id_card*')) aria-current="page" @endif>
            <div class="sidebar_icon"><i class="bi bi-person-badge" aria-hidden="true"></i></div>
            <span class="link_name">{{ get_phrase('My ID Card') }}</span>
        </a>
    </div>
</li>
@endif
