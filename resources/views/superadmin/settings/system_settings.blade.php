@extends('superadmin.navigation')
   
@section('content')
@if ($errors->any())
<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div
              class="d-flex justify-content-between align-items-center flex-wrap gr-15"
            >
                <div class="d-flex flex-column">
                    <h4>{{ get_phrase('System Settings') }}</h4>
                    <ul class="d-flex align-items-center eBreadcrumb-2">
                        <li><a href="#">{{ get_phrase('Home') }}</a></li>
                        <li><a href="#">{{ get_phrase('Settings') }}</a></li>
                        <li><a href="#">{{ get_phrase('System Settings') }}</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-7">
        <div class="eSection-wrap">
            <div class="eMain">
                <div class="row">
                    <div class="col-md-11 pb-3">
                        <div class="eForm-layouts"><p class="text-muted">PNG, JPG, JPEG, GIF or WebP; up to 2 MB per image.</p>
                            <p class="column-title">{{ get_phrase('SYSTEM SETTINGS') }}</p>
                            <form method="POST" enctype="multipart/form-data" class="d-block" action="{{ route('superadmin.system.update') }}">
                                @csrf 
                                <div class="fpb-7">
                                    <label for="system_name" class="eForm-label">{{ get_phrase('System Name') }}</label>
                                    
                                    <input type="text" class="form-control eForm-control" value="{{ get_settings('system_name') }}" id="system_name" name = "system_name" required>
                                    
                                </div>
                                <div class="fpb-7">
                                    <label for="system_title" class="eForm-label">{{ get_phrase('System Title') }}</label>
                                    <input type="text" class="form-control eForm-control" value="{{ get_settings('system_title') }}" id="system_title" name = "system_title" required>
                                </div>
                                <div class="fpb-7 border rounded p-3">
                                    <h5>AI Teaching Assistant Pro</h5>
                                    <p class="text-muted">Provide Admin and Lecturer portals with a secure shortcut to the external service. No portal or student data is sent when opening it.</p>
                                    <input type="hidden" name="ai_teaching_assistant_enabled" value="0">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="ai_teaching_assistant_enabled" name="ai_teaching_assistant_enabled" value="1" {{ get_settings('ai_teaching_assistant_enabled') === '1' ? 'checked' : '' }}>
                                        <label class="form-check-label" for="ai_teaching_assistant_enabled">Enable shortcuts for Admins and Lecturers</label>
                                    </div>
                                    <div class="small text-muted mt-2">Launch URL: <a href="https://www.aiteachingassistantpro.ca/" target="_blank" rel="noopener noreferrer">https://www.aiteachingassistantpro.ca/</a></div>
                                </div>
                                <div class="fpb-7">
                                    <label for="navbar_title" class="eForm-label">{{ get_phrase('Navbar Title') }}</label>
                                    <input type="text" class="form-control eForm-control" value="{{ get_settings('navbar_title') }}" id="navbar_title" name = "navbar_title" required>
                                </div>
                                <div class="fpb-7">
                                    <label for="system_email" class="eForm-label">{{ get_phrase('System Email') }}</label>
                                    <input type="text" class="form-control eForm-control" value="{{ get_settings('system_email') }}" id="system_email" name = "system_email" required>
                                </div>
                                <div class="fpb-7">
                                    <label for="phone" class="eForm-label">{{ get_phrase('Phone') }}</label>
                                    <input type="text" class="form-control eForm-control" value="{{ get_settings('phone') }}" id="phone" name = "phone" required>
                                </div>
                                <div class="fpb-7">
                                    <label for="fax" class="eForm-label">{{ get_phrase('Fax') }}</label>
                                    <input type="text" class="form-control eForm-control" value="{{ get_settings('fax') }}" id="fax" name = "fax" required>
                                </div>
                                <div class="fpb-7">
                                    <label for="language" class="eForm-label">{{ get_phrase('System Language') }}</label>
                                    <select class="form-select eForm-select eChoice-multiple-with-remove" id="language" name="language" required>
                                        <?php $languages = get_all_language(); ?>
                                        <?php foreach ($languages as $language): ?>
                                        <option value="{{ $language->name  }}" {{ get_settings('language') == $language->name ?  'selected':'' }}>{{ ucfirst($language->name) }}</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="fpb-7">
                                    <label for="address" class="eForm-label">{{ get_phrase('Address') }}</label>
                                    <textarea class="form-control eForm-control" id="address" name = "address" rows="5" required>{{ get_settings('address') }}</textarea>
                                </div>
                                <div class="fpb-7">
                                    <label for="frontend_view" class="eForm-label">{{ get_phrase('Frontend View') }}</label>
                                    <select name="frontend_view" id="frontend_view" class="form-select eForm-select eChoice-multiple-with-remove"  required>
                                        <option value="0" {{ get_settings('frontend_view') == '0' ? 'selected':'' }} >{{ get_phrase('No') }}</option>
                                        <option value="1" {{ get_settings('frontend_view') == '1' ? 'selected':'' }} >{{ get_phrase('Yes') }}</option>
                                    </select>
                                </div>
                                <div class="fpb-7">
                                    <label for="name" class="eForm-label">{{ get_phrase('Timezone') }}</label>
                                    <select class="form-select eForm-select eChoice-multiple-with-remove" id="timezone" name="timezone" required>
                                        <?php $tzlist = DateTimeZone::listIdentifiers(DateTimeZone::ALL); ?>
                                        <?php foreach ($tzlist as $tz): ?>
                                        <option value="{{ $tz  }}" {{ get_settings('timezone') == $tz ?  'selected':'' }}>{{ $tz  }}</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="fpb-7">
                                    <label for="footer_text" class="eForm-label">{{ get_phrase('Footer Text') }}</label>
                                    <input type="text" class="form-control eForm-control" value="{{ get_settings('footer_text') }}" id="footer_text" name = "footer_text" required>
                                </div>
                                <div class="fpb-7">
                                    <label for="footer_link" class="eForm-label">{{ get_phrase('Footer Link') }}</label>
                                    <input type="text" class="form-control eForm-control" value="{{ get_settings('footer_link') }}" id="footer_link" name = "footer_link" required>
                                </div>
                                <div class="fpb-7">
                                    <label for="footer_link" class="eForm-label">{{ get_phrase('Help Link') }}</label>
                                    <input type="text" class="form-control eForm-control" value="{{ get_settings('help_link') }}" id="help_link" name = "help_link" required>
                                </div>
                                <div class="fpb-7">
                                    <label for="youtube_api_key" class="eForm-label">{{ get_phrase('Youtube Api Key') }}</label>
                                    <input type="text" class="form-control eForm-control" value="{{ get_settings('youtube_api_key') }}" id="youtube_api_key" name = "youtube_api_key" required>
                                </div>
                                <div class="fpb-7">
                                    <label for="vimeo_api_key" class="eForm-label">{{ get_phrase('Vimeo Api Key') }}</label>
                                    <input type="text" class="form-control eForm-control" value="{{ get_settings('vimeo_api_key') }}" id="vimeo_api_key" name = "vimeo_api_key" required>
                                </div>
                                 <div class="fpb-7 pt-2">
                                    <button type="submit" class="btn-form">{{ get_phrase('Submit') }}</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-5">
        <div class="eSection-wrap">
            <div class="eMain">
                <div class="row">
                    <div class="col-md-12 pb-3">
                        <p class="column-title">{{ get_phrase('PRODUCT UPDATE') }}</p>
                        <div class="eForm-file">
                            <form action="{{route('superadmin.product.update')}}" method="post" enctype="multipart/form-data">
                                @CSRF
                                <div class="mb-3">
                                    <label for="formFileSm" class="eForm-label">{{ get_phrase('File') }}</label>
                                    <input class="form-control eForm-control-file" id="file" type="file" accept=".png,.jpg,.jpeg,.gif,.webp" name="file">
                                </div>
                                <button type="submit" class="btn-form float-end">{{ get_phrase('Update') }}</button>
                            </form>
                        </div>
                    </div>
                    <span class="eBadge ebg-info mb-1 p-2"><?php echo 'Maximum upload size'; ?>: <?php echo ini_get('upload_max_filesize'); ?></span>
                    <span class="eBadge ebg-info mb-1 p-2"><?php echo 'Post max size'; ?>: <?php echo ini_get('post_max_size'); ?></span>
                    <span class="eBadge ebg-danger mb-1 p-2"><?php echo '"post_max_size" '.get_phrase("Has to be bigger than").' "upload_max_filesize"'; ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row mt-3">
    <div class="col-7">
        <div class="eSection-wrap">
            <div class="eMain">
                <p class="column-title">AI Teaching Assistant API Settings</p>
                <p class="text-muted">Optional connection details. The provider has not published an API specification, so saving these values does not activate an API connection. Do not enter a key until the provider issues one for your institution.</p>
                <form method="POST" action="{{ route('superadmin.ai_teaching_assistant.api.update') }}">
                    @csrf
                    <div class="fpb-7">
                        <label for="ai_teaching_assistant_api_endpoint" class="eForm-label">API endpoint (HTTPS)</label>
                        <input type="url" class="form-control eForm-control" id="ai_teaching_assistant_api_endpoint" name="ai_teaching_assistant_api_endpoint" value="{{ get_settings('ai_teaching_assistant_api_endpoint') }}" placeholder="https://provider.example/api">
                    </div>
                    <div class="fpb-7">
                        <label for="ai_teaching_assistant_api_key" class="eForm-label">API key</label>
                        <input type="password" class="form-control eForm-control" id="ai_teaching_assistant_api_key" name="ai_teaching_assistant_api_key" autocomplete="new-password" value="" placeholder="{{ $aiTeachingAssistantApiKeyConfigured ? 'Key saved; enter a new key only to replace it' : 'No key saved' }}">
                        <small class="text-muted">{{ $aiTeachingAssistantApiKeyConfigured ? 'A key is saved encrypted. It is never displayed.' : 'No API key is currently saved.' }}</small>
                    </div>
                    @if($aiTeachingAssistantApiKeyConfigured)
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="clear_ai_teaching_assistant_api_key" name="clear_ai_teaching_assistant_api_key" value="1">
                        <label class="form-check-label" for="clear_ai_teaching_assistant_api_key">Clear saved API key</label>
                    </div>
                    @endif
                    <button type="submit" class="btn-form">Save API Settings</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="col-12">
    <div class="eSection-wrap">
        <div class="eMain">
            <div class="row">
                <div class="col-md-12 pb-3">
                    <p class="column-title">{{ get_phrase('SYSTEM LOGO') }}</p>
                    <div class="eForm-file">
                        <form method="POST" enctype="multipart/form-data" class="d-block" action="{{ route('superadmin.logo.update') }}">
                            @csrf 
                            <div class="row">
                                <div class="col-md-3 text-center">
                                    <label class="col-form-label" for="example-fileinput">{{ get_phrase('Dark logo') }}</label>
                                    <div class="eCard d-block text-center bg-light">
                                        @include('shared.branding_preview', ['imageUrl' => branding_image_url('dark_logo'), 'imageLabel' => get_phrase('dark_logo')])
                                        <div class="eCard-body">
                                            <input class="form-control eForm-control-file" id="dark_logo" type="file" accept=".png,.jpg,.jpeg,.gif,.webp" name="dark_logo">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3 text-center">
                                    <label class="col-form-label" for="example-fileinput">{{ get_phrase('Light logo') }}</label>
                                    <div class="eCard d-block text-center bg-secondary">
                                        @include('shared.branding_preview', ['imageUrl' => branding_image_url('light_logo'), 'imageLabel' => get_phrase('light_logo')])
                                        <div class="eCard-body">
                                        <input class="form-control eForm-control-file" id="light_logo" type="file" accept=".png,.jpg,.jpeg,.gif,.webp" name="light_logo">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3 text-center">
                                    <label class="col-form-label" for="example-fileinput">{{ get_phrase('Favicon') }}</label>
                                    <div class="eCard d-block text-center bg-light">
                                        @include('shared.branding_preview', ['imageUrl' => branding_image_url('favicon'), 'imageLabel' => get_phrase('favicon')])
                                        <div class="eCard-body">
                                        <input class="form-control eForm-control-file" id="favicon" type="file" accept=".png,.jpg,.jpeg,.gif,.webp,.ico" name="favicon">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-3 text-center">
                                    <label class="col-form-label" for="example-fileinput">{{ get_phrase('Nav Bar Logo') }}</label>
                                    <div class="eCard d-block text-center bg-secondary">
                                        @include('shared.branding_preview', ['imageUrl' => branding_image_url('white_logo'), 'imageLabel' => get_phrase('white_logo')])
                                        <div class="eCard-body">
                                        <input class="form-control eForm-control-file" id="white_logo" type="file" accept=".png,.jpg,.jpeg,.gif,.webp" name="white_logo">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <button type="submit" class="btn-form">{{ get_phrase('Update Logo') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="row">
    <div class="col-9">
        <div class="eSection-wrap">
            <div class="eMain">
                <div class="row">
                    <div class="col-md-11 pb-3">
                        <div class="eForm-layouts"><p class="text-muted">PNG, JPG, JPEG, GIF or WebP; up to 2 MB per image.</p>
                            <p class="column-title">{{ get_phrase('Email Template Settings') }}</p>
                            <form method="POST" enctype="multipart/form-data" class="d-block" action="{{ route('superadmin.system.update') }}">
                                @csrf
                                <div class="fpb-7">
                                    <label for="system_title" class="eForm-label">{{ get_phrase('Email Title') }}</label>
                                    <input type="text" class="form-control eForm-control" value="{{ get_settings('email_title') }}" id="email_title" name = "email_title" required>
                                </div> 
    
                                <div class="fpb-7">
                                    <label for="email_details" class="eForm-label">{{ get_phrase('Email Details') }}</label>
                                    <textarea class="form-control eForm-control" id="email_details" name="email_details" rows="4"  maxlength="200"  required>{{ get_settings('email_details') }}</textarea>
                                    
                                    <small><?php echo get_phrase('Remaining characters is'); ?> <strong id="remaining_character">200</strong> </small>
                                </div>
    
                                <div class="fpb-7">
                                    <label for="warning_text" class="eForm-label">{{ get_phrase('Warning Text') }}</label>
                                    <textarea class="form-control eForm-control" id="warning_text" name="warning_text" rows="3"  maxlength="150"  required>{{ get_settings('warning_text') }}</textarea>
    
                                    <small><?php echo get_phrase('Remaining characters is'); ?> <strong id="remaining_character_warning">150</strong> </small>
                                </div>
                            <div class="row mt-5">
                                <div class="col-md-4 text-center">
                                    <label class="eForm-label" for="example-fileinput">{{ get_phrase('Email logo') }}</label>
                                    <div class="eCard d-block text-center bg-light">
                                        @include('shared.branding_preview', ['imageUrl' => branding_image_url('email_logo'), 'imageLabel' => get_phrase('email_logo')])
                                        <div class="eCard-body">
                                            <input class="form-control eForm-control-file" id="email_logo" type="file" accept=".png,.jpg,.jpeg,.gif,.webp" name="email_logo">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 text-center">
                                    <label class="eForm-label" for="example-fileinput">{{ get_phrase('Social logo-1') }}</label>
                                    <div class="eCard d-block text-center bg-light">
                                        @include('shared.branding_preview', ['imageUrl' => branding_image_url('socialLogo1'), 'imageLabel' => get_phrase('socialLogo1')])
                                        <div class="eCard-body">
                                            <input class="form-control eForm-control-file" id="socialLogo1" type="file" accept=".png,.jpg,.jpeg,.gif,.webp" name="socialLogo1">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 text-center">
                                    <label class="eForm-label" for="example-fileinput">{{ get_phrase('Social logo-2') }}</label>
                                    <div class="eCard d-block text-center bg-light">
                                        @include('shared.branding_preview', ['imageUrl' => branding_image_url('socialLogo2'), 'imageLabel' => get_phrase('socialLogo2')])
                                        <div class="eCard-body">
                                            <input class="form-control eForm-control-file" id="socialLogo2" type="file" accept=".png,.jpg,.jpeg,.gif,.webp" name="socialLogo2">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 text-center">
                                    <label class="eForm-label" for="example-fileinput">{{ get_phrase('Social logo-3') }}</label>
                                    <div class="eCard d-block text-center bg-light">
                                        @include('shared.branding_preview', ['imageUrl' => branding_image_url('socialLogo3'), 'imageLabel' => get_phrase('socialLogo3')])
                                        <div class="eCard-body">
                                            <input class="form-control eForm-control-file" id="socialLogo3" type="file" accept=".png,.jpg,.jpeg,.gif,.webp" name="socialLogo3">
                                        </div>
                                    </div>
                                </div>
                            </div>
                                 <div class="fpb-7 pt-2">
                                    <button type="submit" class="btn-form">{{ get_phrase('Submit') }}</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-3">
        <div class="card">
            <div class="card-body">
              <h4 class="header-title mb-3"><?php echo get_phrase('instruction'); ?></h4>
              <div class="row mt-2">
                <div class="col-md-12">
                  <div class="alert alert-success" role="alert">
                    <small>{{ get_phrase("Images for email templates will only support if the application is hosted on a live server. Localhost will not support this.") }}</small>
                  </div>
                </div>
              </div>
            </div>
        </div>   
    </div>
</div>


<script>
    $('#email_details').bind('input propertychange', function() {
              var currentLength = $('#email_details').val().length;
              var remaining_character = 200 - currentLength;
              $('#remaining_character').text(remaining_character);
              copyTheMessageToForm();
            });  
    $('#warning_text').bind('input propertychange', function() {
              var currentLength = $('#warning_text').val().length;
              var remaining_character = 150 - currentLength;
              $('#remaining_character_warning').text(remaining_character);
              copyTheMessageToForm();
            });  

</script>

@endsection
