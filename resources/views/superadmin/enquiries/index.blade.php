@extends('superadmin.navigation')

@section('content')

{{--
    SUPER ADMIN ENQUIRY INBOX — index.

    Behind `auth` + `superAdmin` (routes/web.php). Nothing here is reachable by a
    student, lecturer, parent or school admin.

    The list shows the visitor's words as they were submitted. There is no edit
    action anywhere in this module, on purpose: an enquiry is the only record the
    Institute has that a question was asked, and letting an administrator silently
    rewrite it would destroy that. The only mutations are the status and delete.
--}}

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="mb-0">{{ get_phrase('Website Enquiries') }}</h4>

                {{-- The count of unread enquiries, so the inbox announces itself
                     without an administrator having to open it. --}}
                @if(($counts['new'] ?? 0) > 0)
                    <span class="badge badge-warning">
                        {{ $counts['new'] }} new
                    </span>
                @endif
            </div>

            <div class="card-body">

                {{-- Status tabs. Counts are totals across every page of that status,
                     computed before pagination, so they never shrink to the size of
                     the current page. --}}
                <ul class="nav nav-pills mb-3">
                    @foreach([
                        'new' => 'New',
                        'read' => 'Read',
                        'answered' => 'Answered',
                        'spam' => 'Spam',
                    ] as $piieKey => $piieLabel)
                        <li class="nav-item">
                            <a class="nav-link {{ $activeStatus === $piieKey ? 'active' : '' }}"
                               href="{{ route('superadmin.enquiries.index', array_filter([
                                   'status' => $piieKey,
                                   'q' => $search ?: null,
                               ])) }}">
                                {{ get_phrase($piieLabel) }}
                                <span class="badge badge-light">{{ $counts[$piieKey] ?? 0 }}</span>
                            </a>
                        </li>
                    @endforeach

                    <li class="nav-item">
                        <a class="nav-link {{ $activeStatus === null ? 'active' : '' }}"
                           href="{{ route('superadmin.enquiries.index', array_filter(['q' => $search ?: null])) }}">
                            {{ get_phrase('All') }}
                            <span class="badge badge-light">{{ $counts['all'] ?? 0 }}</span>
                        </a>
                    </li>
                </ul>

                <form method="GET" action="{{ route('superadmin.enquiries.index') }}" class="form-row mb-3">
                    @if($status !== 'new')
                        <input type="hidden" name="status" value="{{ $status }}">
                    @endif

                    <div class="col-sm-8">
                        <label class="sr-only" for="enquiry-search">{{ get_phrase('Search enquiries') }}</label>
                        <input type="text"
                               class="form-control"
                               id="enquiry-search"
                               name="q"
                               value="{{ $search }}"
                               placeholder="{{ get_phrase('Search by name, email, subject or message') }}">
                    </div>

                    <div class="col-sm-4">
                        <button type="submit" class="btn btn-primary btn-block">
                            {{ get_phrase('Search') }}
                        </button>
                    </div>
                </form>

                @if(session('success'))
                    <div class="alert alert-success">{{ session('success') }}</div>
                @endif

                @if($enquiries->isEmpty())
                    <div class="alert alert-info">
                        @if($search !== '')
                            {{ get_phrase('No enquiries match that search.') }}
                        @elseif($activeStatus === 'new')
                            {{ get_phrase('There are no new enquiries.') }}
                        @else
                            {{ get_phrase('There are no enquiries with that status.') }}
                        @endif
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>{{ get_phrase('Received') }}</th>
                                    <th>{{ get_phrase('From') }}</th>
                                    <th>{{ get_phrase('Subject') }}</th>
                                    <th>{{ get_phrase('Status') }}</th>
                                    <th></th>
                                </tr>
                            </thead>

                            <tbody>
                                @foreach($enquiries as $piieEnquiry)
                                    <tr class="{{ $piieEnquiry->isUnread() ? 'font-weight-bold' : '' }}">
                                        <td style="white-space:nowrap;">
                                            {{ $piieEnquiry->created_at?->format('d M Y H:i') }}
                                        </td>

                                        <td>
                                            {{ $piieEnquiry->name }}
                                            <br>
                                            <small class="text-muted">{{ $piieEnquiry->email }}</small>
                                            @if($piieEnquiry->phone)
                                                <br><small class="text-muted">{{ $piieEnquiry->phone }}</small>
                                            @endif
                                        </td>

                                        <td>{{ $piieEnquiry->subject }}</td>

                                        <td>
                                            <span class="badge badge-{{ $piieEnquiry->status === 'new' ? 'warning' : ($piieEnquiry->status === 'spam' ? 'secondary' : 'success') }}">
                                                {{ get_phrase(ucfirst($piieEnquiry->status)) }}
                                            </span>
                                        </td>

                                        <td style="white-space:nowrap;">
                                            <a class="btn btn-sm btn-outline-primary"
                                               href="{{ route('superadmin.enquiries.show', $piieEnquiry->id) }}">
                                                {{ get_phrase('Open') }}
                                            </a>

                                            {{-- Delete is a POST with CSRF, never a GET
                                                 link: a GET would let any page on the
                                                 internet destroy an enquiry by loading
                                                 an image from a crafted URL. --}}
                                            <form method="POST"
                                                  action="{{ route('superadmin.enquiries.destroy', $piieEnquiry->id) }}"
                                                  class="d-inline"
                                                  onsubmit="return confirm('{{ get_phrase('Delete this enquiry permanently?') }}');">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    {{ get_phrase('Delete') }}
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {!! $enquiries->appends(request()->except('page'))->links() !!}
                @endif
            </div>
        </div>
    </div>
</div>

@endsection
