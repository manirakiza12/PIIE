@extends('admin.navigation')

@section('content')
@if(get_settings('ai_teaching_assistant_enabled') === '1')
<div class="card-dashboard mb-3">
    <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div><h5 class="mb-1">AI Teaching Assistant Pro</h5><p class="text-muted mb-0">Open teaching and learning tools in a new tab.</p></div>
        <a class="btn btn-primary" href="https://www.aiteachingassistantpro.ca/" target="_blank" rel="noopener noreferrer">Open AI Teaching Assistant</a>
    </div>
</div>
@endif
{{-- The dashboard now uses the canonical admin sidebar (admin.navigation) like every other admin page.
     Font Awesome 6 (the stat-card icons) and the content-wrapper spacing below used to come from
     layouts.app; they are carried here so the dashboard looks exactly as before. --}}
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
    .main_content {
        padding: 20px 25px !important;
        overflow: visible !important;
    }
    .container-fluid {
        padding: 0 10px !important;
        overflow: visible !important;
    }
    @media (max-width: 768px) {
        .main_content {
            padding: 15px !important;
        }
    }

    /* ============================================ */
    /* FIXED DASHBOARD STYLES - Text Not Cut Off    */
    /* ============================================ */

    /* Dashboard Stats Cards */
    .dashboard-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }

    /* Explicit per-breakpoint card counts (desktop 4 / laptop 3 / tablet 2
       (see the 768px rule below) / mobile 1 (see the 480px rule below)),
       on top of the auto-fit default above so cards never overflow even
       at widths between these breakpoints. */
    @media (min-width: 1400px) {
        .dashboard-stats {
            grid-template-columns: repeat(4, 1fr);
        }
    }

    @media (min-width: 993px) and (max-width: 1399px) {
        .dashboard-stats {
            grid-template-columns: repeat(3, 1fr);
        }
    }

    .stat-card {
        background: #ffffff;
        border-radius: 12px;
        padding: 18px 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border-left: 4px solid #1a3a6b;
        transition: all 0.3s ease;
        position: relative;
        overflow: visible;
    }

    .stat-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    }

    .stat-card .stat-number {
        font-size: 24px;
        font-weight: 700;
        color: #1a2332;
        margin-bottom: 2px;
        display: block;
    }

    .stat-card .stat-label {
        font-size: 12px;
        color: #6c757d;
        font-weight: 500;
        display: block;
        white-space: normal;
        word-wrap: break-word;
    }

    .stat-card .stat-icon {
        position: absolute;
        right: 14px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 28px;
        opacity: 0.08;
        color: #1a3a6b;
    }

    .stat-card.blue { border-left-color: #1a3a6b; }
    .stat-card.gold { border-left-color: #c8860a; }
    .stat-card.green { border-left-color: #198754; }
    .stat-card.red { border-left-color: #dc3545; }
    .stat-card.purple { border-left-color: #6f42c1; }
    .stat-card.teal { border-left-color: #20c997; }

    .stat-card.blue .stat-icon { color: #1a3a6b; }
    .stat-card.gold .stat-icon { color: #c8860a; }
    .stat-card.green .stat-icon { color: #198754; }
    .stat-card.red .stat-icon { color: #dc3545; }
    .stat-card.purple .stat-icon { color: #6f42c1; }
    .stat-card.teal .stat-icon { color: #20c997; }

    /* Card Styles */
    .card-dashboard {
        background: #ffffff;
        border-radius: 12px;
        border: 1px solid #e9ecef;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        margin-bottom: 20px;
        overflow: visible;
    }

    .card-dashboard .card-header {
        padding: 14px 18px;
        border-bottom: 1px solid #e9ecef;
        background: #fafbfc;
        font-weight: 600;
        font-size: 14px;
        color: #1a2332;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .card-dashboard .card-body {
        padding: 16px 18px;
        overflow: visible;
    }

    /* Badge Status */
    .badge-status {
        padding: 2px 10px;
        border-radius: 12px;
        font-size: 10px;
        font-weight: 600;
        display: inline-block;
        white-space: nowrap;
    }

    .badge-status.active { background: #d1f5e0; color: #198754; }
    .badge-status.inactive { background: #fce4e4; color: #842029; }

    /* School Item */
    .school-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 0;
        border-bottom: 1px solid #f5f5f5;
        overflow: visible;
    }

    .school-item:last-child {
        border-bottom: none;
    }

    .school-item .school-avatar {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        background: #1a3a6b;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 14px;
        flex-shrink: 0;
    }

    .school-item .school-info {
        flex: 1;
        min-width: 0;
    }

    .school-item .school-info .school-name {
        font-weight: 600;
        color: #1a2332;
        font-size: 13px;
        white-space: normal;
        word-wrap: break-word;
    }

    .school-item .school-info .school-details {
        font-size: 11px;
        color: #6c757d;
        white-space: normal;
        word-wrap: break-word;
    }

    /* Event Item */
    .event-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 0;
        border-bottom: 1px solid #f5f5f5;
    }

    .event-item:last-child {
        border-bottom: none;
    }

    .event-item .event-date {
        min-width: 40px;
        font-size: 11px;
        color: #6c757d;
        text-align: center;
        line-height: 1.3;
    }

    .event-item .event-date .day {
        font-size: 18px;
        font-weight: 700;
        color: #1a2332;
        display: block;
    }

    .event-item .event-info {
        flex: 1;
        min-width: 0;
    }

    .event-item .event-info .title {
        font-weight: 500;
        color: #1a2332;
        font-size: 13px;
        white-space: normal;
        word-wrap: break-word;
    }

    .event-item .event-info .meta {
        font-size: 11px;
        color: #6c757d;
    }

    /* Table */
    .table-superadmin {
        width: 100%;
        font-size: 13px;
        border-collapse: collapse;
    }

    .table-superadmin thead th {
        background: #f8f9fa;
        padding: 10px 14px;
        text-align: left;
        font-weight: 600;
        color: #1a2332;
        border-bottom: 2px solid #e9ecef;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        white-space: nowrap;
    }

    .table-superadmin tbody td {
        padding: 10px 14px;
        border-bottom: 1px solid #f0f0f0;
        vertical-align: middle;
        color: #495057;
        white-space: normal;
        word-wrap: break-word;
    }

    .table-superadmin tbody tr:hover {
        background: #f8f9fa;
    }

    /* Two Column Layout */
    .row-two {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 24px;
    }

    @media (max-width: 992px) {
        .row-two {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .dashboard-stats {
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .stat-card {
            padding: 14px 16px;
        }
        .stat-card .stat-number {
            font-size: 20px;
        }
    }

    @media (max-width: 480px) {
        .dashboard-stats {
            grid-template-columns: 1fr;
        }
    }

    /* Section Header */
    .section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
        flex-wrap: wrap;
        gap: 12px;
    }

    .section-header .section-title {
        font-size: 20px;
        font-weight: 700;
        color: #1a2332;
        margin: 0;
    }

    .section-header .section-title small {
        font-size: 14px;
        font-weight: 400;
        color: #6c757d;
        display: block;
        margin-top: 2px;
    }

    .btn-primary-custom {
        padding: 8px 18px;
        border-radius: 8px;
        border: none;
        background: #1a3a6b;
        color: #ffffff;
        font-weight: 500;
        font-size: 13px;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .btn-primary-custom:hover {
        background: #0d1f3c;
        color: #ffffff;
        text-decoration: none;
    }

    .btn-outline-primary-custom {
        padding: 8px 18px;
        border-radius: 8px;
        border: 1.5px solid #1a3a6b;
        background: transparent;
        color: #1a3a6b;
        font-weight: 500;
        font-size: 13px;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .btn-outline-primary-custom:hover {
        background: #1a3a6b;
        color: #ffffff;
        text-decoration: none;
    }
</style>

<div class="container-fluid">

    <!-- ============================================ -->
    <!-- WELCOME SECTION                             -->
    <!-- ============================================ -->
    <div class="section-header">
        <div>
            <h1 class="section-title">
                Dashboard
                <small>Home - Dashboard</small>
            </h1>
        </div>
        <div class="section-actions">
            <a href="{{ route('admin.hei_admissions.index') }}" class="btn-primary-custom">
                <i class="fas fa-plus"></i> New Admission
            </a>
            <a href="{{ route('admin.student') }}" class="btn-outline-primary-custom">
                <i class="fas fa-user-graduate"></i> Add Student
            </a>
        </div>
    </div>

    @if(!empty($admissionsAvailable) && !empty($admissionsActionItems))
    <!-- ============================================ -->
    <!-- NEEDS YOUR ACTION                            -->
    <!-- Computed live from current Admissions data — -->
    <!-- nothing here is a stored/dismissible          -->
    <!-- notification, so it never goes stale and      -->
    <!-- never needs a "mark as read".                 -->
    <!-- ============================================ -->
    <div class="mb-4" style="background:#fff7e6; border:1px solid #ffe0a3; border-radius:12px; padding:18px 22px;">
        <div class="d-flex align-items-center gap-2 mb-2">
            <i class="bi bi-bell-fill" style="color:#b54708;"></i>
            <strong style="color:#b54708; font-size:15px;">{{ get_phrase('Needs Your Action') }}</strong>
        </div>
        <div class="d-flex flex-wrap gap-3">
            @foreach($admissionsActionItems as $item)
                <a href="{{ $item['url'] }}" class="d-flex align-items-center gap-2"
                   style="background:#fff; border:1px solid #ffe0a3; border-radius:8px; padding:10px 16px; color:#0C141D; text-decoration:none; font-size:14px;">
                    <i class="bi {{ $item['icon'] }}" style="color:#b54708;"></i>
                    <span class="badge bg-warning text-dark">{{ $item['count'] }}</span>
                    {{ $item['label'] }}
                </a>
            @endforeach
        </div>
    </div>
    @endif

    <!-- ============================================ -->
    <!-- STATS CARDS (this school only)               -->
    <!-- ============================================ -->
    <div class="dashboard-stats">
        <div class="stat-card blue">
            <span class="stat-number">{{ $totalStudents ?? 0 }}</span>
            <span class="stat-label">Total Students</span>
            <i class="fas fa-user-graduate stat-icon"></i>
        </div>

        <div class="stat-card green">
            <span class="stat-number">{{ $activeStudents ?? 0 }}</span>
            <span class="stat-label">Active Students</span>
            <i class="fas fa-check-circle stat-icon"></i>
        </div>

        <div class="stat-card gold">
            <span class="stat-number">{{ $totalTeachers ?? 0 }}</span>
            <span class="stat-label">Teachers</span>
            <i class="fas fa-chalkboard-teacher stat-icon"></i>
        </div>

        <div class="stat-card purple">
            <span class="stat-number">{{ $totalStaff ?? 0 }}</span>
            <span class="stat-label">Staff</span>
            <i class="fas fa-user-shield stat-icon"></i>
        </div>

        <div class="stat-card teal">
            <span class="stat-number">{{ $totalParents ?? 0 }}</span>
            <span class="stat-label">Parents</span>
            <i class="fas fa-users stat-icon"></i>
        </div>

        <div class="stat-card red">
            <span class="stat-number">{{ $totalClasses ?? 0 }}</span>
            <span class="stat-label">Classes</span>
            <i class="fas fa-school stat-icon"></i>
        </div>

        <div class="stat-card blue">
            <span class="stat-number">{{ $totalCourses ?? 0 }}</span>
            <span class="stat-label">Courses / Programmes</span>
            <i class="fas fa-graduation-cap stat-icon"></i>
        </div>

        <div class="stat-card green">
            <span class="stat-number">{{ $totalSubjects ?? 0 }}</span>
            <span class="stat-label">Subjects</span>
            <i class="fas fa-book stat-icon"></i>
        </div>

        <div class="stat-card gold">
            <span class="stat-number">{{ $attendanceToday ?? 0 }}</span>
            <span class="stat-label">Attendance Marked Today</span>
            <i class="fas fa-calendar-check stat-icon"></i>
        </div>

        <div class="stat-card purple">
            <span class="stat-number">{{ $classesToday ?? 0 }}</span>
            <span class="stat-label">Classes on Today's Timetable</span>
            <i class="fas fa-clock stat-icon"></i>
        </div>

        <div class="stat-card teal">
            <span class="stat-number">{{ currency(number_format($feeCollectedThisMonth ?? 0)) }}</span>
            <span class="stat-label">Fee Collected This Month</span>
            <i class="fas fa-coins stat-icon"></i>
        </div>

        <div class="stat-card red">
            <span class="stat-number">{{ currency(number_format($outstandingFees ?? 0)) }}</span>
            <span class="stat-label">Outstanding Fees</span>
            <i class="fas fa-file-invoice-dollar stat-icon"></i>
        </div>

        <div class="stat-card blue">
            <span class="stat-number">{{ $pendingApprovals ?? 0 }}</span>
            <span class="stat-label">Pending Admission Approvals</span>
            <i class="fas fa-hourglass-half stat-icon"></i>
        </div>

        <div class="stat-card green">
            <span class="stat-number">{{ $booksIssued ?? 0 }} / {{ $totalBooks ?? 0 }}</span>
            <span class="stat-label">Books Issued / Library Titles</span>
            <i class="fas fa-book-reader stat-icon"></i>
        </div>
    </div>

    <!-- ============================================ -->
    <!-- TWO COLUMN LAYOUT                           -->
    <!-- ============================================ -->
    <div class="row-two">

        <!-- ============================================ -->
        <!-- LEFT COLUMN - Recent Admissions               -->
        <!-- ============================================ -->
        <div class="card-dashboard">
            <div class="card-header">
                <span><i class="fas fa-user-plus" style="color: #1a3a6b; margin-right: 8px;"></i>Recent Admissions</span>
                <a href="{{ route('admin.hei_admissions.index') }}" style="font-size: 12px; color: #1a3a6b; text-decoration: none; font-weight: 500;">View All →</a>
            </div>
            <div class="card-body">
                @if(isset($recentAdmissions) && count($recentAdmissions) > 0)
                    @foreach($recentAdmissions as $application)
                    <div class="school-item">
                        <div class="school-avatar">{{ substr($application->first_name ?? 'A', 0, 2) }}</div>
                        <div class="school-info">
                            <div class="school-name">{{ trim(($application->first_name ?? '') . ' ' . ($application->last_name ?? '')) ?: 'Applicant' }}</div>
                            <div class="school-details">
                                {{ optional($application->programme)->name ?? 'No programme' }}
                                <span class="badge-status {{ $application->status === 'enrolled' ? 'active' : 'inactive' }}">
                                    {{ ucfirst(str_replace('_', ' ', $application->status)) }}
                                </span>
                            </div>
                        </div>
                        <a href="{{ route('admin.hei_admissions.index') }}" style="color: #adb5bd; font-size: 12px; flex-shrink: 0;">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                    @endforeach
                @else
                    <div style="text-align: center; padding: 30px 20px; color: #6c757d;">
                        <i class="fas fa-user-plus" style="font-size: 28px; opacity: 0.2; display: block; margin-bottom: 8px;"></i>
                        <p style="font-size: 13px; margin: 0;">No admissions yet.</p>
                    </div>
                @endif
            </div>
        </div>

        <!-- ============================================ -->
        <!-- RIGHT COLUMN - Recent Announcements           -->
        <!-- ============================================ -->
        <div class="card-dashboard">
            <div class="card-header">
                <span><i class="fas fa-bullhorn" style="color: #c8860a; margin-right: 8px;"></i>Recent Announcements</span>
                <a href="{{ route('admin.noticeboard.list') }}" style="font-size: 12px; color: #1a3a6b; text-decoration: none; font-weight: 500;">View All →</a>
            </div>
            <div class="card-body">
                @if(isset($recentAnnouncements) && count($recentAnnouncements) > 0)
                    @foreach($recentAnnouncements as $notice)
                    <div class="event-item">
                        <div class="event-info">
                            <div class="title">{{ $notice->notice_title }}</div>
                            <div class="meta">{{ optional($notice->created_at)->format('l, M d Y') }}</div>
                        </div>
                    </div>
                    @endforeach
                @else
                    <div style="text-align: center; padding: 20px; color: #6c757d; font-size: 13px;">
                        No announcements yet
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- ============================================ -->
    <!-- TWO COLUMN LAYOUT — EVENTS / CALENDAR        -->
    <!-- ============================================ -->
    <div class="row-two">

        <div class="card-dashboard">
            <div class="card-header">
                <span><i class="fas fa-calendar-alt" style="color: #1a3a6b; margin-right: 8px;"></i>Upcoming Events</span>
                <a href="{{ route('admin.events.list') }}" style="font-size: 12px; color: #1a3a6b; text-decoration: none; font-weight: 500;">See all →</a>
            </div>
            <div class="card-body">
                @if(isset($upcomingEvents) && count($upcomingEvents) > 0)
                    @foreach($upcomingEvents as $event)
                    <div class="event-item">
                        <div class="event-date">
                            <span class="day">{{ date('d', $event->timestamp) }}</span>
                            {{ date('M', $event->timestamp) }}
                        </div>
                        <div class="event-info">
                            <div class="title">{{ $event->title }}</div>
                            <div class="meta">{{ date('l, M d Y', $event->timestamp) }}</div>
                        </div>
                    </div>
                    @endforeach
                @else
                    <div style="text-align: center; padding: 20px; color: #6c757d; font-size: 13px;">
                        No upcoming events
                    </div>
                @endif
            </div>
        </div>

        <div class="card-dashboard">
            <div class="card-header">
                <span><i class="fas fa-calendar-week" style="color: #c8860a; margin-right: 8px;"></i>School Calendar</span>
                <a href="{{ route('admin.academic_calendar.index') }}" style="font-size: 12px; color: #1a3a6b; text-decoration: none; font-weight: 500;">See all →</a>
            </div>
            <div class="card-body">
                @if(isset($upcomingCalendar) && count($upcomingCalendar) > 0)
                    @foreach($upcomingCalendar as $calendarEvent)
                    <div class="event-item">
                        <div class="event-date">
                            <span class="day">{{ \Carbon\Carbon::parse($calendarEvent->event_date)->format('d') }}</span>
                            {{ \Carbon\Carbon::parse($calendarEvent->event_date)->format('M') }}
                        </div>
                        <div class="event-info">
                            <div class="title">{{ $calendarEvent->title }}</div>
                            <div class="meta">{{ \Carbon\Carbon::parse($calendarEvent->event_date)->format('l, M d Y') }}</div>
                        </div>
                        <span style="padding: 2px 10px; border-radius: 12px; font-size: 9px; font-weight: 600; background: #dbeafe; color: #1a3a6b; flex-shrink: 0;">
                            {{ $calendarEvent->event_type ?? 'Event' }}
                        </span>
                    </div>
                    @endforeach
                @else
                    <div style="text-align: center; padding: 20px; color: #6c757d; font-size: 13px;">
                        No calendar entries yet
                    </div>
                @endif
            </div>
        </div>
    </div>

</div>

@endsection
