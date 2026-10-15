<div class="eoff-form">
    <form method="POST" enctype="multipart/form-data" class="d-block ajaxForm" action="{{ route('admin.class.update', ['id' => $class->id]) }}">
         @csrf 
        <div class="form-row">
            <div class="fpb-7">
                <label for="name" class="eForm-label">{{ get_phrase('Name') }}</label>
                <input type="text" class="form-control eForm-control" id="name" name="name" value="{{ $class->name }}" required>
            </div>

            <div class="fpb-7 pt-2">
                <button class="btn-form" type="submit">{{ get_phrase('Update').' '.academic_term('class', auth()->user()->school_id) }}</button>
            </div>
        </div>
    </form>
</div>
