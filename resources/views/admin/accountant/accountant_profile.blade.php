<style>
    .profile_img{
        display: flex;
        justify-content: center;
    }
    .student_simg{
        display: flex;
        justify-content: center;
    }
    .name_title h4{
        font-size: 14px;
        font-weight: 500;
    }
    .text{
        border-top: 1px solid #817e7e21;

    } 
    .text h4{
        border-bottom: 1px solid #817e7e21;
        padding-bottom: 7px;
        padding-top: 5px;
        font-size: 14px;
        font-weight: 400;
    }
    .text h4:last-child{
        border-bottom: none;
    }
</style>

<div class="row">
    <div class="col-12">
        <div class="school_name">
            <h2 class="text-center">{{ DB::table('schools')->where('id', auth()->user()->school_id)->value('title') }}</h2>
        </div>
    </div>
</div>

<section class="profile">
    <div class="container"> 
      <div class="row">
        <div class="col-lg-4">
          <div class="profile_img">
              <div class="test_div">
                  <div class="student_simg">
                      <img src="{{ $user_details['photo'] }}" class="rounded-circle div-sc-five">
                  </div>
                  <div class="name_title mt-3 text-center">
                      <h4>{{ get_phrase('Name') }} : {{ $user_details['name'] }}</h4>
                      <h4>{{ get_phrase('Email') }} : {{ null_checker($user_details['email']) }}</h4>

                  </div>
              </div>
          </div>
      </div>
      <div class="col-lg-8">
            <ul
              class="nav nav-pills eNav-Tabs-justify"
              id="pills-tab"
              role="tablist"
            >
              <li class="nav-item" role="presentation">
                <button
                  class="nav-link active"
                  id="pills-jHome-tab"
                  data-bs-toggle="pill"
                  data-bs-target="#pills-jHome"
                  type="button"
                  role="tab"
                  aria-controls="pills-jHome"
                  aria-selected="true"
                >
                {{ get_phrase('Admin Info') }}
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button
                  class="nav-link"
                  id="pills-jProfile-tab"
                  data-bs-toggle="pill"
                  data-bs-target="#pills-jProfile"
                  type="button"
                  role="tab"
                  aria-controls="pills-jProfile"
                  aria-selected="false"
                >
                {{ get_phrase('Change Password') }}
                </button>
              </li>
            </ul>
            <div
              class="tab-content eNav-Tabs-content"
              id="pills-tabContent"
            >
              <div
                class="tab-pane fade show active"
                id="pills-jHome"
                role="tabpanel"
                aria-labelledby="pills-jHome-tab"
              >
                <div class="text name_title">
                    <h4>{{ get_phrase('Staff Number') }} : {{ null_checker($user_details['code'] ?? '') }}</h4>
                    <h4>{{ get_phrase('Name') }} : {{ $user_details['name'] }}</h4>
                    <h4>{{ get_phrase('Gender') }} : {{ null_checker($user_details['gender']) }}</h4>
                    <h4>{{ get_phrase('Address') }} : {{ null_checker($user_details['address']) }}</h4>

                    <h4>{{ get_phrase('Contact') }} : {{ null_checker($user_details['phone']) }}</h4>
                    <h4>{{ get_phrase('Department') }} : {{ null_checker($user_details['department'] ?? '') }}</h4>
                    <h4>{{ get_phrase('Designation') }} : {{ null_checker($user_details['designation'] ?? '') }}</h4>
                    <h4>{{ get_phrase('Employment Type') }} : {{ null_checker($user_details['employment_type'] ?? '') }}</h4>
                    <h4>{{ get_phrase('Staff Status') }} : {{ ucfirst($user_details['staff_status'] ?? 'active') }}</h4>
                </div>
              </div>
              <div
                class="tab-pane fade"
                id="pills-jProfile"
                role="tabpanel"
                aria-labelledby="pills-jProfile-tab"
              >
                @if((int) auth()->user()->role_id === 2)
                <form action="{{route('admin.user_password')}}" method="post">
                          @CSRF
                          <div class="fpb-7">
                              <input type="text" class="form-control eForm-control" name="password" id="password{{ $user_details['id'] }}">
                              <input type="hidden" name="user_id" value="{{ $user_details['id'] }}">
                            </div>

                            <div class="generatePass d-flex">
                              <div class="pt-2">
                                  <button type="button"
                                class="btn-form" style="width: 127px;"
                                aria-expanded="false" onclick="generatePassword('{{ $user_details['id']  }}')">Generate Password</button>
                              </div>
                              <div class="ms-3 pt-2">
                                <button type="submit" class="btn-form float-end">{{ get_phrase('Submit') }}</button>
                              </div>
                            </div> 
                        </form>  
                @endif
              </div>
            </div>
      </div>
    </div>
</section>

<script>
    function generatePassword(id) {
      var length = 12;
      var charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+";
      var password = "";
      for (var i = 0; i < length; ++i) {
        var randomNumber = Math.floor(Math.random() * charset.length);
        password += charset[randomNumber];
      }
      document.getElementById("password"+id).value = password;
    }
  </script>
