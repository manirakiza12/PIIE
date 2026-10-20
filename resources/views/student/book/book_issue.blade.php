@extends('student.navigation')
   
@section('content')
<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div
              class="d-flex justify-content-between align-items-center flex-wrap gr-15"
            >
                <div class="d-flex flex-column">
                    <h4>{{ get_phrase('Book Issue') }}</h4>
                    <ul class="d-flex align-items-center eBreadcrumb-2">
                        <li><a href="#">{{ get_phrase('Home') }}</a></li>
                        <li><a href="#">{{ get_phrase('Back Office') }}</a></li>
                        <li><a href="#">{{ get_phrase('Issued Book') }}</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="eSection-wrap pb-2">
            
            <form method="GET" enctype="multipart/form-data" class="d-block ajaxForm" action="{{ route('student.book.issued_list') }}">
                    <div class="row">
                        <div class="col-xl-3 mb-3"></div>
                        @if($date_from != "" && $date_to !="")
                            <div class="col-xl-3 mb-3">
                                <input type="text" class="form-control eForm-control" name="eDateRange"
                                    value="{{ date('m/d/Y', $date_from).' - '.date('m/d/Y', $date_to) }}" />
                            </div>
                        @else
                            <div class="col-xl-3 mb-3">
                                <input type="text" class="form-control eForm-control" name="eDateRange"
                                    value="{{ date('m/d/Y', strtotime(' -30 day')).' - '.date('m/d/Y') }}" />
                            </div>
                        @endif

                        <div class="col-xl-2 mb-3">
                            <button type="submit" class="btn btn-icon btn-secondary form-control">{{ get_phrase('Filter') }}</button>
                        </div>

                        @if(count($book_issues) > 0)
                        <div class="col-xl-4">
                            <div class="export position-relative">
                              <button class="eBtn-3 dropdown-toggle float-end mb-4" type="button" id="defaultDropdown" data-bs-toggle="dropdown" data-bs-auto-close="true" aria-expanded="false">
                                <span class="pr-10">
                                  <svg xmlns="http://www.w3.org/2000/svg" width="12.31" height="10.77" viewBox="0 0 10.771 12.31">
                                    <path id="arrow-right-from-bracket-solid" d="M3.847,1.539H2.308a.769.769,0,0,0-.769.769V8.463a.769.769,0,0,0,.769.769H3.847a.769.769,0,0,1,0,1.539H2.308A2.308,2.308,0,0,1,0,8.463V2.308A2.308,2.308,0,0,1,2.308,0H3.847a.769.769,0,1,1,0,1.539Zm8.237,4.39L9.007,9.007A.769.769,0,0,1,7.919,7.919L9.685,6.155H4.616a.769.769,0,0,1,0-1.539H9.685L7.92,2.852A.769.769,0,0,1,9.008,1.764l3.078,3.078A.77.77,0,0,1,12.084,5.929Z" transform="translate(0 12.31) rotate(-90)" fill="#00a3ff"></path>
                                  </svg>
                                </span>
                                {{ get_phrase('Export') }}
                              </button>
                              <ul class="dropdown-menu dropdown-menu-end eDropdown-menu-2">
                                <li>
                                    <a class="dropdown-item" id="pdf" href="javascript:;" onclick="generatePDF()">{{ get_phrase('PDF') }}</a>
                                </li>
                                <li>
                                    <a class="dropdown-item" id="print" href="javascript:;" onclick="ePrintDiv('issued_book_report')">{{ get_phrase('Print') }}</a>
                                </li>
                              </ul>
                            </div>
                        </div>
                        @endif
                    </div>
                
            </form>

            <div class="book_issue_content">
                @include('student.book.issued_list')
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">

  "use strict";

    function ePrintDiv(ePrintDivId) {
        window.printReport(ePrintDivId);
    }

    function generatePDF() {

        // Choose the element that our invoice is rendered in.
        const element = document.getElementById("issued_book_report");

        // clone the element
        var clonedElement = element.cloneNode(true);

        // change display of cloned element 
        $(clonedElement).css("display", "block");

        // Choose the clonedElement and save the PDF for our user.
        var opt = {
          margin:       1,
          filename:     'issued_book_report.pdf',
          image:        { type: 'jpeg', quality: 0.98 },
          html2canvas:  { scale: 2 }
        };

        // New Promise-based usage:
        window.exportReportPdf(clonedElement, opt.filename);

        // remove cloned element
        clonedElement.remove();
    }
</script>

@endsection