@extends('teacher.navigation')
   
@section('content')
    <!-- Start User Profile area -->
    <div class="user-profile-area d-flex flex-wrap">
        <!-- Left side -->
        <div class="user-info d-flex flex-column">
            <div
            class="user-info-basic d-flex flex-column justify-content-center"
            >
            <div class="user-graphic-element-1">
                <img src="{{ asset('assets/images/sprial_1.png') }}" alt="" />
            </div>
            <div class="user-graphic-element-2">
                <img src="{{ asset('assets/images/polygon_1.png') }}" alt="" />
            </div>
            <div class="user-graphic-element-3">
                <img src="{{ asset('assets/images/circle_1.png') }}" alt="" />
            </div>
            <div class="userImg">
                <img width="100%" src="{{ get_user_image(auth()->user()->id) }}" alt="" />
            </div>
            <div class="userContent text-center">
                <h4 class="title">{{ auth()->user()->name }}</h4>
                <p class="info">{{ get_phrase('Teacher') }}</p>
                <p class="user-status-verify">{{ get_phrase('Verified') }}</p>
            </div>
            </div>
            <div class="user-info-edit">
            <div
                class="user-edit-title d-flex justify-content-between align-items-center"
            >
                <h3 class="title">{{ get_phrase('Details info') }}</h3>
            </div>
            <div class="user-info-edit-items">
                <div class="item">
                <p class="title">{{ get_phrase('Email') }}</p>
                <p class="info">{{ auth()->user()->email }}</p>
                </div>
                <div class="item">
                <p class="title">{{ get_phrase('Phone Number') }}</p>
                <p class="info">{{ json_decode(auth()->user()->user_information, true)['phone'] }}</p>
                </div>
                <div class="item">
                <p class="title">{{ get_phrase('Address') }}</p>
                <p class="info">
                {{ json_decode(auth()->user()->user_information, true)['address'] }}
                </p>
                </div>
            </div>
            </div>
        </div>
        <!-- Right side -->
        <div class="user-details-info">
            
            <!-- Tab content -->
            <div class="tab-content eNav-Tabs-content" id="myTabContent">
            <div
                class="tab-pane fade show active"
                id="basicInfo"
                role="tabpanel"
                aria-labelledby="basicInfo-tab"
            >
                <div class="eForm-layouts">
                <form action="{{ route('teacher.password', 'update') }}" method="post">
                    @CSRF
                    @if(auth()->user()->force_password_change)
                        <div class="alert alert-info">Change your temporary password to finish account setup and open Messages. Enter your temporary password as the current password.</div>
                    @endif
                    @if($errors->any())
                        <div class="alert alert-danger" role="alert">
                            @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                        </div>
                    @endif

                    <div class="fpb-7">
                    <label for="new_password" class="eForm-label">{{ get_phrase('New Password') }}</label>
                    <input
                        type="password"
                        class="form-control eForm-control"
                        id="new_password"
                        name="new_password"
                        placeholder="Your current password"
                    />
                    </div>

                    <div class="fpb-7">
                    <label for="confirm_password" class="eForm-label">{{ get_phrase('Confirm Password') }}</label>
                    <input
                        type="password"
                        class="form-control eForm-control"
                        id="confirm_password"
                        name="confirm_password"
                        placeholder="Your current password"
                    />
                    </div>

                    <div class="fpb-7">
                    <label for="old_password" class="eForm-label">{{ get_phrase('Current Password') }}</label>
                    <input
                        type="password"
                        class="form-control eForm-control"
                        id="old_password"
                        name="old_password"
                        placeholder="Your current password"
                    />
                    </div>
                    <div class="fpb-7 text-end pt-3">
                        <button type="submit" class="btn btn-primary text-12px p-2">{{ get_phrase('Change Password') }}</button>
                    </div>

                </form>
                </div>
            </div>

            </div>
        </div>
    </div>
    <!-- End User Profile area -->
@endsection
