<!--title-->
<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-body" id="printableArea">
        @include('partials.id_card', [
            'school' => $school,
            'studentDetails' => $student_details,
            'programme' => $programme,
            'cardNumber' => $cardNumber,
            'validFor' => $validFor,
            'qrDataUri' => $qrDataUri,
            'staffCard' => false,
            'staffPosition' => null,
            'staffDepartment' => null,
            'cardLogoSrc' => null,
            'isPdf' => false,
            'staffSignatureSrc' => asset('assets/signatures/Twinamatsiko Naboth.jpg'),
            'studentSignatureSrc' => asset('assets/signatures/academic registrar.jpg'),
        ])

        <div class="d-print-none mt-4">
          <div class="text-center">
            <input type="button" class="eBtn eBtn btn-primary" onclick="printableDiv('printableArea')" value="{{ get_phrase('Print') }}" />
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script type="text/javascript">

  "use strict";

  function printableDiv(printableAreaDivId) {
        window.printReport(printableAreaDivId);
    }
</script>