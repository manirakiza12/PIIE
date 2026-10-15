<?php 

use App\Models\Section;
use App\Models\Subject;
use App\Models\Session;

// Sessions are school-owned: this school's active session (Pre-RBAC cleanup).
$active_session = Session::where('school_id', auth()->user()->school_id)->where('status', 1)->first();

?>
<form method="POST" class="d-block ajaxForm" action="{{ route('admin.routine.update', ['id' => $routine->id ]) }}">
    @csrf 
    <div class="form-row">
        <div class="fpb-7">
            <label for="class_id_on_routine_creation" class="eForm-label">{{ academic_term('class', auth()->user()->school_id) }}</label>
            <select name="class_id" id="class_id_on_routine_creation" class="form-select eForm-select eChoice-multiple-with-remove"  required onchange="classWiseSectionForRoutineCreate(this.value)">
                <option value="">{{ get_phrase('Select').' '.academic_term('class', auth()->user()->school_id) }}</option>
                <?php foreach($classes as $class): ?>
                    <option value="{{ $class['id'] }}" {{ $routine->class_id == $class['id'] ?  'selected':'' }}>{{ $class['name'] }}</option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="fpb-7">
            <label for="section_id_on_routine_creation" class="eForm-label">{{ academic_term('section', auth()->user()->school_id) }}</label>
            <select name="section_id" id = "section_id_on_routine_creation" class="form-select eForm-select eChoice-multiple-with-remove" required>
                <option value="">{{ get_phrase('Select a section') }}</option>
                <?php $sections = Section::where(['class_id' => $routine['class_id']])->get(); ?>
                <?php foreach($sections as $section): ?>
                    <option value="{{ $section['id'] }}" {{ $routine->section_id == $section['id'] ?  'selected':'' }}>{{ $section['name'] }}</option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="fpb-7">
            <label for="subject_id_on_routine_creation" class="eForm-label">
                {{ get_phrase('Subject') }}
                <a href="javascript:;" class="ms-1" title="{{ get_phrase('Add Subject') }}" onclick="rightModal('{{ route('admin.subject.open_modal') }}', '{{ get_phrase('Create Subject') }}')">
                    <i class="bi bi-plus-circle"></i>
                </a>
            </label>
            <select name="subject_id" id = "subject_id_on_routine_creation" class="form-select eForm-select eChoice-multiple-with-remove"  required>
                <option value="">{{ get_phrase('Select a subject') }}</option>
                <?php $subjects = Subject::where(['class_id' => $routine['class_id'], 'session_id' => $active_session->id])->get(); ?>
                <?php foreach($subjects as $subject): ?>
                    <option value="{{ $subject['id'] }}" {{ $routine->subject_id == $subject['id'] ?  'selected':'' }}>{{ $subject['name'] }}</option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="fpb-7">
            <label for="teacher" class="eForm-label">{{ get_phrase('Teacher') }}</label>
            <select name="teacher_id" id = "teacher_on_routine_creation" class="form-select eForm-select eChoice-multiple-with-remove"  required>
                <option value="">{{ get_phrase('Assign a teacher') }}</option>
                <?php foreach($teachers as $teacher): ?>
                    <option value="{{ $teacher['id'] }}" {{ $routine->teacher_id == $teacher['id'] ?  'selected':'' }}>{{ $teacher->name }}</option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="fpb-7">
            <label for="class_room_id" class="eForm-label">{{ get_phrase('Class room') }}</label>
            <select name="class_room_id" id = "class_room_id_on_routine_creation" class="form-select eForm-select eChoice-multiple-with-remove"  required>
                <option value="">{{ get_phrase('Select a class room') }}</option>
                <?php foreach($class_rooms as $class_room): ?>
                    <option value="{{ $class_room['id'] }}" {{ $routine->room_id == $class_room['id'] ?  'selected':'' }}>{{ $class_room['name'] }}</option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="fpb-7">
            <label for="day" class="eForm-label">{{ get_phrase('Day') }}</label>
            <select name="day" id = "day_on_routine_creation" class="form-select eForm-select eChoice-multiple-with-remove"  required>
                <option value="">{{ get_phrase('Select a day') }}</option>
                <option value="saturday" {{ $routine->day == 'saturday' ?  'selected':'' }}>{{ get_phrase('Saturday') }}</option>
                <option value="sunday" {{ $routine->day == 'sunday' ?  'selected':'' }}>{{ get_phrase('Sunday') }}</option>
                <option value="monday" {{ $routine->day == 'monday' ?  'selected':'' }}>{{ get_phrase('Monday') }}</option>
                <option value="tuesday" {{ $routine->day == 'tuesday' ?  'selected':'' }}>{{ get_phrase('Tuesday') }}</option>
                <option value="wednesday" {{ $routine->day == 'wednesday' ?  'selected':'' }}>{{ get_phrase('Wednesday') }}</option>
                <option value="thursday" {{ $routine->day == 'thursday' ?  'selected':'' }}>{{ get_phrase('Thursday') }}</option>
                <option value="friday" {{ $routine->day == 'friday' ?  'selected':'' }}>{{ get_phrase('Friday') }}</option>
            </select>
        </div>

        <div class="fpb-7">
            <label for="starting_hour" class="eForm-label">{{ get_phrase('Starting hour') }}</label>
            <select name="starting_hour" id = "starting_hour_on_routine_creation" class="form-select eForm-select eChoice-multiple-with-remove"  required>
                <option value="">{{ get_phrase('Starting hour') }}</option>
                <?php for($i = 0; $i <= 23; $i++){
                    if ($i < 12){
                        if ($i == 0){ ?>
                            <option value="{{ $i }}" {{ $routine->starting_hour == $i ?  'selected':'' }}>12 AM</option>
                        <?php }else{ ?>
                            <option value="{{ $i }}" {{ $routine->starting_hour == $i ?  'selected':'' }}>{{ $i }} AM</option>
                        <?php } ?>
                    <?php }else{ ?>
                        <?php $j = $i - 12; ?>

                        <?php if ($j == 0){ ?>
                            <option value="{{ $i }}" {{ $routine->starting_hour == $i ?  'selected':'' }}>12 PM</option>
                        <?php }else{ ?>
                            <option value="{{ $i }}" {{ $routine->starting_hour == $i ?  'selected':'' }}>{{ $j }} PM</option>
                        <?php } ?>
                    <?php } ?>
                <?php } ?>
            </select>
        </div>

        <div class="fpb-7">
            <label for="starting_minute" class="eForm-label">{{ get_phrase('Starting minute') }}</label>
            <select name="starting_minute" id = "starting_minute_on_routine_creation" class="form-select eForm-select eChoice-multiple-with-remove"  required>
                <option value="">{{ get_phrase('Starting minute') }}</option>
                <?php for($i = 0; $i <= 55; $i = $i+5){ ?>
                    <option value="{{ $i }}" {{ $routine->starting_minute == $i ?  'selected':'' }}>{{ $i }}</option>
                <?php } ?>
            </select>
        </div>

        <div class="fpb-7">
            <label for="ending_hour" class="eForm-label">{{ get_phrase('Ending hour') }}</label>
            <select name="ending_hour" id = "ending_hour_on_routine_creation" class="form-select eForm-select eChoice-multiple-with-remove"  required>
                <option value="">{{ get_phrase('Ending hour') }}</option>
                <?php for($i = 0; $i <= 23; $i++){
                    if ($i < 12){
                        if ($i == 0){ ?>
                            <option value="{{ $i }}" {{ $routine->ending_hour == $i ?  'selected':'' }}>12 AM</option>
                        <?php }else{ ?>
                            <option value="{{ $i }}" {{ $routine->ending_hour == $i ?  'selected':'' }}>{{ $i }} AM</option>
                        <?php } ?>
                    <?php }else{ ?>
                        <?php $j = $i - 12; ?>

                        <?php if ($j == 0){ ?>
                            <option value="{{ $i }}" {{ $routine->ending_hour == $i ?  'selected':'' }}>12 PM</option>
                        <?php }else{ ?>
                            <option value="{{ $i }}" {{ $routine->ending_hour == $i ?  'selected':'' }}>{{ $j }} PM</option>
                        <?php } ?>
                    <?php } ?>
                <?php } ?>
            </select>
        </div>

        <div class="fpb-7">
            <label for="ending_minute" class="eForm-label">{{ get_phrase('Ending minute') }}</label>
            <select name="ending_minute" id = "ending_minute_on_routine_creation" class="form-select eForm-select eChoice-multiple-with-remove"  required>
                <option value="">{{ get_phrase('Ending minute') }}</option>
                <?php for($i = 0; $i <= 55; $i = $i+5){ ?>
                    <option value="{{ $i }}" {{ $routine->ending_minute == $i ?  'selected':'' }}>{{ $i }}</option>
                <?php } ?>
            </select>
        </div>

        <div class="fpb-7 pt-2">
            <button class="btn-form" type="submit">{{ get_phrase('Update routine') }}</button>
        </div>

    </div>
</form>


<script type="text/javascript">

    "use strict";

    function classWiseSectionForRoutineCreate(classId) {
        let url = "{{ route('admin.class_wise_sections', ['id' => ":classId"]) }}";
        url = url.replace(":classId", classId);
        $.ajax({
            url: url,
            success: function(response){
                $('#section_id_on_routine_creation').html(response);
                classWiseSubjectForRoutineCreate(classId);
            }
        });
    }

    function classWiseSubjectForRoutineCreate(classId) {
        let url = "{{ route('admin.class_wise_subject', ['id' => ":classId"]) }}";
        url = url.replace(":classId", classId);
        $.ajax({
            url: url,
            success: function(response){
                $('#subject_id_on_routine_creation').html(response);
            }
        });
    }

    $(document).ready(function () {
      $(".eChoice-multiple-with-remove").select2();
    });
</script>
