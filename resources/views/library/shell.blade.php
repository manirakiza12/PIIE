@extends($layout)
@section('content')
<link rel="stylesheet" href="{{ asset('assets/css/library.css') }}">
<div class="library-workspace">
    <header class="library-header">
        <div><span class="library-eyebrow">Learning resources</span><h1>Library</h1><p>{{ $student ? 'Discover your next read, manage your loans and reserve a title.' : 'A clear view of your collection, borrowers and circulation.' }}</p></div>
        <div class="library-actions">
            @if($canIssue)<a class="btn btn-primary" href="{{ route($prefix.'.library.checkout') }}"><i class="bi bi-arrow-up-right-circle" aria-hidden="true"></i> Issue a book</a>@endif
            @if($canManage)<a class="btn btn-outline-primary" href="{{ route($prefix.'.library.books.create') }}"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add title</a>@endif
        </div>
    </header>
    <nav class="library-tabs" aria-label="Library navigation">
        @foreach(['dashboard'=>'Overview','catalogue'=>'Catalogue','loans'=>$student?'My loans':'Loans & returns','reservations'=>$student?'My reservations':'Reservations'] as $tab=>$label)
        <a href="{{ route($prefix.'.library.'.$tab) }}" @if($page === $tab) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
        @if(!$student)<a href="{{ route($prefix.'.library.reports') }}" @if($page === 'reports') aria-current="page" @endif>Reports</a>@endif
        @if($canManage)<a href="{{ route($prefix.'.library.settings') }}" @if($page === 'settings') aria-current="page" @endif>Borrowing rules</a>@endif
    </nav>
    @if(session('message'))<div class="alert alert-success" role="status">{{ session('message') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger" role="alert"><strong>Please review the following:</strong><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @yield('library_content')
</div>
@endsection
