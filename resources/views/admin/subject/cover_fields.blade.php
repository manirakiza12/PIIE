<div class="mb-3">
    <label for="course-unit-cover" class="eForm-label">{{ get_phrase('Course Unit cover image (optional)') }}</label>
    <input type="file" name="cover_image" id="course-unit-cover" class="form-control" accept="image/jpeg,image/png,image/webp" aria-describedby="course-unit-cover-help">
    <div id="course-unit-cover-help" class="form-text">{{ get_phrase('JPEG, PNG or WebP, up to 4 MB. Cropped to 16:9 for My Courses. This image is not published on the public programme pages.') }}</div>
    @error('cover_image')<div class="text-danger" role="alert">{{ $message }}</div>@enderror
    @if(isset($subject) && app(\App\Support\Images\CourseUnitCoverImage::class)->exists($subject))
        <img src="{{ route('admin.subject.cover', $subject->id) }}" alt="{{ get_phrase('Current Course Unit cover') }}" width="240" height="135" class="d-block rounded my-2" style="object-fit:cover">
    @endif
    @if(isset($subject) && $subject->cover_image_path)
        <div class="form-check mt-2">
            <input type="checkbox" class="form-check-input" id="remove-unit-cover" name="remove_cover_image" value="1" @checked(old('remove_cover_image'))>
            <label for="remove-unit-cover" class="form-check-label">{{ get_phrase('Remove current image') }}</label>
        </div>
        <p class="form-text">{{ get_phrase('To replace the image, choose a new file and leave Remove unchecked.') }}</p>
    @endif
</div>
