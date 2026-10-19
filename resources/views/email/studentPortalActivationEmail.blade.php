@php
use App\Models\School;
$school_details = !empty($data['school_id']) ? School::find($data['school_id']) : null;
$institution_name = $school_details->school_name ?? 'Prime International Institute of Excellence';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Portal Account</title>
</head>
<body style="margin:0; padding:0; font-family: 'Cabin', sans-serif;">
    <div class="email-container" style="background-color: #fff;">
        <table class="table-content" style="box-shadow: rgba(100, 100, 111, 0.2) 0px 7px 29px 0px; padding: 45px 30px 34px 30px; margin: auto; width: 600px;">
            <tbody>
                <tr>
                    <td>
                        <p style="color:#0C141D; font-size: 22px; font-weight:600;">{{ $institution_name }}</p>
                        <h3 style="font-size: 17px; color: #0C141D; margin: 0; margin-top: 20px;">Dear {{ $data['name'] }},</h3>
                        <p style="font-size: 15px; color: #7B7F84; margin: 0; margin-top: 10px;">
                            @if($data['student_password_setup'] ?? false)
                                Your student portal account at {{ $institution_name }} is ready. Set your password to access your assigned courses. The administration handles your student record and documents; you do not need to complete an application.
                            @else
                                Congratulations. Your admission/enrollment at {{ $institution_name }} has been processed successfully.
                            @endif
                        </p>
                    </td>
                </tr>
                <tr>
                    <td>
                        <table style="border:1px solid #E4E7EC; margin-top: 20px; width: 100%;" cellpadding="10" cellspacing="0">
                            <tr style="background-color: #E4E7EC;">
                                <td><strong>Student Details</strong></td>
                            </tr>
                            @if(!empty($data['code']))
                            <tr><td>Registration Number: <strong>{{ $data['code'] }}</strong></td></tr>
                            @endif
                            @if(!empty($data['programme']))
                            <tr><td>Programme: <strong>{{ $data['programme'] }}</strong></td></tr>
                            @endif
                            @if(!empty($data['intake']))
                            <tr><td>Intake: <strong>{{ $data['intake'] }}</strong></td></tr>
                            @endif
                        </table>
                    </td>
                </tr>
                <tr>
                    <td>
                        <table style="border:1px solid #E4E7EC; margin-top: 20px; width: 100%;" cellpadding="10" cellspacing="0">
                            <tr style="background-color: #E4E7EC;">
                                <td><strong>Student Portal Login</strong></td>
                            </tr>
                            <tr><td>Login Email: <strong>{{ $data['email'] }}</strong></td></tr>
                            @unless($data['student_password_setup'] ?? false)
                            <tr><td>Temporary Password: <strong>{{ $data['password'] }}</strong></td></tr>
                            @endunless
                            <tr><td>Portal URL: <a href="{{ url('/login') }}">{{ url('/login') }}</a></td></tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding-top: 20px;">
                        <p style="font-size: 15px; color: #c0392b; font-weight: 600;">
                            @if($data['student_password_setup'] ?? false)
                                Use the password setup button below to choose your password. This link expires in {{ config('auth.passwords.users.expire', 60) }} minutes. If it expires, request a new link from the student portal login page or contact the administration.
                            @else
                                Please change your password immediately after your first login. You will be required to set a new password before you can access the Student Portal.
                            @endif
                        </p>
                    </td>
                </tr>
                <tr style="text-align: center;">
                    <td style="padding-top: 10px;">
                        <span class="es-button-border msohide"><a href="{{ $data['setup_url'] ?? url('/login') }}" class="es-button" target="_blank">{{ ($data['student_password_setup'] ?? false) ? 'Set Password & Open Student Portal' : 'Log in to the Student Portal' }}</a></span>
                    </td>
                </tr>
                <tr>
                    <td style="padding-top: 30px;">
                        <p style="font-size: 15px; color: #7B7F84;">Regards,<br>{{ $institution_name }}</p>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</body>
</html>
