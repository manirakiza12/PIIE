<?php
use App\Models\School;

$school_data = School::where('id', auth()->user()->school_id)->first();
?>
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
        <div class="paymentHeader d-flex justify-content-between align-items-center">
            <h5 class="title">{{ get_phrase('Make Payment') }}</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" onclick="location.href='{{ route('student.hostel_fee_manager.list') }}'"></button>
        </div>

        <div class="paymentWrap d-flex align-items-start flex-wrap">
            <div class="paymentRight" style="width:100%;">
                <p class="payment_tab_title pb-30">{{ get_phrase('Invoice Summary') }}</p>
                <div class="payment_table">
                    <div class="table-responsive">
                        <table class="table eTable eTable-2">
                            <tbody>
                                <tr>
                                    <td><div class="dAdmin_info_name"><p><span>01</span></p></div></td>
                                    <td><div class="dAdmin_info_name min-w-100px"><p>{{ get_phrase('Hostel Fee') }}</p></div></td>
                                    <td><div class="dAdmin_info_name min-w-150px text-end"><p>{{ $fee_details['total_amount'] . ' ' . get_active_currency() }}</p></div></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                @if (addon_status('payment_gateways') == 1)
                    <div class="off_payment_form mb-4">
                        <form action="{{ route('student.hostel.payment.marzpay.start', ['id' => $fee_details['id']]) }}" method="post" class="marzpay-form form">
                            @csrf
                            <hr class="border mb-4">
                            <div class="fpb-7">
                                <label for="marzpay_phone_number" class="eForm-label">{{ get_phrase('Mobile Money Number') }}</label>
                                <input type="tel" class="form-control eForm-control" id="marzpay_phone_number" name="phone_number" placeholder="e.g. 0712345678" value="{{ old('phone_number') }}" required>
                                <small class="text-muted d-block mt-1">{{ get_phrase("You'll get a USSD prompt on this phone to approve the payment.") }}</small>
                            </div>
                            <button type="submit" class="off_payment_btn">{{ get_phrase('Pay with MarzPay') }}</button>
                        </form>
                    </div>
                @endif

                <div class="off_payment_form">
                    <form action="{{ route('student.offline.payment.hostel') }}" class="offline-form form" method="post" enctype="multipart/form-data">
                        @csrf
                        <hr class="border mb-4">
                        <input type="hidden" id="amount" class="form-control eForm-control" name="amount" value="{{ $fee_details['total_amount'] }}" readonly>

                        <div class="payable_document">
                            <label for="document_image" class="eForm-label">{{ get_phrase('Document of your payment') }} (jpg, pdf, txt, png, docx)</label>
                            <input type="file" class="form-control eForm-control-file" id="document_image" name="document_image" required>
                        </div>

                        <button type="submit" class="off_payment_btn">{{ get_phrase('Submit payment document') }}</button>

                        <div class="offline_payment_instruction alert alert-success mt-3">
                            <p class="payment_tab_title mb-3 text-center">{{ get_phrase('Instruction') }}</p>
                            <p class="mb-3">{{ $school_data->off_pay_ins_text }}</p>
                            <p class="mt-3">{{ get_phrase('Admin will review your payment document and then approve the Payment.') }}</p>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="{{ asset('assets/vendors/jquery/jquery-3.6.0.min.js') }}"></script>
    <script src="{{ asset('assets/vendors/bootstrap-5.1.3/js/bootstrap.bundle.min.js') }}"></script>
</body>

</html>
