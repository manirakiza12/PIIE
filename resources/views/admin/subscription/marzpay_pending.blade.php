<!DOCTYPE html>
<html lang="en">

<head>
    <title>{{ get_phrase('Payment | Ekator 8') }}</title>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="shortcut icon" href="{{ asset('assets/images/logo.png') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/vendors/bootstrap-5.1.3/css/bootstrap.min.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/custom.css') }}" />
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/style.css') }}" />
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/vendors/bootstrap-icons-1.8.1/bootstrap-icons.css') }}">
@include('shared.typography')
</head>

<body>

    <div class="main_content paymentContent">
        <div class="text-center py-5">
            <div id="mp-waiting">
                <div class="spinner-border text-primary mb-3" role="status" style="width:3rem;height:3rem;"></div>
                <h5>{{ get_phrase('Check your phone') }}</h5>
                <p class="text-muted">{{ get_phrase('Approve the MarzPay prompt on your phone to complete this payment.') }}</p>
                <p class="text-muted">{{ get_phrase('This page will update automatically once payment is confirmed.') }}</p>
            </div>

            <div id="mp-paid" class="d-none">
                <i class="bi bi-check-circle-fill text-success" style="font-size:3rem;"></i>
                <h5 class="mt-3">{{ get_phrase('Payment received') }}</h5>
                <a href="{{ route('admin.subscription') }}" class="btn btn-primary mt-3">{{ get_phrase('Back to Subscription') }}</a>
            </div>

            <div id="mp-failed" class="d-none">
                <i class="bi bi-x-circle-fill text-danger" style="font-size:3rem;"></i>
                <h5 class="mt-3">{{ get_phrase('Payment not completed') }}</h5>
                <a href="{{ route('admin.subscription') }}" class="btn btn-primary mt-3">{{ get_phrase('Try again') }}</a>
            </div>
        </div>
    </div>

    <script src="{{ asset('assets/vendors/jquery/jquery-3.6.0.min.js') }}"></script>
    <script>
        (function () {
            var statusUrl = @json(route('admin.subscription.marzpay.status', $payment->id));
            var attempts = 0;
            var maxAttempts = 60;

            function poll() {
                attempts++;
                $.get(statusUrl).done(function (data) {
                    if (data.status === 'paid') {
                        $('#mp-waiting').addClass('d-none');
                        $('#mp-paid').removeClass('d-none');
                    } else if (data.status === 'failed') {
                        $('#mp-waiting').addClass('d-none');
                        $('#mp-failed').removeClass('d-none');
                    } else if (attempts < maxAttempts) {
                        setTimeout(poll, 4000);
                    }
                }).fail(function () {
                    if (attempts < maxAttempts) {
                        setTimeout(poll, 4000);
                    }
                });
            }

            setTimeout(poll, 4000);
        })();
    </script>
</body>

</html>
