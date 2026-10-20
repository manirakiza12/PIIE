@extends('admin.navigation')
@section('content')
    <div class="mainSection-title">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
                    <div class="d-flex flex-column">
                        <h4>{{ get_phrase('Club Members') }}</h4>
                        <ul class="d-flex align-items-center eBreadcrumb-2">
                            <li><a href="#">{{ get_phrase('Home') }}</a></li>
                            <li><a href="#">{{ get_phrase('Back Office') }}</a></li>
                            <li><a href="#">{{ get_phrase('Club') }}</a></li>
                        </ul>
                    </div>
                    <div class="export-btn-area">
                        <a href="javascript:;" class="export_btn"
                            onclick="rightModal('{{ route('admin.club.add_member', $club->id) }}', '{{ get_phrase('Select Student') }}')">
                            <i class="bi bi-plus"></i>{{ get_phrase('Add Student') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="eSection-wrap-2">
        <div class="row">
            <div class="col-12">

                <!-- Search and filter -->
                <div
                    class="search-filter-area d-flex justify-content-md-between justify-content-center align-items-center flex-wrap gr-15">
                    <form action="{{ route('admin.club.members', $club->id) }}" method="GET">
                        <div class="search-input d-flex justify-content-start align-items-center">
                            <span>
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16">
                                    <path id="Search_icon" data-name="Search icon"
                                        d="M2,7A4.951,4.951,0,0,1,7,2a4.951,4.951,0,0,1,5,5,4.951,4.951,0,0,1-5,5A4.951,4.951,0,0,1,2,7Zm12.3,8.7a.99.99,0,0,0,1.4-1.4l-3.1-3.1A6.847,6.847,0,0,0,14,7,6.957,6.957,0,0,0,7,0,6.957,6.957,0,0,0,0,7a6.957,6.957,0,0,0,7,7,6.847,6.847,0,0,0,4.2-1.4Z"
                                        fill="#797c8b" />
                                </svg>
                            </span>
                            <input type="text" id="search" name="search" value="{{ $search }}"
                                placeholder="Search student" class="form-control" />
                        </div>
                    </form>
                    <div class="filter-export-area d-flex align-items-center">
                        
                        <!-- Export Button -->
                        @if (count($members) > 0)
                            <div class="position-relative">
                                <button class="eBtn-3 dropdown-toggle" type="button" id="defaultDropdown"
                                    data-bs-toggle="dropdown" data-bs-auto-close="true" aria-expanded="false">
                                    <span class="pr-10">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="12.31" height="10.77"
                                            viewBox="0 0 10.771 12.31">
                                            <path id="arrow-right-from-bracket-solid"
                                                d="M3.847,1.539H2.308a.769.769,0,0,0-.769.769V8.463a.769.769,0,0,0,.769.769H3.847a.769.769,0,0,1,0,1.539H2.308A2.308,2.308,0,0,1,0,8.463V2.308A2.308,2.308,0,0,1,2.308,0H3.847a.769.769,0,1,1,0,1.539Zm8.237,4.39L9.007,9.007A.769.769,0,0,1,7.919,7.919L9.685,6.155H4.616a.769.769,0,0,1,0-1.539H9.685L7.92,2.852A.769.769,0,0,1,9.008,1.764l3.078,3.078A.77.77,0,0,1,12.084,5.929Z"
                                                transform="translate(0 12.31) rotate(-90)" fill="#F15F23" />
                                        </svg>
                                    </span>
                                    {{ get_phrase('Export') }}
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end eDropdown-menu-2">
                                    <li>
                                        <a class="dropdown-item" id="pdf" href="javascript:;"
                                            onclick="Export()">{{ get_phrase('PDF') }}</a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" id="print" href="javascript:;"
                                            onclick="printableDiv('member_list')">{{ get_phrase('Print') }}</a>
                                    </li>
                                </ul>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        <div id="member_list">
            <table class="table eTable eTable-2">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student</th>
                        <th>Class</th>
                        <th>Section</th>
                        <th>Account Status</th>
                        <th>Options</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($members as $member)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td>{{ $member->student->name }}</td>
                            <td>
                                {{ optional(optional($member->student->enrollment)->class)->name ?? '-' }}
                            </td>
                            <td>
                                {{ optional(optional($member->student->enrollment)->section)->name ?? '-' }}
                            </td>

                            <td>
                                <div class="dAdmin_info_name min-w-100px">
                                    @if (!empty($member->status == 0))
                                        <span class="eBadge ebg-soft-danger">{{ get_phrase('Disabled') }}</span>
                                    @else
                                        <span class="eBadge ebg-soft-success">{{ get_phrase('Enable') }}</span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <div class="adminTable-action">
                                    <button type="button" class="eBtn eBtn-black dropdown-toggle table-action-btn-2"
                                        data-bs-toggle="dropdown" aria-expanded="false">
                                        {{ get_phrase('Actions') }}
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end eDropdown-menu-2 eDropdown-table-action">
                                        <li>
                                            <a class="dropdown-item" href="javascript:;"
                                                onclick="confirmModal('{{ route('admin.club.member.delete', $member->id) }}', 'undefined');">{{ get_phrase('Delete') }}</a>
                                        </li>
                                        @if ($member->status == 0)
                                            <li>
                                                <a class="dropdown-item" href="javascript:;"
                                                    onclick="confirmModal('{{ route('admin.club.member.approve', $member->id) }}', 'undefined');">
                                                    Enable
                                                </a>
                                            </li>
                                        @else
                                            <li>
                                                <a class="dropdown-item" href="javascript:;"
                                                    onclick="confirmModal('{{ route('admin.club.member.disable', $member->id) }}', 'undefined');">
                                                    Disable
                                                </a>
                                            </li>
                                        @endif

                                    </ul>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <style>
        .select2-search--dropdown {
            display: block !important;
        }

        input.select2-search__field:focus-visible {
            outline: none;
        }
    </style>


    <script type="text/javascript">
        "use strict";

        function classWiseSection(classId) {
            let url = "{{ route('class_wise_sections', ['id' => ':classId']) }}";
            url = url.replace(":classId", classId);
            $.ajax({
                url: url,
                success: function(response) {
                    $('#section_id').html(response);
                }
            });
        }

        function Export() {

            // Choose the element that our invoice is rendered in.
            const element = document.getElementById("member_list");

            // clone the element
            var clonedElement = element.cloneNode(true);

            // change display of cloned element
            $(clonedElement).css("display", "block");

            // Choose the clonedElement and save the PDF for our user.
            var opt = {
                margin: 1,
                filename: 'member_list_{{ date('y-m-d') }}.pdf',
                image: {
                    type: 'jpeg',
                    quality: 0.98
                },
                html2canvas: {
                    scale: 2
                }
            };

            // New Promise-based usage:
            window.exportReportPdf(clonedElement, opt.filename);

            // remove cloned element
            clonedElement.remove();
        }

        function printableDiv(printableAreaDivId) {
        window.printReport(printableAreaDivId);
    }
    </script>
@endsection
