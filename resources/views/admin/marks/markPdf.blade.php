@include('shared.pdf_layout')
<style>
 td{
    width: 150px;
    border: 1px solid #797c8b;
    padding: 10px;
}
</style>
<div class="mark_report_content mark_report " id="mark_history">
    <h4 style="font-size: 16px; font-weight: 600; line-height: 26px; color: #1466AF; margin-left:45%; margin-bottom:15px; margin-top:17px;">{{ get_phrase('Exam Mark') }}</h4>

    <table class="table eTable eTable-2 table-bordered" >
        <thead>
            <tr>
                <th scope="col">{{ get_phrase('Student name') }}</th>
                <th scope="col">{{ get_phrase('Mark') }}</th>
                <th scope="col">{{ get_phrase('Grade point') }}</th>
                <th scope="col">{{ get_phrase('Comment') }}</th>
            </tr>   
        </thead>
        <tbody>
            @foreach($enroll_students as $enroll_student)
                <?php

                $student_details = App\Models\User::find($enroll_student->user_id);
                $filterd_data = App\Models\Gradebook::where('exam_category_id', $exam_category_id)
                ->where('class_id', $class_id)
                ->where('section_id', $section_id)
                ->where('session_id', $session_id)
                ->where('student_id', $enroll_student->user_id);

                if($filterd_data->value('marks')){
                    $subject_mark = json_decode($filterd_data->value('marks'), true);
                    if(!empty($subject_mark[$subject_id])) {
                        $user_marks = $subject_mark[$subject_id];
                    } else {
                        $user_marks = 0;
                    }


                } else {
                    $user_marks = 0;
                }

                if($filterd_data->value('comment')){
                    $comment = $filterd_data->value('comment');
                } else {
                    $comment = '';
                }

                ?>
                <tr>
                    <td>{{ $student_details->name }}</td>
                    <td>
                        <span id="mark-{{ $enroll_student->user_id }}">{{ $user_marks }}</span>
                    </td>
                    <td>
                        <span id="grade-for-mark-{{ $enroll_student->user_id }}">{{ get_grade($user_marks) }}</span> 
                    </td>
                    <td>
                        <span id="comment-{{ $enroll_student->user_id }}">{{ $comment }}</span>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
