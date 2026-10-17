<nav class="d-flex flex-wrap gap-2 mb-3" aria-label="Academic units sections">
    <a class="btn {{ request()->routeIs('admin.department.schools_faculties') || request()->routeIs('admin.department.units.*') ? 'btn-primary' : 'btn-outline-secondary' }}" href="{{ route('admin.department.schools_faculties') }}">Schools &amp; Faculties</a>
    <a class="btn {{ request()->routeIs('admin.department.departments') || request()->routeIs('admin.department.show') || request()->routeIs('admin.department.open_modal') ? 'btn-primary' : 'btn-outline-secondary' }}" href="{{ route('admin.department.departments') }}">Departments</a>
</nav>
