@if($question->resources->isNotEmpty())
    <ul class="list-unstyled small mt-2" aria-label="Question resources">
        @foreach($question->resources as $material)
            <li class="mb-1">
                @if($material->isFile())
                    <a href="{{ route($teacherView ? 'teacher.course_offerings.assignments.resources.file' : 'student.courses.assignments.resources.file', $material->id) }}">{{ $material->title }}</a>
                @elseif($material->hasUsableLink())
                    <a href="{{ $material->link_url }}" target="_blank" rel="noopener noreferrer">{{ $material->title }}</a>
                @endif
                @if($teacherView && ! $frozen)
                    <form method="POST" action="{{ route('teacher.course_offerings.assignments.resources.destroy', $material->id) }}" class="d-inline">
                        @csrf
                        <button class="btn btn-sm btn-link text-danger" type="submit">Remove resource</button>
                    </form>
                @endif
            </li>
        @endforeach
    </ul>
@endif
