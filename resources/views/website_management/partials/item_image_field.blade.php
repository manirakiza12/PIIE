{{--
    ===========================================================================
    CMS IMAGE FIELD — upload, preview, ratio guidance, replace and remove.
    ===========================================================================

    ONE partial, used by both the "create item" and "edit item" forms, so the
    two cannot drift apart.

    ── PROGRAMME ITEMS GET THE 16:9 TREATMENT ────────────────────────────────
    `$isProgramme` is decided server-side by the same rule the controller uses
    (`item_type === 'programme'` or a `programme_catalog*` section), so the
    guidance shown here always matches the processing actually applied. A
    leadership portrait is NOT told it will be cropped to 16:9, because it will
    not be.
--}}

@php
    $idSuffix = $idSuffix ?? 'new';
    $isProgramme = $isProgramme ?? false;
    $currentImage = $currentImage ?? null;
    $currentUrl = $currentImage ? asset('assets/uploads/website/'.$currentImage) : null;
@endphp

<div class="col-md-4 fpb-7">
    <label class="eForm-label" for="piie-img-{{ $idSuffix }}">Image</label>

    <input type="file"
           name="image"
           id="piie-img-{{ $idSuffix }}"
           class="form-control eForm-control-file piie-img__input"
           accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
           data-piie-image-input>

    {{-- LIVE PREVIEW of a newly chosen file, before it is saved.
         Plain browser file reading: the file never leaves the machine until the
         form is submitted, so "preview before saving" costs no server round trip
         and uploads nothing extra. --}}
    <div class="piie-img__preview piie-img__preview--pending d-none mt-2"
         data-piie-image-preview
         aria-live="polite">
        <img src="" alt="" class="piie-img__frame" data-piie-image-preview-img>
        <p class="piie-img__meta" data-piie-image-preview-meta></p>
        <p class="piie-img__note">Preview only — save to apply this image.</p>
    </div>

    @if($currentUrl)
        <div class="piie-img__preview mt-2">
            <p class="piie-img__caption">Currently saved</p>
            <img src="{{ $currentUrl }}" alt="Currently saved image"
                 class="piie-img__frame" loading="lazy">
            <p class="piie-img__meta">{{ basename($currentImage) }}</p>
        </div>
    @endif

    {{-- Two hints, exactly one visible.
         On the EDIT form the server already knows the item, so `$isProgramme` is
         correct immediately. On the CREATE form the section is typed into a text
         field beside it and is therefore not known at render time — so the hint
         follows that field as it is typed. Hard-coding `true` there would promise
         a 16:9 crop for a leadership portrait, which the controller does not do. --}}
    <p class="piie-img__hint {{ $isProgramme ? '' : 'd-none' }}" data-piie-hint-programme>
        <strong>Programme cover.</strong>
        JPEG, PNG or WebP, up to 4&nbsp;MB.
        Cropped to 16:9 and optimised to
        {{ \App\Support\Images\ImageOptimizer::TARGET_WIDTH }}&nbsp;&times;&nbsp;{{ \App\Support\Images\ImageOptimizer::TARGET_HEIGHT }}&nbsp;px.
        Recommended source size is 1600&nbsp;&times;&nbsp;900&nbsp;px.
    </p>

    <p class="piie-img__hint {{ $isProgramme ? 'd-none' : '' }}" data-piie-hint-other>
        JPEG, PNG or WebP. This image type keeps its own proportions — it is not
        cropped to 16:9.
    </p>

    @if($currentImage)
        {{-- Explicit removal. Omitting the file input on save means "leave the
             image alone", which is right: otherwise every unrelated title edit
             would delete the picture. This checkbox is the explicit way to clear
             it. --}}
        <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" value="1"
                   id="piie-img-remove-{{ $idSuffix }}"
                   name="remove_image"
                   data-piie-image-remove>
            <label class="form-check-label text-danger" for="piie-img-remove-{{ $idSuffix }}">
                Remove the saved image
            </label>
        </div>
    @endif
</div>

