@php
    $routePrefix = request()->routeIs('teacher.*') ? 'teacher' : (request()->routeIs('student.*') ? 'student' : 'admin');
@endphp

<div class="eoff-form">
    <h6 class="mb-3">{{ $liveClass->title }}</h6>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#lcResourcesTab" type="button" role="tab">
                {{ get_phrase('Resources') }} <span class="badge bg-secondary">{{ $resources->count() }}</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#lcRecordingsTab" type="button" role="tab">
                {{ get_phrase('Recordings') }} <span class="badge bg-secondary">{{ $recordings->count() }}</span>
            </button>
        </li>
    </ul>

    <div class="tab-content">
        {{-- ── Resources: slides, readings, handouts ─────────────────────── --}}
        <div class="tab-pane fade show active" id="lcResourcesTab" role="tabpanel">
            @if($canManage)
                <form method="POST" action="{{ route($routePrefix . '.live_classes.materials.store', $liveClass->id) }}" enctype="multipart/form-data" class="mb-4" id="resourceForm">
                    @csrf
                    <input type="hidden" name="category" value="resource">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="eForm-label">{{ get_phrase('Type') }}</label>
                            <select name="type" class="form-control eForm-control" id="resourceType">
                                <option value="file">{{ get_phrase('File') }}</option>
                                <option value="link">{{ get_phrase('Link') }}</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="eForm-label">{{ get_phrase('Title') }} *</label>
                            <input type="text" name="title" class="form-control eForm-control" required maxlength="200">
                        </div>
                        <div class="col-12" id="resourceFileWrap">
                            <label class="eForm-label">{{ get_phrase('File') }} *</label>
                            <input type="file" name="file" class="form-control eForm-control" accept=".{{ implode(',.', \App\Models\LiveClassMaterial::ALLOWED_EXTENSIONS) }}">
                            <small class="text-muted">{{ get_phrase('PDF, PowerPoint, Word, Excel or image · max') }} {{ \App\Models\LiveClassMaterial::MAX_FILE_MB }}MB</small>
                        </div>
                        <div class="col-12 d-none" id="resourceLinkWrap">
                            <label class="eForm-label">{{ get_phrase('Link URL') }}</label>
                            <input type="url" name="link_url" class="form-control eForm-control" placeholder="https://...">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="eBtn eBtn-primary">{{ get_phrase('Add Resource') }}</button>
                        </div>
                    </div>
                </form>
            @endif

            @forelse($resources as $material)
                @include('admin.live_class._material_item', ['material' => $material, 'canManage' => $canManage, 'routePrefix' => $routePrefix])
            @empty
                <p class="text-muted text-center py-3">{{ get_phrase('No resources have been added yet.') }}</p>
            @endforelse
        </div>

        {{-- ── Recordings: the actual session, uploaded or linked ─────────── --}}
        <div class="tab-pane fade" id="lcRecordingsTab" role="tabpanel">
            @if($canManage)
                <form method="POST" action="{{ route($routePrefix . '.live_classes.materials.store', $liveClass->id) }}" enctype="multipart/form-data" class="mb-4" id="recordingForm">
                    @csrf
                    <input type="hidden" name="category" value="recording">
                    <div class="alert alert-info py-2 px-3 mb-3" style="font-size:13px;">
                        {{ get_phrase('For a full-length recording, a link is usually more reliable than uploading.') }}
                        {{ get_phrase('Upload is best kept for short clips only.') }}
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="eForm-label">{{ get_phrase('Type') }}</label>
                            <select name="type" class="form-control eForm-control" id="recordingType">
                                <option value="link">{{ get_phrase('Link') }}</option>
                                <option value="file">{{ get_phrase('File') }}</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="eForm-label">{{ get_phrase('Title') }} *</label>
                            <input type="text" name="title" class="form-control eForm-control" required maxlength="200">
                        </div>
                        <div class="col-12 d-none" id="recordingFileWrap">
                            <label class="eForm-label">{{ get_phrase('File') }} *</label>
                            <input type="file" name="file" class="form-control eForm-control" accept=".{{ implode(',.', \App\Models\LiveClassMaterial::ALLOWED_RECORDING_EXTENSIONS) }}">
                            <small class="text-muted">{{ get_phrase('MP4, WebM, MOV, MKV, MP3 or M4A · max') }} {{ \App\Models\LiveClassMaterial::MAX_RECORDING_MB }}MB</small>
                        </div>
                        <div class="col-12" id="recordingLinkWrap">
                            <label class="eForm-label">{{ get_phrase('Link URL') }}</label>
                            <input type="url" name="link_url" class="form-control eForm-control" placeholder="https://...">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="eBtn eBtn-primary">{{ get_phrase('Add Recording') }}</button>
                        </div>
                    </div>
                </form>
            @endif

            {{-- The authoritative recording, when there genuinely is one.

                 Only when the state says available AND there is a usable https
                 link. A PROCESSING class is deliberately NOT listed here: this
                 tab is where a student looks for something to watch, and an
                 entry with no playable file behind it reads as a broken release
                 rather than "not ready yet". --}}
            @if($primaryRecordingAvailable)
                <div class="border rounded p-3 mb-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <div class="fw-semibold"><i class="bi bi-camera-video me-1"></i>{{ get_phrase('Recording of this class') }}</div>
                        <div class="small text-muted">{{ $liveClass->recordingStateLabel() }}.</div>
                    </div>
                    <a href="{{ route('live_classes.recording.access', $liveClass->id) }}"
                       class="eBtn eBtn-primary" target="_blank" rel="noopener">{{ get_phrase('Watch Recording') }}</a>
                </div>
            @endif

            @forelse($recordings as $material)
                @include('admin.live_class._material_item', ['material' => $material, 'canManage' => $canManage, 'routePrefix' => $routePrefix])
            @empty
                @if(! $primaryRecordingAvailable)
                    @if($recordingState === \App\Models\LiveClass::RECORDING_PROCESSING)
                        <p class="text-muted text-center py-3 mb-0">
                            {{ get_phrase('The recording is being prepared. It will appear here when it becomes available.') }}
                        </p>
                        <p class="small text-center text-muted mb-0">
                            {{ get_phrase('Your lecturer has set this state manually. PIIE does not track progress on the meeting provider.') }}
                        </p>
                    @elseif($recordingState === \App\Models\LiveClass::RECORDING_UNAVAILABLE)
                        <p class="text-muted text-center py-3 mb-0">{{ get_phrase('No recording is available for this class.') }}</p>
                    @else
                        <p class="text-muted text-center py-3 mb-0">{{ get_phrase('No recording is available for this class.') }}</p>
                    @endif
                @endif
            @endforelse
        </div>
    </div>
</div>

<script>
(function () {
    function wireTypeToggle(selectId, fileWrapId, linkWrapId) {
        var typeSelect = document.getElementById(selectId);
        var fileWrap = document.getElementById(fileWrapId);
        var linkWrap = document.getElementById(linkWrapId);
        if (!typeSelect) return;

        typeSelect.addEventListener('change', function () {
            var isLink = typeSelect.value === 'link';
            fileWrap.classList.toggle('d-none', isLink);
            linkWrap.classList.toggle('d-none', !isLink);
        });
    }

    wireTypeToggle('resourceType', 'resourceFileWrap', 'resourceLinkWrap');
    wireTypeToggle('recordingType', 'recordingFileWrap', 'recordingLinkWrap');
})();
</script>
