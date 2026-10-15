@extends('admin.navigation')
@section('content')
<div class="mainSection-title"><h4>{{ get_phrase('Notification Settings') }}</h4><p class="text-muted">{{ get_phrase('Manage email and SMS preferences. Changes take effect when you save.') }}</p></div>
@include('admin.settings.partials.settings_nav', ['active'=>'notifications'])
@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@php
            $email_notifs = [
                'notif_new_student'   => ['label' => 'New Student Registration', 'desc' => 'Send email when a new student registers'],
                'notif_fee_paid'      => ['label' => 'Fee Payment Received', 'desc' => 'Send email when a fee payment is confirmed'],
                'notif_exam_published'=> ['label' => 'Exam Published', 'desc' => 'Notify students when an exam is published'],
                'notif_assignment'    => ['label' => 'Assignment Posted', 'desc' => 'Notify students when a new assignment is posted'],
                'notif_leave_request' => ['label' => 'Leave Request Submitted', 'desc' => 'Notify admin when staff submits a leave request'],
                'notif_notice'        => ['label' => 'New Notice Posted', 'desc' => 'Notify all users when a notice is posted'],
            ];
            @endphp
@php
            $sms_notifs = [
                'sms_new_student' => ['label' => 'New Student SMS', 'desc' => 'Send SMS to new student on registration'],
                'sms_fee_paid'    => ['label' => 'Payment Confirmation SMS', 'desc' => 'Send SMS receipt on fee payment'],
                'sms_exam_result' => ['label' => 'Exam Result SMS', 'desc' => 'Send SMS when exam results are published'],
            ];
            @endphp
<form class="settings-preferences" data-workspace-continuous action="{{ route('admin.settings.notifications.save') }}" method="POST">
@csrf
<div class="row g-3">
@foreach(['email'=>['title'=>'Email Notifications','icon'=>'bi-envelope','description'=>'Choose which events use email notifications.','items'=>$email_notifs], 'sms'=>['title'=>'SMS Notifications','icon'=>'bi-phone','description'=>'Choose which events use text message notifications.','items'=>$sms_notifs]] as $channel=>$group)
<section class="col-12 col-lg-6" aria-labelledby="{{ $channel }}-heading"><div class="settings-preferences__card">
    <h5 id="{{ $channel }}-heading"><i class="bi {{ $group['icon'] }}" aria-hidden="true"></i> {{ get_phrase($group['title']) }}</h5>
    <p class="settings-preferences__intro">{{ get_phrase($group['description']) }}</p>
    @foreach($group['items'] as $key=>$info)
    <div class="settings-preference">
        <div><label for="{{ $key }}" class="settings-preference__label">{{ get_phrase($info['label']) }}</label><p class="settings-preference__description" id="{{ $key }}-description">{{ get_phrase($info['desc']) }}</p></div>
        <div class="form-check form-switch settings-preference__switch">
            <input type="checkbox" class="form-check-input" role="switch" id="{{ $key }}" name="{{ $key }}" aria-describedby="{{ $key }}-description" @checked($notif_settings[$key] ?? false) data-notification-switch>
            <span class="settings-preference__state" aria-hidden="true" data-switch-state>{{ ($notif_settings[$key] ?? false) ? get_phrase('On') : get_phrase('Off') }}</span>
        </div>
    </div>
    @endforeach
    @if($channel==='sms')<p class="alert alert-info mt-3 mb-0">{{ get_phrase("SMS requires an SMS gateway integration (Twilio, Africa's Talking, etc.)") }}</p>@endif
</div></section>
@endforeach
</div>
<div class="settings-preferences__actions"><button type="submit" class="eBtn eBtn-primary"><i class="bi bi-save" aria-hidden="true"></i> {{ get_phrase('Save Notification Settings') }}</button></div>
</form>
<script>
document.querySelectorAll('[data-notification-switch]').forEach(function (input) {
    input.addEventListener('change', function () {
        input.parentElement.querySelector('[data-switch-state]').textContent = input.checked ? @json(get_phrase('On')) : @json(get_phrase('Off'));
    });
});
</script>
@endsection
