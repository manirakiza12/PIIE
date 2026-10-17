<div class="eoff-form">
    <form method="POST" class="d-block"
          action="{{ $admission ? route('admin.hei_admissions.open_modal', ['id' => $admission->id]) : route('admin.hei_admissions.store') }}">
        @csrf
        <div class="form-row">
            <div class="row">
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('First Name') }} *</label>
                    <input type="text" class="form-control eForm-control" name="first_name" value="{{ $admission->first_name ?? '' }}" required></div>
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('Last Name') }} *</label>
                    <input type="text" class="form-control eForm-control" name="last_name" value="{{ $admission->last_name ?? '' }}" required></div>
            </div>
            <div class="row mt-2">
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('Email') }}</label>
                    <input type="email" class="form-control eForm-control" name="email" value="{{ $admission->email ?? '' }}"></div>
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('Phone') }}</label>
                    <input type="text" class="form-control eForm-control" name="phone" value="{{ $admission->phone ?? '' }}"></div>
            </div>
            <div class="row mt-2">
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('Programme') }}</label>
                    <select class="form-control eForm-control" name="programme_id">
                        <option value="">{{ get_phrase('— Select —') }}</option>
                        @foreach($programmes as $p)
                            <option value="{{ $p->id }}" {{ ($admission->programme_id ?? '') == $p->id ? 'selected' : '' }}>{{ $p->code }} — {{ $p->name }}</option>
                        @endforeach
                    </select></div>
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('Intake Session') }}</label>
                    <select class="form-control eForm-control" name="intake_session_id">
                        <option value="">{{ get_phrase('— Select —') }}</option>
                        @foreach($sessions as $s)
                            <option value="{{ $s->id }}" {{ ($admission->intake_session_id ?? '') == $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                        @endforeach
                    </select></div>
            </div>
            <div class="row mt-2">
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('Gender') }}</label>
                    <select class="form-control eForm-control" name="gender">
                        <option value="">{{ get_phrase('— Select —') }}</option>
                        <option value="male" {{ ($admission->gender ?? '') == 'male' ? 'selected' : '' }}>{{ get_phrase('Male') }}</option>
                        <option value="female" {{ ($admission->gender ?? '') == 'female' ? 'selected' : '' }}>{{ get_phrase('Female') }}</option>
                        <option value="other" {{ ($admission->gender ?? '') == 'other' ? 'selected' : '' }}>{{ get_phrase('Other') }}</option>
                    </select></div>
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('Date of Birth') }}</label>
                    <input type="date" class="form-control eForm-control" name="dob" value="{{ $admission->dob ?? '' }}"></div>
            </div>
            <div class="row mt-2">
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('Nationality') }}</label>
                    <input type="text" class="form-control eForm-control" name="nationality" value="{{ $admission->nationality ?? '' }}"></div>
                <div class="col-6 fpb-7"><label class="eForm-label">{{ get_phrase('Agent') }}</label>
                    <select class="form-control eForm-control" name="agent_id">
                        <option value="">{{ get_phrase('— None —') }}</option>
                        @foreach($agents as $a)
                            <option value="{{ $a->id }}" {{ ($admission->agent_id ?? '') == $a->id ? 'selected' : '' }}>{{ $a->name }}</option>
                        @endforeach
                    </select></div>
            </div>
            <div class="fpb-7 mt-2"><label class="eForm-label">{{ get_phrase('Qualifications') }}</label>
                <textarea class="form-control eForm-control" name="qualifications" rows="3">{{ $admission->qualifications ?? '' }}</textarea></div>
            <div class="fpb-7 pt-3">
                <button class="btn-form" type="submit">{{ $admission ? get_phrase('Update Application') : get_phrase('Submit Application') }}</button>
            </div>
        </div>
    </form>
</div>
