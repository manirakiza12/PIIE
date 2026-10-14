@extends('superadmin.navigation')
@section('content')
<div class="eSection-wrap"><h4>Library administration</h4><p>Select the institution whose library you want to supervise. All resources and circulation records remain owned by that institution.</p><div class="table-responsive"><table class="table eTable"><thead><tr><th>Institution</th><th>Library workspace</th><th>Configuration</th></tr></thead><tbody>@foreach($schools as $school)<tr><td>{{ $school->title }}</td><td><a href="{{ route('superadmin.library.dashboard',['school'=>$school->id]) }}">Open library</a></td><td><a href="{{ route('superadmin.library.settings',['school'=>$school->id]) }}">Enable / configure</a></td></tr>@endforeach</tbody></table></div>{{ $schools->links() }}</div>
@endsection
