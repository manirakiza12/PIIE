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
                <img width="100%" height="150px" src="{{ get_user_image(auth()->user()->id) }}" alt="" />
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
            {{-- Regional Settings (personal timezone) lives on its own screen
                 rather than inside this form, because it is a presentation
                 preference and not part of a person's identity or contact
                 record - and because a student and a member of staff must reach
                 the identical screen under identical rules. --}}
            <div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <strong>{{ get_phrase('Regional Settings') }}</strong>
                    <div class="small">
                        {{ get_phrase('Choose the timezone used to display your classes, deadlines and schedules.') }}
                    </div>
                </div>
                <a href="{{ route('profile.regional.edit') }}" class="eBtn eBtn-primary">
                    {{ get_phrase('Regional Settings') }}
                </a>
            </div>
            <div
                class="tab-pane fade show active"
                id="basicInfo"
                role="tabpanel"
                aria-labelledby="basicInfo-tab"
            >
                <div class="eForm-layouts">
                <form action="{{ route('teacher.profile.update') }}" method="post" enctype="multipart/form-data">
                    @CSRF
                    
                    <div class="fpb-7">
                    <label for="eInputName" class="eForm-label">{{ get_phrase('Name') }}</label>
                    <input
                        type="text"
                        class="form-control eForm-control"
                        id="eInputName"
                        name="name"
                        value="{{ auth()->user()->name }}"
                        placeholder="Your Name"
                        aria-label="Your Name"
                    />
                    </div>
                    <div class="fpb-7">
                    <label for="eInputEmail" class="eForm-label"
                        >{{ get_phrase('Email') }}</label
                    >
                    <input
                        type="email"
                        class="form-control eForm-control"
                        id="eInputEmail"
                        name="email"
                        value="{{ auth()->user()->email }}"
                        placeholder="example@email.com"
                        aria-label="example@email.com"
                    />
                    </div>

                    <div class="fpb-7">
                    <label for="designation" class="eForm-label"
                        >{{ get_phrase('Designation') }}</label
                    >
                    <input
                        type="text"
                        class="form-control eForm-control"
                        id="designation"
                        name="designation"
                        value="{{ auth()->user()->designation }}"
                        placeholder="Enter your designation"
                    />
                    </div>
                    
                    
                    <div class="fpb-7">
                    <label for="eBrithDay" class="eForm-label"
                        >{{ get_phrase('Birthday') }}</label
                    >
                    @php
                        $birthday = json_decode(auth()->user()->user_information, true)['birthday'];
                        if(empty($birthday)){
                            $birthday = time();
                        }
                    @endphp
                    <input
                        type="text"
                        class="form-control eForm-control"
                        id="eInputDate"
                        name="eDefaultDateRange"
                        value="{{ date('m/d/Y', $birthday) }}"
                    />
                    </div>
                    <div class="fpb-7">
                    <label for="eGenderList" class="eForm-label"
                        >{{ get_phrase('Gender') }}</label
                    >
                    <select name="gender"
                        class="form-select eForm-select eChoice-multiple-without-remove"
                        data-placeholder="Type to search..."
                    >
                        <option value="Male" @php strtolower(json_decode(auth()->user()->user_information, true)['gender']) == 'male' ? 'selected':''; @endphp>{{ get_phrase('Male') }}</option>
                        <option value="Female" @php strtolower(json_decode(auth()->user()->user_information, true)['gender']) == 'female' ? 'selected':''; @endphp>{{ get_phrase('Female') }}</option>
                    </select>
                    </div>
                    <div class="fpb-7">
                    <label for="eInputPhone" class="eForm-label"
                        >{{ get_phrase('Phone Number') }}</label
                    >
                    <input
                        type="number"
                        class="form-control eForm-control"
                        id="eInputPhone"
                        name="phone"
                        value="{{ json_decode(auth()->user()->user_information, true)['phone'] }}"
                        placeholder="00 (00) 12345 6789"
                        aria-label="00 (00) 12345 6789"
                    />
                    </div>
                    <div class="fpb-7">
                    <label for="eInputAddress" class="eForm-label"
                        >{{ get_phrase('Address') }}</label
                    >
                    <input
                        type="text"
                        class="form-control eForm-control"
                        id="eInputAddress"
                        name="address"
                        value="{{ json_decode(auth()->user()->user_information, true)['address'] }}"
                        placeholder="Enter Address"
                        aria-label="Enter Address"
                    />
                    </div>

                    <div class="fpb-7">
                    <label for="eInputAddress" class="eForm-label"
                        >{{ get_phrase('Photo') }}</label
                    >
                    <input type="hidden" class="form-control" name="old_photo" value="{{ json_decode(auth()->user()->user_information, true)['photo'] }}"/>
                    <input type="file" class="form-control eForm-control-file" name="photo" accept="image/*" />
                    </div>

                <button type="submit" class="userFormEdit-btn btn">{{ get_phrase('Save Changes') }}</button>

                </form>
                </div>
            </div>

            </div>
        </div>
    </div>
    <!-- End User Profile area -->
@endsection