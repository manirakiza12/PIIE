@unless($readOnly)
    <div class="ap-card mt-4">
        <div class="ap-card-head">
            <h2 class="ap-card-title"><i class="bi bi-send-check"></i> {{ get_phrase('Declaration') }}</h2>
        </div>

        <form action="{{ route('applicant.application.submit') }}" method="POST">
            @csrf

            <div class="form-check mb-4">
                <input class="form-check-input" type="checkbox" name="declaration" id="declaration" value="1" {{ $canSubmit ? '' : 'disabled' }}>
                <label class="form-check-label" for="declaration" style="font-size:14.5px;">
                    {{-- Declaration text is deliberately not run through get_phrase(): that
                         helper auto-inserts any string it's given into language.phrase, a
                         column capped at varchar(191) — this sentence is longer than that
                         and is legal/declaration wording we don't want to paraphrase-shorten
                         just to fit a translation-lookup table. --}}
                    I declare that the information given in this application is true and complete to the best of my knowledge. I understand that giving false information may lead to my application being rejected or my admission being cancelled.
                </label>
            </div>

            <div class="d-flex flex-wrap gap-2 justify-content-end">
                <a href="{{ route('applicant.application.step', 'review') }}" class="ap-btn ap-btn-ghost">
                    <i class="bi bi-pencil"></i> {{ get_phrase('Back to Review') }}
                </a>
                <button type="submit" class="ap-btn ap-btn-accent" {{ $canSubmit ? '' : 'disabled' }}>
                    <i class="bi bi-send"></i>
                    {{ $admission->status === \App\Models\Admission::STATUS_NEEDS_CORRECTION ? get_phrase('Resubmit Application') : get_phrase('Submit Application') }}
                </button>
            </div>

            @unless($canSubmit)
                <p class="ap-hint text-end mt-2 mb-0">{{ get_phrase('Resolve the items listed above to enable submission.') }}</p>
            @endunless
        </form>
    </div>
@endunless
