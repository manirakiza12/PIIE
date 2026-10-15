<div class="mainSection-title">
    <h4>{{ get_phrase('Dashboard') }}</h4>
    <p class="mt-2">{{ institution_name() }}</p>
</div>
<div class="row g-3">
    @foreach([
        ['superadmin.school.list', 'Institution Administration', 'Manage the institution and its administrator accounts.'],
        ['superadmin.website.index', 'Website Management', 'Manage website pages, sections, FAQs and public contact details.'],
        ['superadmin.enquiries.index', 'Website Enquiries', 'Review messages received through the public website.'],
        ['superadmin.system_settings', 'System Settings', 'Manage system identity, logos and email branding.'],
        ['superadmin.payment_settings', 'Payment Settings', 'Manage the existing payment configuration.'],
        ['superadmin.smtp_settings', 'SMTP Settings', 'Manage email delivery and send a test email.'],
        ['superadmin.google_meet.settings', 'Google Meet Setup', 'Configure the live-class integration.'],
        ['superadmin.library.institutions', 'Library Administration', 'Open institution library administration.'],
        ['superadmin.audit_log.index', 'Audit Logs', 'Review recorded system activity.'],
    ] as [$destination, $label, $description])
        <div class="col-12 col-md-6 col-xl-4">
            <div class="eSection-wrap h-100">
                <h5><a href="{{ route($destination) }}">{{ get_phrase($label) }}</a></h5>
                <p class="mt-2">{{ get_phrase($description) }}</p>
            </div>
        </div>
    @endforeach
</div>
