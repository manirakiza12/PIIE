<form method="POST" class="d-block ajaxForm" action="{{ route('admin.routine.routine_add') }}">
    @csrf 
    <div class="form-row">
        <div class="fpb-7">
            <label for="class_id_on_routine_creation" class="eForm-label">{{ academic_term('class', auth()->user()->school_id) }}</label>
            <select name="class_id" id="class_id_on_routine_creation" class="form-select eForm-select eChoice-multiple-with-remove"  required onchange="classWiseSectionForRoutineCreate(this.value)">
                <option value="">{{ get_phrase('Select').' '.academic_term('class', auth()->user()->school_id) }}</option>
                <?php foreach($classes as $class): ?>
                    <option value="{{ $class['id'] }}">{{ $class['name'] }}</option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="fpb-7">
            <label for="section_id_on_routine_creation" class="eForm-label">{{ academic_term('section', auth()->user()->school_id) }}</label>
            <select name="section_id" id = "section_id_on_routine_creation" class="form-select eForm-select eChoice-multiple-with-remove"  required>
                <option value="">{{ get_phrase('Select').' '.academic_term('section', auth()->user()->school_id) }}</option>
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
                <option value="">{{ get_phrase('Select subject') }}</option>
            </select>
        </div>

        <div class="fpb-7">
            <label for="teacher" class="eForm-label">{{ get_phrase('Teacher') }}</label>
            <select name="teacher_id" id = "teacher_on_routine_creation" class="form-select eForm-select eChoice-multiple-with-remove"  required>
                <option value="">{{ get_phrase('Assign a teacher') }}</option>
                <?php foreach($teachers as $teacher): ?>
                    <option value="{{ $teacher['id'] }}">{{ $teacher->name }}</option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="fpb-7">
            <label for="class_room_id" class="eForm-label">{{ get_phrase('Class room') }}</label>
            <select name="class_room_id" id = "class_room_id_on_routine_creation" class="form-select eForm-select eChoice-multiple-with-remove"  required>
                <option value="">{{ get_phrase('Select a class room') }}</option>
                <?php foreach($class_rooms as $class_room): ?>
                    <option value="{{ $class_room['id'] }}">{{ $class_room['name'] }}</option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="fpb-7">
            <label for="day" class="eForm-label">{{ get_phrase('Day') }}</label>
            <select name="day" id = "day_on_routine_creation" class="form-select eForm-select eChoice-multiple-with-remove"  required>
                <option value="">{{ get_phrase('Select a day') }}</option>
                <option value="saturday">{{ get_phrase('Saturday') }}</option>
                <option value="sunday">{{ get_phrase('Sunday') }}</option>
                <option value="monday">{{ get_phrase('Monday') }}</option>
                <option value="tuesday">{{ get_phrase('Tuesday') }}</option>
                <option value="wednesday">{{ get_phrase('Wednesday') }}</option>
                <option value="thursday">{{ get_phrase('Thursday') }}</option>
                <option value="friday">{{ get_phrase('Friday') }}</option>
            </select>
        </div>

        <div class="fpb-7">
            <label for="starting_hour" class="eForm-label">{{ get_phrase('Starting hour') }}</label>
            <select name="starting_hour" id = "starting_hour_on_routine_creation" class="form-select eForm-select eChoice-multiple-with-remove"  required>
                <option value="">{{ get_phrase('Starting hour') }}</option>
                <?php for($i = 0; $i <= 23; $i++){
                    if ($i < 12){
                        if ($i == 0){ ?>
                            <option value="{{ $i }}">12 AM</option>
                        <?php }else{ ?>
                            <option value="{{ $i }}">{{ $i }} AM</option>
                        <?php } ?>
                    <?php }else{ ?>
                        <?php $j = $i - 12; ?>

                        <?php if ($j == 0){ ?>
                            <option value="{{ $i }}">12 PM</option>
                        <?php }else{ ?>
                            <option value="{{ $i }}">{{ $j }} PM</option>
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
                    <option value="{{ $i }}">{{ $i }}</option>
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
                            <option value="{{ $i }}">12 AM</option>
                        <?php }else{ ?>
                            <option value="{{ $i }}">{{ $i }} AM</option>
                        <?php } ?>
                    <?php }else{ ?>
                        <?php $j = $i - 12; ?>

                        <?php if ($j == 0){ ?>
                            <option value="{{ $i }}">12 PM</option>
                        <?php }else{ ?>
                            <option value="{{ $i }}">{{ $j }} PM</option>
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
                    <option value="{{ $i }}">{{ $i }}</option>
                <?php } ?>
            </select>
        </div>

        <div class="fpb-7 pt-2">
            <button class="btn-form" type="submit">{{ get_phrase('Add routine') }}</button>
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
