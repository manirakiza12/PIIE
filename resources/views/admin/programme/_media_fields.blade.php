@php
    $galleryReady = $galleryReady ?? \Illuminate\Support\Facades\Schema::hasTable('programme_gallery_images');
    $galleryImages = $galleryImages ?? collect();
    $galleryRemaining = $galleryRemaining ?? 2;
    $coverUrl = $coverUrl ?? null;
@endphp
<section class="programme-editor-card" aria-labelledby="programme-images-title">
    <h5 id="programme-images-title">{{ get_phrase('Programme images') }}</h5>
    <p class="text-muted small">{{ get_phrase('Choose your images now. All changes are saved together with the programme.') }}</p>
    <label class="eForm-label" for="piie-cover">{{ get_phrase('Cover image') }}</label>
    @unless($coverUrl)<p class="piie-no-cover small text-muted">{{ get_phrase('No cover selected. Choose an image below, or keep the programme without a cover.') }}</p>@endunless
    <img id="piie-cover-preview" class="programme-image-preview {{ $coverUrl ? '' : 'd-none' }}" @if($coverUrl) src="{{ $coverUrl }}" @endif alt="{{ get_phrase('Cover preview') }}">
    <input type="file" id="piie-cover" class="form-control" name="cover_image" data-preview="piie-cover-preview" accept=".jpg,.jpeg,.png,.webp">
    <small class="text-muted d-block mt-1">{{ get_phrase('JPG, PNG or WebP, up to 4 MB per image.') }}</small>
    @if($coverUrl)
        <label class="form-check mt-2"><input class="form-check-input" type="checkbox" name="remove_cover" value="1"> <span>{{ get_phrase('Remove existing cover when saving') }}</span></label>
    @endif
    @error('cover_image')<div class="text-danger" role="alert">{{ $message }}</div>@enderror

    <div class="mt-3" data-testid="piie-programme-gallery">
        <h6>{{ get_phrase('Additional gallery images') }} <span class="badge bg-light text-dark border" data-testid="piie-gallery-count">{{ $galleryImages->count() }} / 2</span></h6>
        <p class="small text-muted">{{ trans_choice('{1} :count slot left|[0,0] :count slots left|[2,*] :count slots left', $galleryRemaining, ['count' => $galleryRemaining]) }}</p>
        @if($galleryReady)
            <div class="programme-gallery-fields">
                @foreach($galleryImages as $galleryImage)
                    <div>
                        @if($galleryImage['url'])<img class="programme-image-preview" src="{{ $galleryImage['url'] }}" alt="{{ $galleryImage['name'] ?: get_phrase('Gallery image') }}">@else<p class="small text-muted">{{ get_phrase('Image file missing') }}</p>@endif
                        <label class="form-check"><input class="form-check-input" type="checkbox" name="remove_gallery_images[]" value="{{ $galleryImage['id'] }}"> <span>{{ get_phrase('Remove when saving') }}</span></label>
                    </div>
                @endforeach
            </div>
            <div class="programme-gallery-fields mt-2">
                @for($i = 0; $i < 2; $i++)
                    <div>
                        <label class="eForm-label" for="piie-gallery-{{ $i }}">{{ get_phrase('Gallery image') }} {{ $i + 1 }}</label>
                        <img id="piie-gallery-preview-{{ $i }}" class="programme-image-preview d-none" alt="{{ get_phrase('Selected image preview') }}">
                        <input type="file" id="piie-gallery-{{ $i }}" class="form-control" name="gallery_images[]" data-preview="piie-gallery-preview-{{ $i }}" accept=".jpg,.jpeg,.png,.webp">
                    </div>
                @endfor
            </div>
            <small class="text-muted d-block mt-2">{{ get_phrase('Up to two gallery images. To replace an existing image, select it for removal and choose a new file.') }}</small>
        @else
            <p class="small text-muted">{{ get_phrase('Gallery uploads are not available yet on this installation.') }}</p>
        @endif
        @error('gallery_images')<div class="text-danger" role="alert">{{ $message }}</div>@enderror
        @error('gallery_images.*')<div class="text-danger" role="alert">{{ $message }}</div>@enderror
    </div>
</section>
<section class="programme-editor-card mt-3" aria-labelledby="programme-website-title">
    <h5 id="programme-website-title">{{ get_phrase('Website catalogue') }}</h5>
    <input type="hidden" name="is_published" value="0">
    <label class="form-check"><input class="form-check-input" type="checkbox" name="is_published" value="1" {{ old('is_published', $programme?->is_published ?? false) ? 'checked' : '' }} {{ $programme && !$programme->is_active ? 'disabled' : '' }}> <span>{{ get_phrase('Publish this programme on the website') }}</span></label>
    <small class="text-muted d-block mb-3">{{ get_phrase('Leave unchecked to keep this programme off the public catalogue.') }}</small>
    <label class="eForm-label" for="piie-order">{{ get_phrase('Catalogue order') }}</label>
    <input type="number" id="piie-order" class="form-control" name="website_sort_order" value="{{ old('website_sort_order', $programme->website_sort_order ?? '') }}" min="0" max="9999" placeholder="{{ get_phrase('Last') }}">
    @if($programme)
        <a class="btn btn-outline-secondary btn-sm mt-3" href="{{ route('admin.programmes.preview', $programme->id) }}" target="_blank" rel="noopener">{{ get_phrase('Preview saved programme') }}</a>
    @endif
    @error('is_published')<div class="text-danger" role="alert">{{ $message }}</div>@enderror
</section>
