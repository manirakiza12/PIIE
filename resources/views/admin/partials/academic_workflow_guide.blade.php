@php($academicGuide = app(\App\Support\Academic\AcademicWorkflowGuide::class)->forRequest(auth()->user(), request()))
@if($academicGuide)
<aside class="border rounded bg-white p-3 mb-3 print-hidden" aria-label="Academic workflow guidance">
    <details>
        <summary class="fw-semibold">Setup help and next steps</summary>
        <p class="mt-2">{{ $academicGuide['current']['purpose'] }}</p><p>{{ $academicGuide['current']['before'] }}</p>
        <p class="small text-muted mt-2">Follow this order for a new programme. Reuse existing records. Open each module from the navigation. The order below follows the setup dependencies.</p>
        <ol class="mb-0 ps-4">
            @foreach($academicGuide['steps'] as $step)
            <li class="mb-2" style="list-style: decimal" @if($step['key'] === $academicGuide['current']['key']) aria-current="step" @endif>
                <strong>{{ $step['label'] }}</strong>
                <span class="d-block small text-muted">{{ $step['purpose'] }}</span>
            </li>
            @endforeach
        </ol>
    </details>
</aside>
@endif
