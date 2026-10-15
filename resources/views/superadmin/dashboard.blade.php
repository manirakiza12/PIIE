@extends('superadmin.navigation')
@section('content')   
@if(\App\Support\SuperAdminWorkspace::showsSubscriptions())
@php

$month_wise_payment = array('Jan' => 0, 'Feb' => 0, 'Mar' => 0, 'Apr' => 0, 'May' => 0, 'Jun' => 0, 'Jul' => 0, 'Aug' => 0, 'Sep' => 0, 'Oct' => 0, 'Nov' => 0, 'Dec' => 0);
$month_wise_payment_for_json = array();
$total_subscription_payment = 0;

foreach($month_wise_payment as $month => $payment){
    $current_year = date('Y');
    $start_date = strtotime('1 '.$month.' '.$current_year);
    $end_date = strtotime(date('t M Y', $start_date));
    $total_payment_in_this_month = DB::table('subscriptions')->where('date_added', '>=', $start_date)->where('date_added', '<=', $end_date)->sum('paid_amount');

	$total_subscription_payment += $total_payment_in_this_month;

    array_push($month_wise_payment_for_json, array("month" => $month, "amount" => $total_payment_in_this_month));
}

$month_wise_payment = $month_wise_payment_for_json;
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
<!-- Start Alerts -->
<div class="row">
	<div class="col-12">
		<div class="eSection-dashboardItems">
			<div class="row flex-wrap">
				<div class="col-lg-6">
					<div class="dashboard_ShortListItems">
						<div class="row">
							<div class="col-md-6">
								<div class="dashboard_ShortListItem">
									<div class="dsHeader d-flex justify-content-between align-items-center">
										<h5 class="title">{{ get_phrase('Schools') }}</h5>
										<a href="{{ route('superadmin.school.list') }}" class="ds_link ds_sutdent">
											<svg xmlns="http://www.w3.org/2000/svg" width="10.146" height="4.764" viewBox="0 0 10.146 4.764"><path id="Read_more_icon" data-name="Read more icon" d="M11.337,5.978l-.84.84.941.947H3.573V8.955h7.865L10.5,9.9l.84.846L13.719,8.36Z" transform="translate(-3.573 -5.978)" fill="#000000"/></svg>
										</a>
									</div>
									<div class="dsBody d-flex justify-content-between align-items-center">
										<div class="ds_item_details">
											<h4 class="total_no">{{ DB::table('schools')->count() }}</h4>
											<p class="total_info">{{ get_phrase('Total Schools') }}</p>
										</div>
										<div class="ds_item_icon">
											<img src="{{ asset('assets/images/Student_icon.png') }}" alt="" />
										</div>
									</div>
								</div>
							</div>
							<div class="col-md-6">
								<div class="dashboard_ShortListItem">
									<div class="dsHeader d-flex justify-content-between align-items-center">
										<h5 class="title">{{ get_phrase('Subscription') }}</h5>
										<a href="{{ route('superadmin.subscription.report') }}" class="ds_link ds_parent">
											<svg xmlns="http://www.w3.org/2000/svg" width="10.146" height="4.764" viewBox="0 0 10.146 4.764"><path id="Read_more_icon" data-name="Read more icon" d="M11.337,5.978l-.84.84.941.947H3.573V8.955h7.865L10.5,9.9l.84.846L13.719,8.36Z" transform="translate(-3.573 -5.978)" fill="#000000"/></svg>
										</a>
									</div>
									<div class="dsBody d-flex justify-content-between align-items-center">
										<div class="ds_item_details">
											<h4 class="total_no">{{ DB::table('subscriptions')->where('expire_date', '>=', time())->count() }}</h4>
											<p class="total_info">{{ get_phrase('Total Active Subscription') }}</p>
										</div>
										<div class="ds_item_icon">
											<img src="{{ asset('assets/images/Parents_icon.png') }}" alt="" />
										</div>
									</div>
								</div>
							</div>
							<div class="col-md-6">
								<div class="dashboard_ShortListItem">
									<div class="dsHeader d-flex justify-content-between align-items-center">
										<h5 class="title">{{ get_phrase('Packages') }}</h5>
										<a href="{{ route('superadmin.package') }}" class="ds_link ds_teacher">
											<svg xmlns="http://www.w3.org/2000/svg" width="10.146" height="4.764" viewBox="0 0 10.146 4.764"><path id="Read_more_icon" data-name="Read more icon" d="M11.337,5.978l-.84.84.941.947H3.573V8.955h7.865L10.5,9.9l.84.846L13.719,8.36Z" transform="translate(-3.573 -5.978)" fill="#000000"/></svg>
										</a>
									</div>
									<div class="dsBody d-flex justify-content-between align-items-center">
										<div class="ds_item_details">
											<h4 class="total_no">{{ DB::table('packages')->count() }}</h4>
											<p class="total_info">{{ get_phrase('Total Packages') }}</p>
										</div>
										<div class="ds_item_icon">
											<img src="{{ asset('assets/images/Teacher_icon.png') }}" alt="" />
										</div>
									</div>
								</div>
							</div>
							<div class="col-md-6">
								<div class="dashboard_ShortListItem">
									<div class="dsHeader d-flex justify-content-between align-items-center">
										<h5 class="title">{{ get_phrase('Pending Requests') }}</h5>
										<a href="{{ route('superadmin.school.list') }}" class="ds_link ds_staff">
											<svg xmlns="http://www.w3.org/2000/svg" width="10.146" height="4.764" viewBox="0 0 10.146 4.764"><path id="Read_more_icon" data-name="Read more icon" d="M11.337,5.978l-.84.84.941.947H3.573V8.955h7.865L10.5,9.9l.84.846L13.719,8.36Z" transform="translate(-3.573 -5.978)" fill="#000000"/></svg>
										</a>
									</div>
									<div class="dsBody d-flex justify-content-between align-items-center">
										<div class="ds_item_details">
											<h4 class="total_no">{{ DB::table('schools')->where('status', 0)->count() }}</h4>
											<p class="total_info">{{ get_phrase('Total Pending Requests') }}</p>
										</div>
										<div class="ds_item_icon">
											<img src="{{ asset('assets/images/Staff_icon.png') }}" alt="" />
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
				<div class="col-lg-6">
					<div class="dashboard_report dashboard_income_report">
						<div class="ds_report_header d-flex justify-content-between align-items-start">
							<div class="ds_report_left">
								<h4 class="title">{{ get_phrase('Subscription Payment') }}</h4>
								<div class="ds_report_count d-flex align-items-center">
									<span class="total_no">{{ currency($total_subscription_payment) }}</span>
								</div>
							</div>
						</div>
						<div class="ds_report_list">
							<div id="chartdiv" class="chartdiv"></div>
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
	  categoryField: "month",
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
	  valueYField: "amount",
	  sequencedInterpolation: true,
	  categoryXField: "month",
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
	var data = <?php echo json_encode($month_wise_payment); ?>;
	
	xAxis.data.setAll(data);
	series.data.setAll(data);
	
	
	// Make stuff animate on load
	// https://www.amcharts.com/docs/v5/concepts/animations/
	series.appear(1000);
	chart.appear(1000, 100);
	
	}); // end am5.ready()
</script>
<!-- HTML -->
@else
    @include('superadmin.partials.institution_dashboard')
@endif
@endsection
