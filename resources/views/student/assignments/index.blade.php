@extends('student.navigation')


@section('content')






{{-- @php echo 'pre'; print_r($teachers); die(); @endphp --}}

<!--title-->
<div class="mainSection-title">
    <div class="row">
      <div class="col-12">
        <div
          class="d-flex justify-content-between align-items-center flex-wrap gr-15">
          <div class="d-flex flex-column">
            <h4>{{ get_phrase('My assignments') }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2">
              <li><a href="{{ route('student.dashboard') }}">{{ get_phrase('Home') }}</a></li>
              <li><a href="{{ route('student.assignment_home', ['type' => 'published']) }}">{{ get_phrase('My assignments') }}</a></li>
            </ul>
          </div>
        </div>
      </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="eSection-wrap-2">
                {{-- navigation tabs --}}
                <ul class="nav nav-tabs eNav-Tabs-custom" id="nav-tab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link @if($type == 'active') active @endif" id="nav-home-active" data-bs-target="active" type="button" role="tab" aria-controls="nav-home" aria-selected="true" onclick="window.location.href = '{{ route('student.assignment_home', ['type' => 'active']) }}';">{{ get_phrase('Active assignments') }}<span></span></button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button  class="nav-link @if($type == 'expired') active @endif" id="nav-home-expired" data-bs-target="expired" role="tab" aria-controls="nav-home" aria-selected="true" onclick="window.location.href = '{{ route('student.assignment_home', ['type' => 'expired']) }}';">{{ get_phrase('Assignments Archives') }}<span></span></button>
                    </li>
                </ul>
                <div class="search-filter-area">


                    {{-- filter export area --}}
                    <div class="filter-export-area d-flex justify-content-end ">
                        
                        {{-- filter button --}}
                        <div class="position-relative">
                            <button
                            class="eBtn-3 dropdown-toggle" type="button" id="defaultDropdown" data-bs-toggle="dropdown" data-bs-auto-close="true" aria-expanded="false">
                                <span class="pr-10">
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        width="14.028"
                                        height="12.276"
                                        viewBox="0 0 14.028 12.276">
                                        <path
                                        id="filter-solid"
                                        d="M.106,32.627A1.1,1.1,0,0,1,1.1,32H12.934a1.092,1.092,0,0,1,.989.627,1.054,1.054,0,0,1-.164,1.164l-4.99,6.126V43.4a.877.877,0,0,1-1.4.7L5.612,42.786a.871.871,0,0,1-.351-.7V39.917L.248,33.79a1.1,1.1,0,0,1-.142-1.164Z"
                                        transform="translate(0 -32)"
                                        fill="#00a3ff"/>
                                    </svg>
                                </span>
                                {{ get_phrase('Filter') }}
                            </button>
                            <div class="dropdown-menu dropdown-menu-end filter-options" aria-labelledby="defaultDropdown">
                                <h4 class="title">{{ get_phrase('Filter Options') }}</h4>
                                <form action="{{ route('student.assignments.filter.show.list', ['type' => $type]) }}" method="get">
                                    <div class="filter-option d-flex flex-column">
                                        
                                        <div>
                                            <label for="subject_id" class="eForm-label">{{ get_phrase('Subject') }}</label>
                                            <select class="form-select" name="subject_id" id="subject_id" data-placeholder="Type to search...">
                                                
                                                <option value="0">{{ get_phrase('Select subject') }}</option>
                                            @foreach ($subjects as $subject)    
                                                <option value="{{ $subject->id }}" @if($subject->id == $filter_subject_id) selected @endif>{{ $subject->name }}</option>
                                            @endforeach
                                            </select>
                                        </div>
                                        
                                        <div>
                                            <label for="teacher_id" class="eForm-label"
                                            >{{ get_phrase('Teacher') }}</label>
                                            <select
                                            class="form-select" name="teacher_id" id="teacher_id" >
                                                <option value="0">{{ get_phrase('Select teacher') }}</option>

                                            @foreach ($teachers as $teacher)    
                                                <option value="{{ $teacher->id }}" @if($teacher->id == $filter_teacher_id) selected @endif >{{ $teacher->name }}</option>
                                            @endforeach 
                                            </select>
                                        </div>
                                        
                                    </div>
                                    
                                    <div class="filter-button d-flex justify-content-end align-items-center">
                                        <a class="form-group">
                                        <button class="eBtn eBtn btn-primary"  type="submit" >{{ get_phrase('Apply') }}</button>
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="my-assignments-list-div">
                @include('student.assignments.list')
                </div>
                
            </div>
        </div>
    </div>
</div>




{{-- modal code start --}}

<!-- Large Scrollable modal -->
<div class="modal fade modal-static workspace-surface" id="large-scrollable-modal" tabindex="-1" role="dialog" aria-labelledby="assignmentModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content" id="tss">
            
            {{-- MODAL HEADER --}}
            <div class="modal-header">
                <h2 class="modal-title" id="assignmentModalTitle"><strong>Modal title</strong></h2>
                <a class="offcanvas-btn" data-bs-dismiss="modal" aria-label="Close">
                    <svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'>
                        <path d='M.293.293a1 1 0 011.414 0L8 6.586 14.293.293a1 1 0 111.414 1.414L9.414 8l6.293 6.293a1 1 0 01-1.414 1.414L8 9.414l-6.293 6.293a1 1 0 01-1.414-1.414L6.586 8 .293 1.707a1 1 0 010-1.414z' />
                    </svg>
                </a>
            </div>

            {{-- MODAL BODY --}}
            <div class="modal-body ml-2 mr-2" id="pdf_div">

            </div>

            {{-- MODAL FOOTER --}}
            <div class="modal-footer d-flex justify-content-between">

                <button class="eBtn eBtn-blue" id="pdf" href="javascript:;" onclick="Export('pdf_div')" >{{ get_phrase('Download') }}</button>
                
                  
                <button class="eBtn eBtn-red" data-bs-dismiss="modal"><?php echo get_phrase("Close"); ?></button>
                

            </div>

        </div><!-- /.modal-content -->
    </div><!-- /.modal-dialog -->
</div>
{{-- modal ended --}}






<script>

   


    function filterMyAssignment(){
        var activeTab = $('#nav-tab .nav-link.active').data('bs-target');


        var teacher_id = parseInt($('#teacher_id').val());
        var subject_id = parseInt($('#subject_id').val());

        if (!Number.isInteger(teacher_id)){
            teacher_id = 0;
        }

        if (!Number.isInteger(subject_id)){
            subject_id = 0;
        }

        // showAssignments(status, class_id, section_id, subject_id);
    }


    function Export(divId) {

        // Choose the element that our invoice is rendered in.
        const element = document.getElementById(divId);

        // clone the element
        var clonedElement = element.cloneNode(true);


        window.scrollTo(0, 0);

        // Wait for a brief moment to ensure content rendering
        setTimeout(function () {
            var clonedElement = element.cloneNode(true);
            clonedElement.classList.add("display-none-view");
            clonedElement.style.width = "100%";
            clonedElement.style.maxWidth = "none";
            $(clonedElement).css("display", "block");


            const buttonsAndAnchors = clonedElement.querySelectorAll(".selector-class-button");

            buttonsAndAnchors.forEach(element => {
                const textNode = document.createTextNode("ANSWER FILE SUBMITTED");
                element.parentNode.replaceChild(textNode, element);
            });


            // Add the page-break-inside: avoid; CSS rule to <tr> elements
            const trElements = clonedElement.querySelectorAll(".row");
                trElements.forEach(tr => {
                tr.style.pageBreakInside = "avoid";
            });

            // Trigger a reflow/layout recalculation by toggling a class
            clonedElement.offsetHeight;

            document.body.appendChild(clonedElement);


            // Choose the clonedElement and save the PDF for our user.
            var opt = {
            margin: 4,
            filename: '{{ auth()->user()->name }}'+'_{{ date("y-m-d") }}.pdf',
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: {
            scale: 2,
            scrollX: 0,
            scrollY: 0
            }
            };

            window.exportReportPdf(clonedElement, opt.filename);



            // remove cloned element
            clonedElement.remove();
        }, 1000); // Adjust the delay as needed
    }


</script>



@endsection
