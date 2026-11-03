@extends('admin.navigation')

@section('content')
<div class="mainSection-title">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h4>{{ get_phrase('Student Academic Exemptions') }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2">
                <li><a href="#">{{ get_phrase('Home') }}</a></li>
                <li><a href="#">{{ get_phrase('Academic') }}</a></li>
                <li aria-current="page">{{ get_phrase('Exemptions & Credit Transfers') }}</li>
            </ul>
        </div>
        <div class="export-btn-area">
            <a href="{{ route('admin.exemptions.create') }}" class="eBtn eBtn-primary">+ {{ get_phrase('New Exemption') }}</a>
        </div>
    </div>
</div>

<div class="eSection-wrap mt-3">
    @if($exemptions->count() > 0)
        <div class="table-responsive">
            <table class="table eTable align-middle">
                <thead>
                    <tr>
                        <th>{{ get_phrase('Student') }}</th>
                        <th>{{ get_phrase('Programme') }}</th>
                        <th>{{ get_phrase('Type') }}</th>
                        <th>{{ get_phrase('Status') }}</th>
                        <th>{{ get_phrase('Requested') }}</th>
                        <th class="text-end">{{ get_phrase('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($exemptions as $exemption)
                        <tr>
                            <td>{{ $exemption->student?->name ?? '—' }}</td>
                            <td>{{ $exemption->programme?->name ?? '—' }}</td>
                            <td>{{ $exemption->exemption_type === 'level' ? get_phrase('Entire Level') : get_phrase('Course Unit') }}</td>
                            <td><span class="badge bg-{{ $exemption->status === 'approved' ? 'success' : ($exemption->status === 'rejected' ? 'danger' : 'warning') }}">{{ get_phrase(ucfirst($exemption->status)) }}</span></td>
                            <td>{{ $exemption->created_at?->format('Y-m-d') }}</td>
                            <td class="text-end">
                                <a href="{{ route('admin.exemptions.show', $exemption->id) }}" class="eBtn eBtn-black eBtn-sm">{{ get_phrase('View') }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {!! $exemptions->links() !!}
    @else
        <div class="text-center py-5">
            <h5>{{ get_phrase('No exemption requests yet') }}</h5>
            <p class="text-muted">{{ get_phrase('Create an exemption request to recognize previously completed academic work.') }}</p>
        </div>
    @endif
</div>
@endsection