<div class="mainSection-title">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h4>Applicant PesaPal settings</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2"><li><a href="{{ route('admin.dashboard') }}">Dashboard</a></li><li>PesaPal settings</li></ul>
        </div>
        @if(is_primary_school(auth()->user()->school_id) && app(\App\Support\Permissions\PermissionService::class)->allows(auth()->user(), 'admissions.view'))
            <a class="export_btn export_btn-outline" href="{{ route('admin.hei_admissions.index') }}">View admissions</a>
        @endif
    </div>
</div>
@if($errors->any())
    <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
@if(session('error'))<div class="alert alert-danger" role="alert">{{ session('error') }}</div>@endif
@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
<div class="row g-4">
    <div class="col-12 col-xl-8">
        <div class="eSection-wrap h-100">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h5 class="mb-0">Shared application payment configuration</h5>
                <span class="badge {{ $configured ? 'bg-success' : 'bg-secondary' }}">{{ $configured ? 'Credentials configured' : 'Credentials not configured' }}</span>
            </div>
            <p class="text-muted">Used by online and administrator-created applications. Resolve outstanding orders before replacing credentials. Configured does not mean sandbox checkout has been tested.</p>
            <form method="post" action="{{ route('admin.hei_admissions.payment.pesapal.settings.save') }}">
                @csrf
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="eForm-label" for="pesapal-environment">Environment</label>
                        <select class="form-select eForm-select" id="pesapal-environment" name="environment" required aria-describedby="environment-help">
                            <option value="sandbox" @selected(old('environment', $environment) === 'sandbox')>Sandbox (test payments)</option>
                            <option value="live" @selected(old('environment', $environment) === 'live')>Live (real payments)</option>
                        </select>
                        <small class="text-muted d-block mt-2" id="environment-help">Select Sandbox for local integration testing.</small>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="eForm-label" for="pesapal-key">Consumer Key</label>
                        <input class="form-control eForm-control" type="password" id="pesapal-key" name="consumer_key" autocomplete="new-password" maxlength="512" aria-describedby="credentials-help">
                    </div>
                    <div class="col-12">
                        <label class="eForm-label" for="pesapal-secret">Consumer Secret</label>
                        <input class="form-control eForm-control" type="password" id="pesapal-secret" name="consumer_secret" autocomplete="new-password" maxlength="512" aria-describedby="credentials-help">
                        <small class="text-muted d-block mt-2" id="credentials-help">Credentials are encrypted at rest and never displayed. Leave fields blank to keep existing credentials. Initial setup and environment changes require both fields.</small>
                    </div>
                    <div class="col-12"><button type="submit" class="btn-form">Save configuration</button></div>
                </div>
            </form>
        </div>
    </div>
    <div class="col-12 col-xl-4">
        <div class="eSection-wrap h-100">
            <h5>Payment notifications (IPN)</h5>
            <p><span class="badge {{ $notificationId ? 'bg-success' : 'bg-warning text-dark' }}">{{ $notificationId ? 'IPN registered' : 'IPN not registered' }}</span></p>
            <p class="text-muted">A public HTTPS notification URL is required. Saving credentials does not register an IPN or initiate a payment.</p>
            @if($configured)
                <form method="post" action="{{ route('admin.hei_admissions.payment.pesapal.settings.register') }}">@csrf<button type="submit" class="btn btn-outline-primary">Register HTTPS IPN with PesaPal</button></form>
            @else<p class="text-muted">Save credentials before registering an IPN.</p>@endif
            <p class="text-muted mt-3 mb-0">Registration is a separate provider operation. Online payments are confirmed only after server-side PesaPal verification.</p>
        </div>
    </div>
    <div class="col-12" id="failed-notifications">
        <div class="eSection-wrap">
            <h5>Undelivered admission notifications</h5>
            @if(!$notificationStorageReady)
                <div class="alert alert-warning mb-0" role="status">Notification tracking is unavailable: its database migration has not been applied. This is not evidence that all notifications were delivered.</div>
            @else
                <p class="text-muted">Scheduled retries run every five minutes when the scheduler is installed. Five unsuccessful attempts require administrator attention. Fix mail delivery before retrying.</p>
                <div class="table-responsive">
                    <table class="table eTable mb-0">
                        <thead><tr><th scope="col">Delivery</th><th scope="col">Attempts</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
                        <tbody>
                        @forelse($deliveries as $delivery)
                            <tr>
                                <td>#{{ $delivery->id }}</td><td>{{ $delivery->attempts }}</td>
                                <td>{{ $delivery->attempts >= 5 ? 'Administrator attention required' : 'Awaiting scheduled retry' }}</td>
                                <td>
                                    @if($delivery->attempts >= 5 && filled($delivery->available_at) && \Illuminate\Support\Carbon::parse($delivery->available_at)->lessThanOrEqualTo(now()))
                                        <form method="post" action="{{ route('admin.hei_admissions.payment.pesapal.notification.retry', $delivery->id) }}">@csrf<button type="submit" class="btn btn-outline-primary btn-sm">Retry notification</button></form>
                                    @else<span class="text-muted">Scheduled</span>@endif
                                </td>
                            </tr>
                        @empty<tr><td colspan="4" class="text-muted">No outstanding notification records.</td></tr>@endforelse
                        </tbody>
                    </table>
                </div>
                <small class="text-muted d-block mt-2">Shows the 20 most recent outstanding deliveries for your school.</small>
            @endif
        </div>
    </div>
</div>
