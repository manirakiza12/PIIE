@extends('superadmin.navigation')

@section('content')

{{--
    SUPER ADMIN ENQUIRY INBOX — single enquiry.

    The visitor's message is rendered with `{{ }}`, so it is escaped and cannot inject
    markup into the Super Admin's browser. The stored value is untrusted public input
    and is treated as such everywhere it is displayed.

    Opening this page marks an unread enquiry as read. That is a convenience, not an
    audit event, so it deliberately does NOT stamp `handled_by` / `handled_at` — those
    are set by the explicit status form below.
--}}

<div class="row">
    <div class="col-12">
        <a href="{{ route('superadmin.enquiries.index') }}" class="btn btn-outline-secondary btn-sm mb-3">
            &larr; {{ get_phrase('Back to enquiries') }}
        </a>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="mb-0">{{ $enquiry->subject }}</h4>

                <span class="badge badge-{{ $enquiry->status === 'new' ? 'warning' : ($enquiry->status === 'spam' ? 'secondary' : 'success') }}">
                    {{ get_phrase(ucfirst($enquiry->status)) }}
                </span>
            </div>

            <div class="card-body">

                @if(session('success'))
                    <div class="alert alert-success">{{ session('success') }}</div>
                @endif

                {{-- A honeypot hit is surfaced to the administrator rather than hidden,
                     because an administrator deciding what to do with spam needs to know
                     why it was flagged. --}}
                @if($enquiry->looksLikeSpam())
                    <div class="alert alert-warning">
                        {{ get_phrase('Flagged automatically: a hidden field was filled in, which a person cannot do. Treated as spam.') }}
                    </div>
                @endif

                <dl class="row mb-4">
                    <dt class="col-sm-3">{{ get_phrase('From') }}</dt>
                    <dd class="col-sm-9">{{ $enquiry->name }}</dd>

                    <dt class="col-sm-3">{{ get_phrase('Email') }}</dt>
                    <dd class="col-sm-9">
                        <a href="mailto:{{ $enquiry->email }}">{{ $enquiry->email }}</a>
                    </dd>

                    @if($enquiry->phone)
                        <dt class="col-sm-3">{{ get_phrase('Telephone') }}</dt>
                        <dd class="col-sm-9">{{ $enquiry->phone }}</dd>
                    @endif

                    <dt class="col-sm-3">{{ get_phrase('Received') }}</dt>
                    <dd class="col-sm-9">{{ $enquiry->created_at?->format('d M Y, H:i') }}</dd>

                    {{-- IP and user agent are stored for spam triage. Shown to Super
                         Admin only, never on any public page. --}}
                    <dt class="col-sm-3">{{ get_phrase('Submitted from') }}</dt>
                    <dd class="col-sm-9">
                        <small class="text-muted">{{ $enquiry->ip_address ?: '—' }}</small>
                    </dd>

                    @if($enquiry->handled_at)
                        <dt class="col-sm-3">{{ get_phrase('Last handled') }}</dt>
                        <dd class="col-sm-9">
                            {{ $enquiry->handled_at->format('d M Y, H:i') }}
                        </dd>
                    @endif
                </dl>

                <h5>{{ get_phrase('Message') }}</h5>

                {{-- `nl2br` on an ESCAPED string, so line breaks survive without
                     allowing any markup. --}}
                <div style="white-space:normal; padding:1rem; background:#f8f9fa; border-radius:6px;">
                    {!! nl2br(e($enquiry->message)) !!}
                </div>

                <hr>

                <form method="POST"
                      action="{{ route('superadmin.enquiries.status', $enquiry->id) }}"
                      class="form-row align-items-end">
                    @csrf

                    <div class="col-sm-4">
                        <label for="enquiry-status">{{ get_phrase('Status') }}</label>
                        <select class="form-control" id="enquiry-status" name="status">
                            @foreach(\App\Models\WebsiteEnquiry::STATUSES as $piieStatus)
                                <option value="{{ $piieStatus }}" @selected($enquiry->status === $piieStatus)>
                                    {{ get_phrase(ucfirst($piieStatus)) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-sm-4">
                        <button type="submit" class="btn btn-primary">
                            {{ get_phrase('Update status') }}
                        </button>
                    </div>
                </form>

                <form method="POST"
                      action="{{ route('superadmin.enquiries.destroy', $enquiry->id) }}"
                      class="mt-3"
                      onsubmit="return confirm('{{ get_phrase('Delete this enquiry permanently?') }}');">
                    @csrf
                    <button type="submit" class="btn btn-outline-danger btn-sm">
                        {{ get_phrase('Delete permanently') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection
