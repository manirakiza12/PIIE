@php
    use App\Models\School;

    $school           = !empty($data['school_id']) ? School::find($data['school_id']) : null;
    $institution_name = $school->title ?? get_settings('system_title') ?? 'Prime International Institute of Excellence';
    $paragraphs       = $data['paragraphs'] ?? [];
    $details          = $data['details'] ?? [];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $data['subject'] ?? 'Your application' }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f5f6f8; font-family: 'Cabin', Arial, sans-serif;">
    <div style="background-color:#f5f6f8; padding: 30px 12px;">
        <table style="box-shadow: rgba(100, 100, 111, 0.15) 0px 7px 29px 0px; background:#ffffff; padding: 40px 34px; margin: auto; width: 600px; max-width:100%; border-radius:10px;" cellpadding="0" cellspacing="0">
            <tbody>
                <tr>
                    <td>
                        <p style="color:#8a1538; font-size: 20px; font-weight:700; margin:0;">{{ $institution_name }}</p>
                        <p style="color:#98a2b3; font-size: 13px; margin:4px 0 0;">{{ get_phrase('Admissions Office') }}</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding-top:26px;">
                        <h3 style="font-size: 19px; color: #0C141D; margin: 0;">{{ $data['heading'] ?? '' }}</h3>
                        @if(!empty($data['greeting']))
                            <p style="font-size: 15px; color: #0C141D; margin: 14px 0 0;">{{ $data['greeting'] }}</p>
                        @endif
                    </td>
                </tr>
                @foreach($paragraphs as $paragraph)
                <tr>
                    <td>
                        <p style="font-size: 15px; line-height:1.6; color: #545a60; margin: 12px 0 0;">{!! $paragraph !!}</p>
                    </td>
                </tr>
                @endforeach

                @if(!empty($details))
                <tr>
                    <td>
                        <table style="border:1px solid #E4E7EC; border-radius:6px; margin-top: 22px; width: 100%;" cellpadding="10" cellspacing="0">
                            @foreach($details as $label => $value)
                                <tr>
                                    <td style="font-size:14px; color:#7B7F84; width:45%;">{{ $label }}</td>
                                    <td style="font-size:14px; color:#0C141D;"><strong>{{ $value }}</strong></td>
                                </tr>
                            @endforeach
                        </table>
                    </td>
                </tr>
                @endif

                @if(!empty($data['cta_url']))
                <tr>
                    <td style="padding-top: 28px;">
                        <a href="{{ $data['cta_url'] }}" target="_blank"
                           style="display:inline-block; background:#8a1538; color:#ffffff; text-decoration:none; padding:12px 26px; border-radius:6px; font-size:15px; font-weight:600;">
                            {{ $data['cta_label'] ?? get_phrase('Open the Applicant Portal') }}
                        </a>
                    </td>
                </tr>
                @endif

                @if(!empty($data['footer_note']))
                @if(!empty($data['access_url']))
                <tr><td style="padding-top:20px"><a href="{{ $data['access_url'] }}">Set up or reset applicant portal access</a></td></tr>
                @endif
                <tr>
                    <td style="padding-top: 24px;">
                        <p style="font-size: 13px; color: #98a2b3; line-height:1.6; margin:0;">{{ $data['footer_note'] }}</p>
                    </td>
                </tr>
                @endif

                <tr>
                    <td style="padding-top: 30px; border-top:1px solid #E4E7EC;">
                        <p style="font-size: 14px; color: #7B7F84; margin-top:18px;">
                            {{ get_phrase('Regards') }},<br>{{ $institution_name }}
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</body>
</html>
