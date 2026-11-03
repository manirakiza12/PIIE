@extends('student.navigation')

@section('content')
    @php
        $user_information = array_merge(
            ['phone' => null, 'address' => null, 'birthday' => null, 'gender' => null, 'photo' => null],
            (array) (json_decode(auth()->user()->user_information ?? '', true) ?: [])
        );
    @endphp
    <style>
        .profile-compact .user-info { max-width: 250px; flex: 0 0 250px; }
        .profile-compact .userImg { width: 100px; height: 100px; border-radius: 6px; overflow: hidden; margin: 0 auto; background: #f2f2f2; }
        .profile-compact .userImg img { width: 100%; height: 100%; object-fit: cover; }
        .profile-compact .user-info-edit-items .item { padding: 4px 0; }
        .profile-compact .fpb-7 { margin-bottom: 8px; }
        .profile-compact .eForm-label { font-size: 13px; margin-bottom: 3px; }
        .profile-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 14px; }
        @media (max-width: 767px) { .profile-grid { grid-template-columns: 1fr; } }
        .profile-save-btn { background: #C54916; border-color: #C54916; color: #fff; }
        .profile-save-btn:hover { background: #a83d12; border-color: #a83d12; color: #fff; }
    </style>
    <div class="user-profile-area d-flex flex-wrap profile-compact" style="gap:14px;">
        <!-- Left side -->
        <div class="user-info d-flex flex-column">
            <div class="user-info-basic d-flex flex-column justify-content-center">
                <div class="userImg">
                    <img src="{{ get_user_image(auth()->user()->id) }}" alt="" />
                </div>
                <div class="userContent text-center">
                    <h4 class="title" style="color:#C54916; font-size:16px; margin-bottom:2px;">{{ auth()->user()->name }}</h4>
                    <p class="info" style="margin-bottom:2px;">{{ get_phrase('Student') }}</p>
                    <p class="user-status-verify" style="margin:0 auto;">{{ get_phrase('Verified') }}</p>
                </div>
            </div>
            <div class="user-info-edit">
                <div class="user-edit-title d-flex justify-content-between align-items-center">
                    <h3 class="title" style="font-size:14px; margin:0;">{{ get_phrase('Details info') }}</h3>
                </div>
                <div class="user-info-edit-items">
                    <div class="item">
                        <p class="title">{{ get_phrase('Registration Number') }}</p>
                        <p class="info">{{ auth()->user()->code }}</p>
                    </div>
                    <div class="item">
                        <p class="title">{{ get_phrase('Email') }}</p>
                        <p class="info" style="word-break:break-all;">{{ auth()->user()->email }}</p>
                    </div>
                </div>
            </div>
        </div>
        <!-- Right side -->
        <div class="user-details-info">
            <div class="tab-content eNav-Tabs-content" id="myTabContent">
                <div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2 py-2 mb-2">
                    <div>
                        <strong style="color:#C54916;">{{ get_phrase('Regional Settings') }}</strong>
                        <div class="small">{{ get_phrase('Choose the timezone used to display your classes, deadlines and schedules.') }}</div>
                    </div>
                    <a href="{{ route('profile.regional.edit') }}" class="eBtn eBtn-primary btn-sm">{{ get_phrase('Regional Settings') }}</a>
                </div>
                <div class="tab-pane fade show active" id="basicInfo" role="tabpanel" aria-labelledby="basicInfo-tab">
                    <div class="eForm-layouts">
                    <form action="{{route('student.profile.update')}}" method="post" enctype="multipart/form-data">
                        @CSRF
                        <p class="form-text small mb-2">{{ get_phrase('Your name, email, date of birth and registration number are maintained by the institution. Contact the Registrar for corrections.') }}</p>
                        @if ($errors->any())
                            <div class="alert alert-danger" role="alert">
                                <ul class="mb-0">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <div class="profile-grid">
                            <div class="fpb-7">
                                <label class="eForm-label">{{ get_phrase('Name') }}</label>
                                <input type="text" class="form-control eForm-control" readonly value="{{ auth()->user()->name }}" />
                            </div>
                            <div class="fpb-7">
                                <label class="eForm-label">{{ get_phrase('Email') }}</label>
                                <input type="email" class="form-control eForm-control" readonly value="{{ auth()->user()->email }}" />
                            </div>
                            <div class="fpb-7">
                                <label class="eForm-label">{{ get_phrase('Date of Birth') }}</label>
                                @php $birthday = $user_information['birthday']; @endphp
                                <input type="text" class="form-control eForm-control" readonly value="{{ $birthday ? date('m/d/Y', $birthday) : '' }}" />
                            </div>
                            <div class="fpb-7">
                                <label class="eForm-label">{{ get_phrase('Gender') }}</label>
                                <select name="gender" class="form-select eForm-select">
                                    <option value="Male" @if(strtolower($user_information['gender'] ?? '') == 'male') selected @endif>{{ get_phrase('Male') }}</option>
                                    <option value="Female" @if(strtolower($user_information['gender'] ?? '') == 'female') selected @endif>{{ get_phrase('Female') }}</option>
                                </select>
                            </div>
                            <div class="fpb-7">
                                <label class="eForm-label">{{ get_phrase('Phone Number') }}</label>
                                <input type="tel" class="form-control eForm-control" name="phone" value="{{ old('phone', $user_information['phone']) }}" placeholder="Phone" />
                            </div>
                            <div class="fpb-7">
                                <label class="eForm-label">{{ get_phrase('Address') }}</label>
                                <input type="text" class="form-control eForm-control" name="address" value="{{ old('address', $user_information['address']) }}" placeholder="Address" />
                            </div>
                        </div>
                        <div class="fpb-7 mt-2">
                            <label class="eForm-label">{{ get_phrase('Photo') }}</label>
                            <input type="file" class="form-control eForm-control-file" name="photo" accept=".jpg,.jpeg,.png,image/jpeg,image/png" />
                            <div class="form-text small">{{ get_phrase('Choose a JPG or PNG image, up to 4 MB.') }}</div>
                        </div>
                        <button type="submit" class="userFormEdit-btn btn profile-save-btn">{{ get_phrase('Save Changes') }}</button>
                    </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection