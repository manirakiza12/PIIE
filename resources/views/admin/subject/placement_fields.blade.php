@if($unitSetup ?? null)
@php
    $setupPlans = $unitSetup['plans']->mapWithKeys(function ($plan) use ($unitSetup) {
        $current = $plan->memberships->firstWhere('subject_id', $unitSetup['subjectId']);
        return [(string)$plan->id => [
            'academicYear' => $plan->effectiveAcademicYear?->label,
            'stages' => $plan->stages->map(fn ($stage) => ['id' => $stage->id, 'label' => $stage->label])->values(),
            'choices' => $plan->memberships->where('subject_id', '!=', $unitSetup['subjectId'])->map(fn ($member) => ['id' => $member->id, 'label' => trim(($member->subject->code ?? '').' — '.($member->subject->name ?? ''))])->values(),
            'current' => $current ? $current->only(['curriculum_stage_id','classification','credits','period_type','period_sequence','sequence']) + ['prerequisites' => $current->prerequisites->pluck('id')->all()] : null,
        ]];
    });
    $existingPlan = $unitSetup['plans']->first(fn ($plan) => $unitSetup['subjectId'] && $plan->memberships->contains('subject_id', $unitSetup['subjectId']));
    $initialPlan = old('placement_curriculum_id', request()->query('curriculum_id', $existingPlan?->id ?? ''));
    $recovered = session()->hasOldInput('placement_curriculum_id') ? [
        'curriculum_stage_id' => old('placement_stage_id'), 'classification' => old('placement_classification'),
        'credits' => old('placement_credits'), 'period_type' => old('placement_period_type'),
        'period_sequence' => old('placement_period_sequence'), 'sequence' => old('placement_sequence'),
        'prerequisites' => old('placement_prerequisites', []),
    ] : null;
@endphp
<section class="border rounded p-3 my-3" data-unit-placement>
    <h5>{{ get_phrase('Curriculum placement') }}</h5>
    <p class="text-muted">{{ get_phrase('Select an existing draft Curriculum to save its credits, Programme Year and prerequisites together with this Course Unit. Other placements remain unchanged.') }}</p>
    <div class="mb-3"><label class="form-label">{{ get_phrase('Programme Curriculum') }}</label>
        <select name="placement_curriculum_id" class="form-select" data-plan>
            <option value="">{{ get_phrase('Catalogue only — leave placements unchanged') }}</option>
            @foreach($unitSetup['plans'] as $plan)<option value="{{ $plan->id }}" @selected((string)$initialPlan === (string)$plan->id)>{{ $plan->programme->name }} — {{ $plan->version }}</option>@endforeach
        </select>
    </div>
    @if($unitSetup['plans']->isEmpty())<p class="text-muted">{{ get_phrase('Create a draft Programme Curriculum and its Programme Years in their modules before assigning a placement. You can save the Course Unit catalogue record now.') }}</p>@endif
    <div class="mb-3">
        <label class="form-label" for="unit-curriculum-academic-year">Curriculum applies from Academic Year</label>
        <input id="unit-curriculum-academic-year" class="form-control" type="text" readonly data-curriculum-year value="Choose a Programme Curriculum to see its Academic Year">
        <p class="form-text">This is the first applicable Academic Year, not an expiry date. The curriculum remains reusable in later years. Set the actual teaching year and semester in the Course Allocation module.</p>
    </div>
    <fieldset data-placement-fields disabled>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">{{ get_phrase('Programme Year') }}</label><select class="form-select" name="placement_stage_id" required data-stage></select></div>
            <div class="col-md-6"><label class="form-label">{{ get_phrase('Classification') }}</label><select class="form-select" name="placement_classification"><option value="compulsory">{{ get_phrase('Compulsory') }}</option><option value="elective">{{ get_phrase('Elective') }}</option></select></div>
            <div class="col-md-6"><label class="form-label">{{ get_phrase('Credit Units') }}</label><input type="number" class="form-control" name="placement_credits" min="0" max="9999.99" step="0.01" required></div>
            <div class="col-md-6"><label class="form-label">{{ get_phrase('Order') }}</label><input type="number" class="form-control" name="placement_sequence" min="0" max="65535" required></div>
            <div class="col-md-6"><label class="form-label">{{ get_phrase('Semester / Term') }}</label><select class="form-select" name="placement_period_type"><option value="">{{ get_phrase('Not assigned') }}</option><option value="semester">{{ get_phrase('Semester') }}</option><option value="term">{{ get_phrase('Term') }}</option></select></div>
            <div class="col-md-6"><label class="form-label">{{ get_phrase('Semester / Term Number') }}</label><input type="number" class="form-control" name="placement_period_sequence" min="1" max="65535"></div>
            <div class="col-12"><label class="form-label">{{ get_phrase('Prerequisite Course Units') }}</label><input type="hidden" name="placement_prerequisites_present" value="1"><select multiple class="form-select" name="placement_prerequisites[]" data-prerequisites size="5"></select><p class="form-text">{{ get_phrase('Choose existing Course Units in the selected Curriculum. Leave empty when there are no prerequisites.') }}</p></div>
        </div>
    </fieldset>
</section>
<script>
(() => {
    const root = document.querySelector('[data-unit-placement]:not([data-unit-initialized])');
    if (!root) return;
    root.dataset.unitInitialized = 'true';
    const plans = @json($setupPlans);
    const recovered = @json($recovered);
    const plan = root.querySelector('[data-plan]');
    const fields = root.querySelector('[data-placement-fields]');
    const stage = root.querySelector('[data-stage]');
    const prerequisites = root.querySelector('[data-prerequisites]');
    const render = (restore) => {
        const selected = plans[plan.value];
        fields.disabled = !selected;
        stage.replaceChildren(); prerequisites.replaceChildren();
        root.querySelector('[data-curriculum-year]').value = !selected ? 'Choose a Programme Curriculum to see its Academic Year' : (selected.academicYear || 'Not selected yet ? set it in the draft Programme Curriculum');
        if (!selected) return;
        const current = (restore && recovered) || selected.current || {classification:'compulsory',credits:'0.00',sequence:0,prerequisites:[]};
        selected.stages.forEach(item => stage.add(new Option(item.label, item.id, false, String(item.id) === String(current.curriculum_stage_id))));
        selected.choices.forEach(item => prerequisites.add(new Option(item.label, item.id, false, (current.prerequisites || []).map(String).includes(String(item.id)))));
        ['classification','credits','sequence','period_type','period_sequence'].forEach(key => { root.querySelector(`[name="placement_${key}"]`).value = current[key] ?? ''; });
    };
    plan.addEventListener('change', () => render(false));
    render(true);
})();
</script>
@endif
