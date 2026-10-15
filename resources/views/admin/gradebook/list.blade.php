<?php 
use App\Models\User;
use App\Models\Section; 
?>

<div class="content_body">
	<table class="table text-nowrap table-hover w-100 light-dashed">
		<thead>
			<tr>
	            <th>#</th>
	            <th>{{ get_phrase('Image') }}</th>
	            <th>{{ get_phrase('Student Name') }}</th>
	            <th>{{ academic_term('section', auth()->user()->school_id) }}</th>
	            <th class="text-center">{{ get_phrase('Action') }}</th>
	        </tr>
		</thead>
		<tbody>
			@foreach ($exam_wise_student_list as $student)

				<?php 
		        $student_details = User::find($student->student_id);
		        $info = json_decode($student_details->user_information);
		        $user_image = $info->photo;
		        if(!empty($info->photo)){
		            $user_image = 'uploads/user-images/'.$info->photo;
		        }else{
		            $user_image = 'uploads/user-images/thumbnail.png';
		        }
		        $section_details = Section::find($student->section_id);
		        ?>
		        <tr>
		            <td>{{ $loop->index + 1 }}</td>
		            <td>
		                <img class="img-fluid" src="{{ asset('assets') }}/{{ $user_image }}" width="50" height="50">
		            </td>
		            <td>{{ $student_details->name }}</td>
		            <td>{{ $section_details->name }}</td>
		            <td class="text-center">
		            	<a href="javascript:;" class="btn btn-light-success py-1 px-2 text-14px mt-1" data-bs-toggle="tooltip" title="View Result" onclick="show_student_list('{{ $student->student_id }}')"><i class="bi bi-person-badge"></i></a>
	                    <a href="javascript:;" class="btn btn-light-success py-1 px-2 text-14px mt-1" data-bs-toggle="tooltip" title="Edit" onclick=""><i class="bi bi-pencil"></i></a>
		            </td>
		        </tr>

			@endforeach
		</tbody>
	</table>
</div>

<script type="text/javascript">

  "use strict";

	
	function show_student_list(student_id) {
		let url = "{{ route('admin.gradebook.subject_wise_marks', ['student_id' => ":student_id"]) }}";
        url = url.replace(":student_id", student_id);
        $.ajax({
            url: url,
            type: 'GET',
            data: { 
                class_id: {{ $class_id }}, 
                section_id: {{ $section_id }},
                exam_category_id: {{ $exam_category_id }}
            },
            success: function(response){
                $('.content_body').html(response);
            }
        });
    }

</script>
