@extends('teacher.navigation')
@section('content')   
   
   @php
       $class_wise_attandance = array();
       $total_student = 0;
       $todays_total_attandance = 0;
       $all_classes = DB::table('classes')->where('school_id', auth()->user()->school_id)->get();
       $currently_session_id = DB::table('sessions')->where('status', 1)->value('id');
   
       foreach($all_classes as $class){
           $total_student += DB::table('enrollment')->where('session_id', $currently_session_id)->where('class_id', $class->id)->where('school_id', auth()->user()->school_id)->get()->count();
   
           $start_date = strtotime(date('d M Y'));
           $end_date = $start_date + 86400;
           $today_attanded = DB::table('daily_attendances')->where('class_id', $class->id)->where('timestamp', '>=', $start_date)->where('timestamp', '<', $end_date)->get();
           array_push($class_wise_attandance, array("class_name" => $class->name, "today_attended" => $today_attanded->count()));
           $todays_total_attandance += $today_attanded->count();
       }
   @endphp
   
       <!-- Mani section header and breadcrumb -->
       <div class="mainSection-title">
       <div class="row">
         <div class="col-12">
           <div
             class="d-flex justify-content-between align-items-center flex-wrap gr-15"
           >
             <div class="d-flex flex-column">
               <h4>{{ get_phrase('Dashboard') }}</h4>
               <ul class="d-flex align-items-center eBreadcrumb-2">
                 <li><a href="#">{{ get_phrase('Home') }}</a></li>
                 <li><a href="#">{{ get_phrase('Dashboard') }}</a></li>
               </ul>
             </div>
           </div>
         </div>
       </div>
       </div>

        @if((app(\App\Support\TenantConfiguration::class)->terminology(auth()->user()->school)['profile'] ?? 'k12') !== 'k12')
        @php $myOfferings = app(\App\Support\CourseOffering\LecturerCourseOfferingAccess::class)->offerings(auth()->user()); $currentCount = $myOfferings->filter(fn ($offering) => $offering->my_allocation_is_current)->count(); @endphp
        <section class="eSection-wrap mb-3" aria-label="My Course Offerings">
          <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
              <h5 class="mb-1">My Course Offerings</h5>
              <p class="text-muted mb-0">{{ $myOfferings->count() }} {{ \Illuminate\Support\Str::plural('Course Offering', $myOfferings->count()) }} allocated to you &middot; {{ $currentCount }} currently in force.</p>
            </div>
            <a class="btn btn-primary" href="{{ route('teacher.course_offerings.index') }}">View My Course Offerings</a>
          </div>
          @if($myOfferings->isEmpty())
            <p class="text-muted mb-0 mt-3">You do not currently have any Course Offerings assigned.</p>
          @else
          <div class="row g-2 mt-1">
            @foreach($myOfferings->take(6) as $myOffering)
              <div class="col-12 col-md-6 col-xl-4">
                <a class="d-block border rounded p-3 h-100 text-decoration-none text-reset" href="{{ route('teacher.course_offerings.show', $myOffering->id) }}">
                  <div class="fw-semibold">{{ $myOffering->subject?->code }} &mdash; {{ $myOffering->subject?->name }}</div>
                  <div class="small text-muted">{{ $myOffering->academicYear?->label }} &middot; {{ $myOffering->academicPeriod?->label }}</div>
                  <div class="small">{{ $myOffering->my_role_label }} &middot; {{ $myOffering->my_confirmed_students }} confirmed {{ \Illuminate\Support\Str::plural('student', $myOffering->my_confirmed_students) }}</div>
                </a>
              </div>
            @endforeach
          </div>
          @endif
        </section>
        @endif
       <!-- Start Alerts -->
       <div class="row">
       <div class="col-12">
         <div class="eSection-dashboardItems">
           <div class="row flex-wrap">
            <div class="col-lg-12">
                <div class="dashboard_ShortListItem">
                        <h4 class="text-dark">{{ auth()->user()->name }}</h4>
                        <p>Welcome, to {{ DB::table('schools')->where('id', auth()->user()->school_id)->value('title') }}</p>
                </div>
            </div>
             <!-- Dashboard Short Details -->
             <div class="col-lg-6">
               <div class="dashboard_ShortListItems">
                 <div class="row">
                   <div class="col-md-6">
                     <div class="dashboard_ShortListItem">
                       <div
                         class="dsHeader d-flex justify-content-between align-items-center"
                       >
                         <h5 class="title">{{ get_phrase('Students') }}</h5>
                       </div>
                       <div
                         class="dsBody d-flex justify-content-between align-items-center"
                       >
                         <div class="ds_item_details">
                           <h4 class="total_no">{{ DB::table('users')->where('role_id', 7)->where('school_id', auth()->user()->school_id)->get()->count() }}</h4>
                           <p class="total_info">{{ get_phrase('Total Student') }}</p>
                         </div>
                         <div class="ds_item_icon">
                           <img
                             src="{{ asset('assets/images/Student_icon.png') }}"
                             alt=""
                           />
                         </div>
                       </div>
                     </div>
                   </div>
                   <div class="col-md-6">
                     <div class="dashboard_ShortListItem">
                       <div
                         class="dsHeader d-flex justify-content-between align-items-center"
                       >
                         <h5 class="title">{{ get_phrase('Parents') }}</h5>
                       </div>
                       <div
                         class="dsBody d-flex justify-content-between align-items-center"
                       >
                         <div class="ds_item_details">
                           <h4 class="total_no">{{ DB::table('users')->where('role_id', 6)->where('school_id', auth()->user()->school_id)->get()->count() }}</h4>
                           <p class="total_info">{{ get_phrase('Total Parent') }}</p>
                         </div>
                         <div class="ds_item_icon">
                           <img
                             src="{{ asset('assets/images/Parents_icon.png') }}"
                             alt=""
                           />
                         </div>
                       </div>
                     </div>
                   </div>
                 </div>
               </div>
             </div>
             <!-- Imcome Report -->
   
              {{--
                 THE GRID AFTER THE STAFFING CARDS WERE REMOVED.

                 `col-lg-6` on BOTH columns, matching the short-details block above.
                 It was `col-md-6 ms-auto` before, which was invisible while four
                 cards filled the left half but left an awkward half-empty gap the
                 moment the widths no longer lined up: below `lg` the left block
                 collapses to 100% while this one stayed at 50% and was pushed right,
                 so a tablet and a phone showed a 50% void.

                 Both at `lg` gives an even 50/50 on a desktop and two full-width
                 stacked blocks on anything narrower. The two remaining cards stay
                 `col-md-6` inside their own row, so they sit side by side on a
                 tablet and stack on a phone. No fixed pixel widths are introduced,
                 so nothing can overflow horizontally.
             --}}
              <!-- Upcoming Events -->
             <div class="col-lg-6">
               <div class="dashboard_report dashboard_upcoming_events">
                 <div
                   class="ds_report_header d-flex justify-content-between align-items-start"
                 >
                   <div class="ds_report_left">
                     <h4 class="title">{{ get_phrase('Upcoming Events') }}</h4>
                   </div>
                   
                 </div>
                 <div class="ds_report_list pt-38">
                   <ul class="upcoming_events_items d-flex flex-column">
   
                       @php $upcoming_events = DB::table('frontend_events')->where('school_id', auth()->user()->school_id)->where('timestamp', '>', time())->where('status', 1)->take(3)->orderBy('id', 'DESC')->get(); @endphp
                       @foreach($upcoming_events as $upcoming_event)
                       <li>
                           <div
                           class="upcoming_events_item d-flex justify-content-between align-items-start"
                           >
                           <div class="events_info">
                               <a href="#" class="title">{{ $upcoming_event->title }}</a>
                               <p class="date">{{ date('D, M d Y', $upcoming_event->timestamp) }}</p>
                           </div>
                           
                           </div>
                       </li>
                       @endforeach
                   </ul>
                   <div class="text-end">
                     <a href="{{ route('teacher.events.list') }}" class="all_report_btn_2">{{ get_phrase('See all') }}</a>
                   </div>
                 </div>
               </div>
             </div>
           </div>
         </div>
       </div>
       </div>
   
   
   <!-- Resources -->
   <script src="{{asset('assets/amchart/index.js')}}"></script>
   <script src="{{asset('assets/amchart/xy.js')}}"></script>
   <script src="{{asset('assets/amchart/animated.js')}}"></script>
   
   <!-- Chart code -->
   <script>
   "use strict";
   
   am5.ready(function() {
   
   // Create root element
   var root = am5.Root.new("chartdiv");
   
   
   // Set themes
   root.setThemes([
     am5themes_Animated.new(root)
   ]);
   
   
   // Create chart
   var chart = root.container.children.push(am5xy.XYChart.new(root, {
     panX: true,
     panY: true,
     wheelX: "panX",
     wheelY: "zoomX",
     pinchZoomX:true
   }));
   
   // Add cursor
   var cursor = chart.set("cursor", am5xy.XYCursor.new(root, {}));
   cursor.lineY.set("visible", false);
   
   
   // Create axes
   var xRenderer = am5xy.AxisRendererX.new(root, { minGridDistance: 30 });
   xRenderer.labels.template.setAll({
     rotation: -90,
     centerY: am5.p50,
     centerX: am5.p100,
     paddingRight: 15
   });
   
   var xAxis = chart.xAxes.push(am5xy.CategoryAxis.new(root, {
     maxDeviation: 0.3,
     categoryField: "class_name",
     renderer: xRenderer,
     tooltip: am5.Tooltip.new(root, {})
   }));
   
   var yAxis = chart.yAxes.push(am5xy.ValueAxis.new(root, {
     maxDeviation: 0.3,
     renderer: am5xy.AxisRendererY.new(root, {})
   }));
   
   
   // Create series
   var series = chart.series.push(am5xy.ColumnSeries.new(root, {
     name: "Series 1",
     xAxis: xAxis,
     yAxis: yAxis,
     valueYField: "today_attended",
     sequencedInterpolation: true,
     categoryXField: "class_name",
     tooltip: am5.Tooltip.new(root, {
       labelText:"{valueY}"
     })
   }));
   
   series.columns.template.setAll({ cornerRadiusTL: 5, cornerRadiusTR: 5 });
   series.columns.template.adapters.add("fill", function(fill, target) {
     return chart.get("colors").getIndex(series.columns.indexOf(target));
   });
   
   series.columns.template.adapters.add("stroke", function(stroke, target) {
     return chart.get("colors").getIndex(series.columns.indexOf(target));
   });
   
   
   // Set data
   var data = <?php echo json_encode($class_wise_attandance); ?>;
   
   xAxis.data.setAll(data);
   series.data.setAll(data);
   
   
   // Make stuff animate on load
   // https://www.amcharts.com/docs/v5/concepts/animations/
   series.appear(1000);
   chart.appear(1000, 100);
   
   }); // end am5.ready()
   </script>
   
   <!-- HTML -->
   
   
@endsection
