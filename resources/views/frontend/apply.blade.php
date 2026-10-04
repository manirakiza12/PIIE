@extends('frontend.index')

@php
    $openIntake = $intakeSessions->first();
@endphp

@section('content')

{{--
    PRESENTATION ONLY. The form, its fields, its validation, its submission route and
    the applicant account workflow underneath are untouched - this block adds the
    shared public chrome that every other page already had, so the page a visitor
    reaches from "Apply Now" is recognisably the same website as the one they clicked
    it from. Previously this view extended the shared layout but rendered no header,
    no footer, no <main> and no <h1>, which made it read as a different site.
--}}
<div class="piie-site">
    @include('frontend.partials.site_header')

    <main id="piie-main">
        @include('frontend.partials.blocks.page_hero', [
            'title'   => get_phrase('Apply Now'),
            'crumbs'  => [
                ['label' => 'Home', 'url' => route('landingPage')],
                ['label' => get_phrase('Apply Now'), 'url' => null],
            ],
        ])

<div class="container" style="max-width:1080px; margin-top:60px; margin-bottom:80px;">

    <div class="text-center mb-5">
        <p class="piie-lede" style="max-width:640px; margin:12px auto 0;">
            {{ get_phrase('Create an applicant account to start your application. Your progress is saved as you go, so you can complete it over several visits and track the outcome in one place.') }}
        </p>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="row g-4 align-items-stretch">
        {{-- Start / continue --}}
        <div class="col-lg-5">
            <div style="background:#fff; border:1px solid #e7e9ee; border-radius:14px; padding:32px; height:100%; box-shadow:0 6px 24px rgba(16,24,40,.06);">
                <h4 style="font-weight:700; margin-bottom:8px;">{{ get_phrase('Ready to apply?') }}</h4>
                <p class="text-muted" style="font-size:15px;">
                    {{ get_phrase('It takes a few minutes to get started. You will need your identification, academic certificates and the contact details of a next of kin.') }}
                </p>

                <a href="{{ route('applicant.register') }}" class="btn w-100 mb-2"
                   style="background:#8a1538; color:#fff; padding:13px; border-radius:9px; font-weight:600;">
                    {{ get_phrase('Start a New Application') }}
                </a>

                <a href="{{ route('applicant.login') }}" class="btn w-100"
                   style="background:#fff; color:#344054; border:1px solid #d0d5dd; padding:13px; border-radius:9px; font-weight:600;">
                    {{ get_phrase('Continue an Existing Application') }}
                </a>

                @if($openIntake)
                    <div style="margin-top:26px; padding-top:20px; border-top:1px dashed #e7e9ee; font-size:14.5px;">
                        <div style="font-weight:700; margin-bottom:6px;">{{ get_phrase('Current Intake') }}</div>
                        <div class="text-muted">{{ $openIntake->name }}</div>
                        @if($openIntake->close_date)
                            <div class="text-muted">
                                {{ get_phrase('Applications close') }}: {{ \Carbon\Carbon::parse($openIntake->close_date)->format('d M Y') }}
                            </div>
                        @endif
                        @if($openIntake->application_fee > 0)
                            <div class="text-muted">
                                {{ get_phrase('Application fee') }}: {{ \App\Support\Admissions\ApplicationFee::format((float) $openIntake->application_fee) }}
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        {{-- How it works --}}
        <div class="col-lg-7">
            <div style="background:#fff; border:1px solid #e7e9ee; border-radius:14px; padding:32px; height:100%;">
                <h4 style="font-weight:700; margin-bottom:20px;">{{ get_phrase('How it works') }}</h4>

                @foreach([
                    ['1', get_phrase('Create your account'), get_phrase('Sign up with your email — this is how you sign back in and how we contact you.')],
                    ['2', get_phrase('Complete your application'), get_phrase('Personal details, programme choice, education history and supporting documents. Save and return whenever you like.')],
                    ['3', get_phrase('Pay the application fee'), get_phrase('Pay online or upload proof of a bank deposit, where a fee applies to your intake.')],
                    ['4', get_phrase('Submit and track'), get_phrase('Follow every update from your dashboard, and download your offer letter as soon as a decision is made.')],
                ] as $stepRow)
                    <div class="d-flex gap-3 mb-4">
                        <div style="flex:0 0 34px; height:34px; border-radius:50%; background:#fdf2f5; color:#8a1538; display:flex; align-items:center; justify-content:center; font-weight:700;">
                            {{ $stepRow[0] }}
                        </div>
                        <div>
                            <div style="font-weight:700; font-size:15.5px;">{{ $stepRow[1] }}</div>
                            <div class="text-muted" style="font-size:14.5px;">{{ $stepRow[2] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- What's on offer --}}
    @if($programmes->isNotEmpty())
        <div style="background:#fff; border:1px solid #e7e9ee; border-radius:14px; padding:32px; margin-top:26px;">
            <h4 style="font-weight:700; margin-bottom:20px;">{{ get_phrase('Programmes Open for Application') }}</h4>

            <div class="row g-3">
                @foreach($programmes->groupBy('level') as $level => $group)
                    <div class="col-md-6 col-lg-4">
                        <div style="border:1px solid #e7e9ee; border-radius:10px; padding:18px; height:100%;">
                            <div style="font-size:12px; text-transform:uppercase; letter-spacing:.06em; color:#8a1538; font-weight:700; margin-bottom:10px;">
                                {{ $level ?: get_phrase('Other') }}
                            </div>
                            <ul style="padding-left:18px; margin:0; font-size:14.5px; color:#475467;">
                                @foreach($group as $programme)
                                    <li style="margin-bottom:6px;">{{ $programme->name }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
    </main>

    @include('frontend.partials.site_footer')
</div>

@endsection
