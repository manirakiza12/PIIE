@extends('student.navigation')
@section('content')
    <div class="mainSection-title">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
                    <div class="d-flex flex-column">
                        <h4>{{ get_phrase('Club') }}</h4>
                        <ul class="d-flex align-items-center eBreadcrumb-2">
                            <li><a href="#">{{ get_phrase('Home') }}</a></li>
                            <li><a href="#">{{ get_phrase('Back Office') }}</a></li>
                            <li><a href="#">{{ get_phrase('Club') }}</a></li>
                        </ul>
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
                    <form action="{{ route('student.club.list') }}">
                        <div class="search-input d-flex justify-content-start align-items-center">
                            <span>
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16">
                                    <path id="Search_icon" data-name="Search icon"
                                        d="M2,7A4.951,4.951,0,0,1,7,2a4.951,4.951,0,0,1,5,5,4.951,4.951,0,0,1-5,5A4.951,4.951,0,0,1,2,7Zm12.3,8.7a.99.99,0,0,0,1.4-1.4l-3.1-3.1A6.847,6.847,0,0,0,14,7,6.957,6.957,0,0,0,7,0,6.957,6.957,0,0,0,0,7a6.957,6.957,0,0,0,7,7,6.847,6.847,0,0,0,4.2-1.4Z"
                                        fill="#797c8b" />
                                </svg>
                            </span>
                            <input type="text" id="search" name="search" value="{{ $search }}"
                                placeholder="Search club" class="form-control" />
                        </div>
                    </form>
                    <div class="filter-export-area d-flex align-items-center">
                        <div class="position-relative">
                            <button class="eBtn-3 dropdown-toggle" type="button" id="defaultDropdown"
                                data-bs-toggle="dropdown" data-bs-auto-close="true" aria-expanded="false">
                                <span class="pr-10">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14.028" height="12.276"
                                        viewBox="0 0 14.028 12.276">
                                        <path id="filter-solid"
                                            d="M.106,32.627A1.1,1.1,0,0,1,1.1,32H12.934a1.092,1.092,0,0,1,.989.627,1.054,1.054,0,0,1-.164,1.164l-4.99,6.126V43.4a.877.877,0,0,1-1.4.7L5.612,42.786a.871.871,0,0,1-.351-.7V39.917L.248,33.79a1.1,1.1,0,0,1-.142-1.164Z"
                                            transform="translate(0 -32)" fill="#00a3ff" />
                                    </svg>
                                </span>
                                {{ get_phrase('Filter') }}
                            </button>
                            <div class="dropdown-menu dropdown-menu-end filter-options" aria-labelledby="defaultDropdown">
                                <h4 class="title">{{ get_phrase('Filter Options') }}</h4>

                                <form action="{{ route('student.club.list') }}" method="GET">
                                    <div class="filter-option d-flex flex-column">

                                        @if ($search != '')
                                            <input type="hidden" name="search" value="{{ $search }}">
                                        @endif

                                        <div>
                                            <label class="eForm-label">{{ get_phrase('Teacher') }}</label>

                                            <select name="advisor_id" class="form-control">
                                                <option value="">{{ get_phrase('Select Teacher') }}</option>

                                                @foreach ($teachers as $teacher)
                                                    <option value="{{ $teacher->id }}"
                                                        {{ request('advisor_id') == $teacher->id ? 'selected' : '' }}>
                                                        {{ $teacher->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>

                                    <div class="filter-button d-flex justify-content-end align-items-center mt-3">
                                        <button class="eBtn eBtn btn-primary" type="submit">
                                            {{ get_phrase('Apply') }}
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <table id="basic-datatable" class="table eTable eTable-2">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Club Name</th>
                    <th>Teacher Name</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th>Options</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($clubs as $club)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $club->club_name }}</td>
                        <td>
                            {{ optional($club->advisor)->name ?? 'N/A' }}
                        </td>
                        <td>
                            {{ Str::limit($club->description, 40) }}
                        </td>
                        <td>
                            @if ($club->status)
                                <span class="eBadge ebg-success">Active</span>
                            @else
                                <span class="eBadge ebg-danger">Inactive</span>
                            @endif
                        </td>
                        @php
                            $membership = $club
                                ->members()
                                ->where('student_id', auth()->id())
                                ->first();
                        @endphp

                        <td class="text-start">
                            <div class="adminTable-action">
                                <button type="button" class="eBtn eBtn-black dropdown-toggle table-action-btn-2"
                                    data-bs-toggle="dropdown">
                                    {{ get_phrase('Actions') }}
                                </button>

                                <ul class="dropdown-menu dropdown-menu-end eDropdown-menu-2 eDropdown-table-action">

                                    {{-- JOIN / REMOVE / LEAVE --}}
                                    <li>
                                        @if (!$membership)
                                            {{-- Not joined --}}
                                            
                                             <a class="dropdown-item" href="javascript:;"
                                                onclick="confirmModal('{{ route('club.join', $club->id) }}', 'undefined');">
                                                Join Club
                                            </a>
                                        @elseif ($membership->status == 0)
                                            {{-- Pending request --}}
                                             <a class="dropdown-item" href="javascript:;"
                                                onclick="confirmModal('{{ route('club.removeRequest', $club->id) }}', 'undefined');">
                                                 Remove Request
                                            </a>
                                        @elseif ($membership->status == 1)
                                            <a class="dropdown-item" href="javascript:;"
                                                onclick="confirmModal('{{ route('club.leave', $club->id) }}', 'undefined');">
                                                Leave Club
                                            </a>
                                        @endif
                                    </li>

                                    {{-- CLUB NOTICE --}}
                                    @if ($membership && $membership->status == 1)
                                        <li>
                                             <a class="dropdown-item" href="{{ route('student.club.notice', $club->id) }}">
                                                Club Notices
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
            const element = document.getElementById("club_list");

            // clone the element
            var clonedElement = element.cloneNode(true);

            // change display of cloned element
            $(clonedElement).css("display", "block");

            // Choose the clonedElement and save the PDF for our user.
            var opt = {
                margin: 1,
                filename: 'club_list_{{ date('y-m-d') }}.pdf',
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
