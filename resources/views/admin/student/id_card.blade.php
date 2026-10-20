<!--title-->
<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-body" id="printableArea">
        @include('partials.id_card', ['studentDetails' => $student_details])

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
