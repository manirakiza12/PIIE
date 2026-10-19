@extends('admin.navigation')

@section('content')
    @php
        $menu_permissions = empty($user->menu_permission) || $user->menu_permission == 'null' ? [] : json_decode($user->menu_permission, true);

        $info = json_decode($user->user_information);
        $user_image = $info->photo;
        if (!empty($info->photo)) {
            $user_image = 'uploads/user-images/' . $info->photo;
        } else {
            $user_image = 'uploads/user-images/thumbnail.png';
        }

        $routes = [
            'admin.admin' => get_phrase('Admin list'),
            'admin.teacher' => get_phrase('Teacher list'),
            'admin.accountant' => get_phrase('Accountant list'),
            'admin.librarian' => get_phrase('Librarian list'),
            'admin.parent' => get_phrase('Parent list'),
            'admin.student' => get_phrase('Students list'),
            'admin.teacher.permission' => get_phrase('Teacher Permission list'),
            'admin.offline_admission.single' => get_phrase('Addmission'),
            'admin.exam_category' => get_phrase('Exam Category'),
            'admin.offline_exam' => get_phrase('Offline Exam'),
            'admin.marks' => get_phrase('Marks'),
            'admin.grade_list' => get_phrase('Grades'),
            'admin.promotion' => get_phrase('Promotion'),
            'admin.daily_attendance' => get_phrase('Daily Attendance'),
            'admin.class_list' => get_phrase('Class List'),
            'admin.routine' => get_phrase('Class Routine'),
            'admin.subject_list' => get_phrase('Subject List'),
            'admin.gradebook' => get_phrase('Gradebooks'),
            'admin.syllabus' => get_phrase('Syllabus'),
            'admin.live_classes' => get_phrase('Live Classes'),
            'admin.online_exams' => get_phrase('Online Exams (Legacy Key)'),
            'admin.online_exams.index' => get_phrase('Online Exams'),
            'admin.question_bank' => get_phrase('Question Bank (Legacy Key)'),
            'admin.question_bank.index' => get_phrase('Question Bank'),
            'admin.class_room_list' => get_phrase('Class Room'),
            'admin.department_list' => get_phrase('Department'),
            'admin.fee_manager.list' => get_phrase('Students Fee Manager'),
            'admin.offline_payment_pending' => get_phrase('Fee Payments'),
            'admin.expense.list' => get_phrase('Expense Manager'),
            'admin.expense.category_list' => get_phrase('Expense Category'),
            'admin.book.book_list' => get_phrase('Book List Manager'),
            'admin.book_issue.list' => get_phrase('Book Issue Report'),
            'admin.noticeboard.list' => get_phrase('Noticeboard'),
            'admin.subscription' => get_phrase('Subscription'),
            'admin.events.list' => get_phrase('Events'),
            'admin.feedback.feedback_list' => get_phrase('Feedback'),
            'admin.appraisal.appraisalQuestions' => get_phrase('Appraisal'),
            'admin.appraisal.appraisalFeedback' => get_phrase('Appraisal Feedback'),
            'admin.settings.school' => get_phrase('School Settings'),
            'admin.settings.session_manager' => get_phrase('Session Manager'),
            'admin.settings.payment' => get_phrase('Payment settings'),
            'admin.profile' => get_phrase('My Account'),
        ];

    @endphp


    <div class="mainSection-title">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <h4>{{ get_phrase('Navigation Menu Settings') }}</h4>
                    <div class="export-btn-area">
                        <a href="{{ route('admin.admin') }}" class="export_btn"><i class="mx-2 bi bi-arrow-left"></i>{{ get_phrase('Back') }}</a>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <div class="row d-flex justify-content-center">
        <div class="col-7">
            <div class="eSection-wrap">
                <div class="eMain">
                    <div class="row">
                        <div class="col-6">
                            <div class="userImG d-flex justify-content-center mt-4">
                                <img width="40%" height="100px" src="{{ asset('assets') }}/{{ $user_image }}" alt="" />
                            </div>

                            <h4 class="mt-3 text-center">{{ $user->name }}</h4>
                            <p class="text-center">{{ $user->email }}</p>

                        </div>
                        <div class="col-6">
                            <div class="eForm-layouts">
                                <form method="POST" enctype="multipart/form-data" class="d-block ajaxForm" action="{{ route('admin.admin.menu_permission_update', ['id' => $user->id]) }}">
                                    @csrf

                                    @foreach ($routes as $route_name => $label)
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" id="{{ $route_name }}" name="permissions[]" value="{{ $route_name }}"
                                            @if (!empty($user->menu_permission) || $user->menu_permission != '') @if (in_array($route_name, $menu_permissions))
                                                checked @endif @else checked @endif
                                            />
                                            <label class="form-check-label" for="{{ $route_name }}">{{ $label }}</label>
                                        </div>
                                    @endforeach

                                    <div class="fpb-7 pt-2">
                                        <button class="btn-form" type="submit">{{ get_phrase('Update') }}</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