@once
    {{-- Inline, not @push('scripts').
         The admin layout has no `@stack('scripts')`, so a push here would be
         dropped and this would silently do nothing. Adding the stack instead
         would have started rendering an unrelated dormant push on the regional
         settings page — a behaviour change outside this scope.

         DOMContentLoaded is REQUIRED, not decoration: this partial renders once
         per item, and the panel puts every item's form on a single page. `@once`
         emits this block at the FIRST include only, so without waiting for the
         parse to finish `querySelectorAll` would see one image input and wire
         up every other programme row's preview to nothing. --}}
    <script>
        /**
         * Image preview for the CMS item forms.
         *
         * Progressive enhancement, and deliberately dependency-free: the platform
         * has no build step and no front-end framework, so plain listeners are the
         * only thing that can be relied on here.
         *
         * It shows the administrator the file they have just chosen BEFORE saving,
         * along with its real dimensions, so a 4000x3000 photograph can be seen to
         * be 4:3 and understood to be cropped. Nothing is uploaded to read it —
         * `URL.createObjectURL` is local.
         */
        (function () {
            'use strict';

            /** Readable file size, so "is this over the 4 MB limit?" is answerable. */
            function human(bytes) {
                if (bytes < 1024) { return bytes + ' B'; }
                if (bytes < 1048576) { return Math.round(bytes / 1024) + ' KB'; }
                return (bytes / 1048576).toFixed(1) + ' MB';
            }

            /** Reduce a pixel size to its simplest ratio, e.g. 1600x900 -> 16:9. */
            function ratioLabel(width, height) {
                function gcd(a, b) {
                    return b ? gcd(b, a % b) : a;
                }

                var g = gcd(width, height) || 1;

                return Math.round(width / g) + ':' + Math.round(height / g);
            }

            function initImagePreviews() {
                document.querySelectorAll('[data-piie-image-input]').forEach(function (input) {
                    var scope = input.closest('.col-md-4') || document;
                    var box = scope.querySelector('[data-piie-image-preview]');
                    var img = box ? box.querySelector('[data-piie-image-preview-img]') : null;
                    var meta = box ? box.querySelector('[data-piie-image-preview-meta]') : null;

                    if (!box || !img || !meta) { return; }

                    input.addEventListener('change', function () {
                        var file = input.files && input.files[0];

                        if (!file) {
                            box.classList.add('d-none');
                            return;
                        }

                        // Release the previous object URL so repeated selections do
                        // not leak the old blob for the life of the page.
                        if (img.dataset.objectUrl) {
                            URL.revokeObjectURL(img.dataset.objectUrl);
                            delete img.dataset.objectUrl;
                        }

                        var url = URL.createObjectURL(file);
                        img.dataset.objectUrl = url;
                        img.src = url;
                        img.alt = 'Preview of ' + file.name;

                        img.onload = function () {
                            meta.textContent = file.name + ' — ' + img.naturalWidth + ' × '
                                + img.naturalHeight + ' px (' + ratioLabel(img.naturalWidth, img.naturalHeight)
                                + '), ' + human(file.size);
                            box.classList.remove('d-none');
                            img.onload = null;
                        };

                        img.onerror = function () {
                            meta.textContent = file.name + ' — this file could not be previewed. '
                                + 'It may not be a valid image.';
                            box.classList.remove('d-none');
                            img.onerror = null;
                        };
                    });
                });

                /**
                 * Keep the guidance honest on the CREATE form.
                 *
                 * The section key is typed into a text field beside the image input,
                 * so the server cannot know at render time whether this will become a
                 * programme. This watches that field and swaps the hint, so an
                 * administrator is told about the 16:9 crop when they pick a
                 * programme section — and told it does NOT apply when they pick a
                 * leadership section, which matters just as much, because that image
                 * keeps its own proportions.
                 */
                document.querySelectorAll('input[name="section_key"]').forEach(function (sectionInput) {
                    var form = sectionInput.closest('form');

                    if (!form) { return; }

                    var programme = form.querySelector('[data-piie-hint-programme]');
                    var other = form.querySelector('[data-piie-hint-other]');

                    if (!programme || !other) { return; }

                    function sync() {
                        var isProgramme = (sectionInput.value || '').trim().indexOf('programme_catalog') === 0;
                        programme.classList.toggle('d-none', !isProgramme);
                        other.classList.toggle('d-none', isProgramme);
                    }

                    sectionInput.addEventListener('input', sync);
                    sectionInput.addEventListener('change', sync);
                    sync();
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initImagePreviews);
            } else {
                initImagePreviews();
            }
        }());
    </script>
@endonce