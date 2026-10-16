@php($academicGuide = app(\App\Support\Academic\AcademicWorkflowGuide::class)->forRequest(auth()->user(), request()))
@if($academicGuide)
<aside class="border rounded bg-white p-3 mb-3" aria-label="Academic workflow guidance">
    <p class="mb-1"><strong>Purpose:</strong> {{ $academicGuide['current']['purpose'] }}</p>
    <p class="mb-2"><strong>Before you start:</strong> {{ $academicGuide['current']['before'] }}</p>
    <details>
        <summary class="fw-semibold">Academic setup order and next steps</summary>
        <p class="small text-muted mt-2">Follow this order for a new programme. Reuse existing records. Optional teaching-group and assessment steps depend on the workflow you use. Links follow your current permissions.</p>
        <ol class="mb-0 ps-4">
            @foreach($academicGuide['steps'] as $step)
            <li class="mb-2" style="list-style: decimal" @if($step['key'] === $academicGuide['current']['key']) aria-current="step" @endif>
                <a href="{{ route($step['route'], $step['parameters']) }}">{{ $step['label'] }}</a>
                <span class="d-block small text-muted">{{ $step['purpose'] }}</span>
            </li>
            @endforeach
        </ol>
    </details>
</aside>
@endif
