<div class="fpb-7">
    <label for="affiliated_institution_name" class="eForm-label">{{ get_phrase('Affiliated University / School') }}</label>
    <input type="text" class="form-control eForm-control" id="affiliated_institution_name" name="affiliated_institution_name" maxlength="255" value="{{ old('affiliated_institution_name', $studentProfile->affiliated_institution_name ?? '') }}">
    <small class="text-muted">{{ get_phrase('For PIIE students studying at another institution. Leave blank if not affiliated.') }}</small>
</div>
<div class="fpb-7">
    <label for="affiliated_student_id" class="eForm-label">{{ get_phrase('Student ID at Affiliated Institution') }}</label>
    <input type="text" class="form-control eForm-control" id="affiliated_student_id" name="affiliated_student_id" maxlength="100" value="{{ old('affiliated_student_id', $studentProfile->affiliated_student_id ?? '') }}">
    <small class="text-muted">{{ get_phrase('Record the partner institution ID here. The PIIE registration number is retained separately.') }}</small>
</div>
