@extends('student.navigation')
   
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
                <p class="info">{{ get_phrase('Student') }}</p>
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
                @php $user_information = array_merge(['phone' => null, 'address' => null], (array) (json_decode(auth()->user()->user_information ?? '', true) ?: [])); @endphp
                <div class="item">
                <p class="title">{{ get_phrase('Phone Number') }}</p>
                <p class="info">{{ $user_information['phone'] }}</p>
                </div>
                <div class="item">
                <p class="title">{{ get_phrase('Address') }}</p>
                <p class="info">
                {{ $user_information['address'] }}
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
                @php $isFirstTime = (bool) auth()->user()->force_password_change; @endphp
                @if($isFirstTime)
                <div class="alert alert-info" role="status">
                    {{ get_phrase('Welcome. Choose your portal password below to finish setting up your account. In the temporary password field, enter the temporary password from your activation email.') }}
                </div>
                @endif
                @if(session('error'))
                    <div class="alert alert-danger" role="alert">{{ session('error') }}</div>
                @endif
                @if(session('message'))
                    <div class="alert alert-success" role="status">{{ session('message') }}</div>
                @endif
                <form action="{{route('student.password', 'update')}}" method="post" autocomplete="off">
                    @CSRF

                    <div class="fpb-7">
                    <label for="old_password" class="eForm-label">
                        {{ $isFirstTime ? get_phrase('Temporary Password (from your activation email)') : get_phrase('Current Password') }} *
                    </label>
                    <input
                        type="password"
                        class="form-control eForm-control @error('old_password') is-invalid @enderror"
                        id="old_password"
                        name="old_password"
                        placeholder="{{ $isFirstTime ? get_phrase('The temporary password in your activation email') : get_phrase('Your current password') }}"
                        autocomplete="current-password"
                        required
                    />
                    @error('old_password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    <div class="fpb-7">
                    <label for="new_password" class="eForm-label">{{ get_phrase('New Password') }} *</label>
                    <input
                        type="password"
                        class="form-control eForm-control @error('new_password') is-invalid @enderror"
                        id="new_password"
                        name="new_password"
                        placeholder="{{ get_phrase('Choose a new password') }}"
                        autocomplete="new-password"
                        required
                    />
                    @error('new_password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>

                    <div class="fpb-7">
                    <label for="confirm_password" class="eForm-label">{{ get_phrase('Confirm New Password') }} *</label>
                    <input
                        type="password"
                        class="form-control eForm-control @error('confirm_password') is-invalid @enderror"
                        id="confirm_password"
                        name="confirm_password"
                        placeholder="{{ get_phrase('Type the new password again') }}"
                        autocomplete="new-password"
                        required
                    />
                    @error('confirm_password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
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