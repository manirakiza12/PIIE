<?php
namespace App\Http\Controllers;

use App\Models\{Book, BookIssue, LibraryCopy, LibraryReservation, User};
use App\Support\Library\LibraryService;
use App\Support\Permissions\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LibraryController extends Controller
{
    protected ?User $tenantActor = null;
    public function __construct(protected LibraryService $library) {}

    protected function access(string $permission = 'library.view', bool $student = false): User
    {
        $user = auth()->user();
        if ($user && (int) $user->role_id === 1 && request()->is('superadmin/library/*')) {
            $school=(int) request()->attributes->get('library_school');
            abort_unless(DB::table('schools')->where('id',$school)->exists(),404);
            $user=clone $user; $user->school_id=$school;
            \Illuminate\Support\Facades\URL::defaults(['school'=>$school]);
        }
        abort_unless($user && (int) $user->school_id > 0 && app(PermissionService::class)->isActive($user), 403);
        if ($this->reader()) {
            abort_unless($student && in_array((int)$user->role_id,[3,7],true), 403);
        } else {
            abort_unless(app(PermissionService::class)->allows($user, $permission), 403);
            if ($user->menu_permission && $user->menu_permission !== 'null') {
                $menu = json_decode($user->menu_permission, true);
                abort_unless(is_array($menu) && (in_array('admin.book.book_list', $menu, true) || in_array('admin.book_issue.list', $menu, true)), 403);
            }
            if ($user->menu_permission === 'null') abort(403);
        }
        if (!$this->library->policy($user->school_id)->module_enabled && $permission!=='library.configure') abort(403,'The library module is disabled for this institution.');
        $this->tenantActor=$user;
        return $user;
    }

    protected function reader(): bool { return request()->is('student/*','teacher/*'); }

    protected function prefix(): string
    {
        foreach (['student','teacher','librarian','superadmin'] as $prefix) if(request()->is($prefix.'/*')) return $prefix;
        return 'admin';
    }

    protected function view(string $page, array $data = [])
    {
        $user = $this->tenantActor ?? auth()->user(); $prefix = $this->prefix(); $student = $this->reader();
        return view('library.'.$page, array_merge([
            'prefix' => $prefix, 'layout' => $prefix.'.navigation', 'student' => $student,
            'canManage' => !$student && app(PermissionService::class)->allows($user, 'library.manage_books'),
            'canIssue' => !$student && app(PermissionService::class)->allows($user, 'library.issue'),
            'canConfigure' => !$student && app(PermissionService::class)->allows($user,'library.configure'),
            'canArchive' => !$student && app(PermissionService::class)->allows($user,'library.archive'),
            'canDigital' => !$student && app(PermissionService::class)->allows($user,'library.digital'),
            'canReports' => !$student && app(PermissionService::class)->allows($user,'library.reports'),
            'canNotices' => !$student && app(PermissionService::class)->allows($user,'library.notices'),
            'canFines' => !$student && app(PermissionService::class)->allows($user,'library.fines'),
            'canOverride' => !$student && app(PermissionService::class)->allows($user,'library.override'),
            'policy' => $this->library->policy($user->school_id), 'today' => $this->library->today($user), 'page' => $page,
        ], $data));
    }

    protected function redirect(string $page, string $message, array $parameters = [])
    {
        return redirect()->route($this->prefix().'.library.'.$page, $parameters)->with('message', $message);
    }

    protected function titles(User $user)
    {
        return Book::where('school_id', $user->school_id)->withCount([
            'loans as active_loans_count' => fn ($q) => $q->where('school_id', $user->school_id)->where('status', 0),
            'holdings as unavailable_count' => fn ($q) => $q->where('status', '!=', 'circulating'),
        ]);
    }

    protected function relations(User $user): array
    {
        return ['book' => fn ($q) => $q->where('school_id', $user->school_id),
            'copy' => fn ($q) => $q->where('school_id', $user->school_id),
            'borrower' => fn ($q) => $q->where('school_id', $user->school_id)];
    }

    public function dashboard()
    {
        $user = $this->access('library.view', true); $today = $this->library->today($user);
        $books = $this->titles($user)->where('is_active', true);
        $loans = BookIssue::where('school_id', $user->school_id)->where('status', 0);
        if ($this->reader()) $loans->where('student_id', $user->id);
        $stats = ['titles' => (clone $books)->count(), 'copies' => (clone $books)->sum('copies'), 'loans' => (clone $loans)->count(),
            'overdue' => (clone $loans)->whereDate('due_date', '<', $today)->count(),
            'legacy' => (clone $loans)->whereNull('due_date')->count()];
        $recent = (clone $loans)->with($this->relations($user))->orderByRaw('due_date IS NULL')->orderBy('due_date')->limit(8)->get();
        return $this->view('dashboard', compact('stats', 'recent'));
    }

    public function catalogue(Request $request)
    {
        $user = $this->access('library.view', true);
        $request->validate(['search' => 'nullable|string|max:200', 'category' => 'nullable|string|max:100', 'state' => 'nullable|in:active,archived,all', 'stock' => 'nullable|in:available,unavailable',
            'resource_type'=>'nullable|in:book,ebook,journal,past_paper,reading,other']);
        $books = $this->titles($user);
        if ($this->reader() || $request->input('state', 'active') === 'active') $books->where('is_active', true);
        elseif ($request->input('state') === 'archived') $books->where('is_active', false);
        if ($search = trim((string) $request->search)) $books->where(function ($q) use ($search) {
            foreach (['name', 'author', 'isbn', 'category', 'publisher', 'shelf_location'] as $field) $q->orWhere($field, 'like', '%'.$search.'%');
            $q->orWhereHas('holdings', fn ($h) => $h->where('accession', 'like', '%'.$search.'%'));
        });
        if ($request->filled('category')) $books->where('category', $request->category);
        if ($request->filled('resource_type')) $books->where('resource_type',$request->resource_type);
        if ($request->filled('stock')) {
            $sql = 'books.copies - (select count(*) from book_issues where book_issues.book_id = books.id and book_issues.school_id = books.school_id and status = 0) - (select count(*) from library_copies where library_copies.book_id = books.id and status <> ?)';
            $books->whereRaw('('.$sql.') '.($request->stock === 'available' ? '> 0' : '<= 0'), ['circulating']);
        }
        $books = $books->orderBy('name')->paginate(15)->withQueryString();
        $categories = Book::where('school_id', $user->school_id)->where('is_active', true)->whereNotNull('category')->distinct()->orderBy('category')->pluck('category');
        return $this->view('catalogue', compact('books', 'categories'));
    }

    public function bookForm(?int $id = null)
    {
        $user = $this->access('library.manage_books');
        $book = $id ? Book::where('school_id', $user->school_id)->findOrFail($id) : new Book(['copies' => 1, 'language' => 'English', 'is_active' => true]);
        return $this->view('book_form', compact('book'));
    }

    public function saveBook(Request $request, ?int $id = null)
    {
        $user = $this->access('library.manage_books');
        $data = $request->validate([
            'name' => 'required|string|max:255', 'author' => 'required|string|max:255', 'copies' => 'required|integer|min:0|max:100000',
            'isbn' => ['nullable','string','max:32', Rule::unique('books')->where('school_id', $user->school_id)->ignore($id)],
            'category' => 'nullable|string|max:100', 'publisher' => 'nullable|string|max:255', 'edition' => 'nullable|string|max:80',
            'publication_year' => 'nullable|integer|min:1000|max:'.(now()->year + 1), 'language' => 'required|string|max:80',
            'shelf_location' => 'nullable|string|max:100', 'description' => 'nullable|string|max:10000',
            'resource_url' => 'nullable|url:https|max:1000', 'is_active' => 'required|boolean',
            'resource_type'=>'nullable|in:book,ebook,journal,past_paper,reading,other', 'download_audience'=>'nullable|in:all,staff',
            'replacement_cost'=>'nullable|numeric|decimal:0,2|min:0|max:10000000',
        ]);
        if ($request->hasFile('digital_file') && !app(PermissionService::class)->allows($user,'library.digital')) abort(403);
        $digital=$request->hasFile('digital_file') ? app(\App\Support\Library\LibraryDigitalStorage::class)->store($request,$user) : [];
        $data=array_merge($data,$digital);
        try { $book = DB::transaction(function () use ($user, $id, $data) {
            $book = $id ? $this->library->book($user, $id) : new Book(['school_id' => $user->school_id, 'session_id' => 0, 'timestamp' => now()->timestamp]);
            if (isset($data['replacement_cost']) && (float)$data['replacement_cost'] !== (float)($book->replacement_cost ?? 0)
                && !app(PermissionService::class)->allows($user,'library.fines')) abort(403);
            if ($id && $book->digital_path && isset($data['download_audience']) && $data['download_audience']!==$book->download_audience
                && !app(PermissionService::class)->allows($user,'library.digital')) abort(403);
            if ($id && $data['copies'] < $book->copies) $this->library->fail('Use copy status to withdraw, mark lost or repair stock; accession history is retained.', 'copies');
            if (!$id && $data['copies'] > 1000) $this->library->fail('Add at most 1,000 copies in one operation.', 'copies');
            if ($id && (bool)$book->is_active !== (bool)$data['is_active'] && !app(PermissionService::class)->allows($user,'library.archive')) abort(403);
            if (!$data['is_active'] && $id && ($book->loans()->where('status', 0)->exists() || $this->library->queue($book, $this->library->today($user))->exists())) $this->library->fail('Close current loans and reservations before archiving this title.');
            $book->fill($data)->save();
            $this->library->initializeCopies($book);
            $this->library->event($user, $id ? 'catalogue_update' : 'catalogue_create', 'Saved title #'.$book->id.' with '.$book->copies.' accession records.', $book->id);
            return $book;
        }, 3); } catch (\Throwable $e) { if($digital) \Illuminate\Support\Facades\Storage::disk('local')->delete($digital['digital_path']); throw $e; }
        return $this->redirect('books.show', 'Catalogue and accession records saved.', ['id' => $book->id]);
    }

    public function bookDetails(int $id)
    {
        $user = $this->access('library.view', true);
        $book = $this->titles($user)->findOrFail($id);
        if ($this->reader()) abort_unless($book->is_active, 404);
        $copies = LibraryCopy::where('school_id', $user->school_id)->where('book_id', $id)->withCount(['loans as on_loan' => fn ($q) => $q->where('status', 0)])->orderBy('id')->paginate(20);
        $offerings=collect();
        if($this->prefix()==='teacher') $offerings=app(\App\Support\CourseOffering\LecturerCourseOfferingAccess::class)->offerings($user);
        return $this->view('book_details', compact('book', 'copies','offerings'));
    }

    public function updateCopy(Request $request, int $id)
    {
        $user = $this->access('library.manage_books');
        $data = $request->validate(['accession' => ['required','string','max:80',Rule::unique('library_copies')->where('school_id', $user->school_id)->ignore($id)],
            'status' => 'required|in:circulating,reference,damaged,lost,withdrawn', 'condition' => 'required|in:good,fair,poor,damaged', 'notes' => 'nullable|string|max:2000']);
        if ($data['condition'] === 'damaged' && $data['status'] === 'circulating') $this->library->fail('A damaged copy cannot circulate. Select damaged or withdrawn stock status.', 'status');
        $bookId = DB::transaction(function () use ($user, $id, $data) {
            $initial = LibraryCopy::where('school_id', $user->school_id)->findOrFail($id);
            $book = $this->library->book($user, $initial->book_id);
            $copy = LibraryCopy::where('school_id', $user->school_id)->lockForUpdate()->findOrFail($id);
            if ($copy->loans()->where('status', 0)->exists()) $this->library->fail('Return this copy before changing its inventory record.');
            $copy->update($data);
            $this->library->event($user, 'copy_update', 'Updated '.$copy->accession.'; '.$copy->status.', '.$copy->condition, $book->id);
            return $book->id;
        }, 3);
        return $this->redirect('books.show', 'Copy inventory updated.', ['id' => $bookId]);
    }

    public function loans(Request $request)
    {
        $user = $this->access('library.view', true);
        $request->validate(['state' => 'nullable|in:active,overdue,returned,lost,legacy,all', 'search' => 'nullable|string|max:200']);
        $loans = $this->loanQuery($user, $request);
        $loans = $loans->with($this->relations($user))->orderByDesc('id')->paginate(20)->withQueryString();
        return $this->view('loans', compact('loans'));
    }

    private function loanQuery(User $user, Request $request)
    {
        $q = BookIssue::where('school_id', $user->school_id);
        if ($this->reader()) $q->where('student_id', $user->id);
        switch ($request->input('state', 'active')) {
            case 'active': $q->where('status', 0); break;
            case 'overdue': $q->where('status', 0)->whereDate('due_date', '<', $this->library->today($user)); break;
            case 'returned': $q->where('status', 1); break;
            case 'lost': $q->where('status', 2); break;
            case 'legacy': $q->where('status', 0)->whereNull('due_date'); break;
        }
        if ($search = trim((string) $request->search)) $q->where(function ($q) use ($search, $user) {
            $q->whereHas('book', fn ($b) => $b->where('school_id', $user->school_id)->where('name', 'like', '%'.$search.'%'))
                ->orWhereHas('copy', fn ($c) => $c->where('school_id', $user->school_id)->where('accession', 'like', '%'.$search.'%'));
            if (!$this->reader()) $q->orWhereHas('borrower', fn ($b) => $b->where('school_id', $user->school_id)->where(fn ($n) => $n->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%')));
        });
        return $q;
    }

    public function checkoutForm(Request $request)
    {
        $user = $this->access('library.issue');
        $request->validate(['member_search' => 'nullable|string|max:200', 'book_search' => 'nullable|string|max:200', 'book_id' => 'nullable|integer']);
        $books = $this->titles($user)->where('is_active', true);
        if ($request->filled('book_id')) $books->whereKey($request->book_id);
        elseif ($request->filled('book_search')) $books->where(fn ($q) => $q->where('name','like','%'.$request->book_search.'%')->orWhere('isbn','like','%'.$request->book_search.'%')->orWhereHas('holdings', fn ($c) => $c->where('accession','like','%'.$request->book_search.'%')));
        $books = $books->orderBy('name')->limit(30)->get();
        $copies = LibraryCopy::where('school_id', $user->school_id)->whereIn('book_id', $books->pluck('id'))
            ->where('status', 'circulating')->whereDoesntHave('loans', fn ($q) => $q->where('status', 0))
            ->orderBy('accession')->limit(200)->get();
        $members = collect();
        if ($request->filled('member_search')) $members = User::where('school_id', $user->school_id)->whereNotIn('role_id', [1,6,8])->where('account_status', '!=', 'disable')
            ->where(fn ($q) => $q->where('name','like','%'.$request->member_search.'%')->orWhere('email','like','%'.$request->member_search.'%')->orWhere('code','like','%'.$request->member_search.'%'))
            ->orderBy('name')->limit(30)->get()->filter(fn ($m) => $this->library->eligible($m));
        return $this->view('checkout', compact('books', 'members', 'copies'));
    }

    public function checkout(Request $request)
    {
        $user = $this->access('library.issue');
        $data = $request->validate(['book_id' => 'required|integer', 'student_id' => 'required|integer', 'copy_id' => 'nullable|integer', 'notes' => 'nullable|string|max:2000','override_reason'=>'nullable|string|min:10|max:1000']);
        $this->library->checkout($user, $data);
        return $this->redirect('loans', 'Book issued. The accession and due date are recorded.');
    }

    public function returnForm(int $id)
    {
        $user = $this->access('library.issue');
        $loan = BookIssue::where('school_id', $user->school_id)->with($this->relations($user))->findOrFail($id);
        return $this->view('return', compact('loan'));
    }

    public function returnLoan(Request $request, int $id)
    {
        $user = $this->access('library.issue');
        $data = $request->validate(['condition' => 'required|in:good,fair,damaged,lost']);
        $this->library->returnLoan($user, $id, $data['condition']);
        return $this->redirect('loans', 'Loan closed and stock availability updated.');
    }

    public function renew(int $id)
    {
        $user = $this->access('library.issue', true);
        $this->library->renew($user, $id);
        return $this->redirect('loans', 'Loan renewed.');
    }

    public function reservations()
    {
        $user = $this->access('library.view', true);
        $holds = LibraryReservation::where('school_id', $user->school_id)->with([
            'book' => fn ($q) => $q->where('school_id', $user->school_id),
            'borrower' => fn ($q) => $q->where('school_id', $user->school_id)]);
        if ($this->reader()) $holds->where('user_id', $user->id);
        $holds = $holds->orderByDesc('id')->paginate(20);
        return $this->view('reservations', compact('holds'));
    }

    public function reserve(Request $request, int $id)
    {
        $user = $this->access('library.issue', true);
        $memberId = $this->reader() ? $user->id : $request->validate(['student_id' => 'required|integer'])['student_id'];
        $this->library->reserve($user, $id, (int) $memberId);
        return $this->redirect('reservations', 'Reservation recorded. Borrowers are served in reservation order.');
    }

    public function reserveSelected(Request $request)
    {
        $request->validate(['book_id' => 'required|integer']);
        return $this->reserve($request, (int) $request->book_id);
    }

    public function cancel(int $id)
    {
        $user = $this->access('library.issue', true);
        DB::transaction(function () use ($user, $id) {
            $initial = LibraryReservation::where('school_id', $user->school_id)->findOrFail($id);
            $this->library->book($user, $initial->book_id);
            $hold = LibraryReservation::where('school_id', $user->school_id)->lockForUpdate()->findOrFail($id);
            if ($this->reader()) abort_unless((int) $hold->user_id === (int) $user->id, 404);
            if ($hold->status !== 'waiting') $this->library->fail('This reservation is already closed.');
            $hold->update(['status' => 'cancelled']);
            $this->library->event($user, 'cancel_reservation', 'Cancelled reservation #'.$hold->id, $hold->book_id);
        }, 3);
        return $this->redirect('reservations', 'Reservation cancelled.');
    }

    public function reports()
    {
        $user = $this->access('library.reports');
        $today = $this->library->today($user);
        $inventory = LibraryCopy::where('school_id', $user->school_id)->select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->pluck('total','status');
        $popular = $this->titles($user)->withCount(['loans' => fn ($q) => $q->where('school_id', $user->school_id)])->orderByDesc('loans_count')->limit(10)->get();
        $events = DB::table('library_events')->where('school_id', $user->school_id)->orderByDesc('id')->paginate(20);
        $overdue = BookIssue::where('school_id', $user->school_id)->where('status', 0)->whereDate('due_date','<',$today)->count();
        $inactive=Book::where('school_id',$user->school_id)->where('is_active',false)->count();
        $popularSubjects=DB::table('book_issues')->join('books','books.id','=','book_issues.book_id')
            ->where('book_issues.school_id',$user->school_id)->where('books.school_id',$user->school_id)
            ->select('books.category',DB::raw('COUNT(*) as total'))->groupBy('books.category')->orderByDesc('total')->limit(10)->get();
        return $this->view('reports', compact('inventory','popular','events','overdue','inactive','popularSubjects'));
    }

    public function export(Request $request)
    {
        $user = $this->access('library.reports');
        $request->validate(['type' => 'required|in:catalogue,loans,overdue', 'state' => 'nullable|in:active,overdue,returned,lost,legacy,all']);
        $type = $request->type;
        if ($type === 'overdue') $request->merge(['state' => 'overdue']);
        return response()->streamDownload(function () use ($user, $request, $type) {
            $out = fopen('php://output','w');
            $write = function (array $row) use ($out) {
                $row = array_map(fn ($v) => is_string($v) && preg_match('/^[\s]*[=+@\-]/u', $v) ? "'".$v : $v, $row);
                fputcsv($out, $row, ',', '"', '');
            };
            if ($type === 'catalogue') {
                $write(['Title','Author','ISBN','Category','Shelf','Total copies','Available','Status']);
                foreach ($this->titles($user)->orderBy('id')->cursor() as $b) $write([$b->name,$b->author,$b->isbn,$b->category,$b->shelf_location,$b->copies,$this->library->available($b),$b->is_active ? 'Active' : 'Archived']);
            } else {
                $write(['Loan','Title','Accession','Borrower','Member code','Issued','Due','Returned','Status','Renewals']);
                $this->loanQuery($user,$request)->with($this->relations($user))->orderBy('id')->chunkById(200, function ($loans) use ($write) {
                    foreach ($loans as $l) $write([$l->id,$l->book?->name,$l->copy?->accession,$l->borrower?->name,$l->borrower?->code,
                        is_numeric($l->issue_date) ? date('Y-m-d',(int) $l->issue_date) : $l->issue_date,$l->due_date?->toDateString(),$l->returned_at?->toDateString(),match ((int) $l->status) { 0 => 'On loan', 2 => 'Lost', default => 'Returned' },$l->renewal_count]);
                });
            }
            fclose($out);
        }, 'library-'.$type.'-'.$this->library->today($user).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function settings()
    {
        $this->access('library.configure');
        return $this->view('settings');
    }

    public function saveSettings(Request $request)
    {
        $user = $this->access('library.configure');
        $data = $request->validate(['loan_days' => 'required|integer|min:1|max:365', 'max_loans' => 'required|integer|min:1|max:50',
            'max_renewals' => 'required|integer|min:0|max:10', 'reservation_days' => 'required|integer|min:1|max:90', 'block_overdue' => 'required|boolean',
            'library_name'=>'sometimes|required|string|max:255','opening_hours'=>'nullable|string|max:500','module_enabled'=>'sometimes|required|boolean',
            'student_downloads'=>'sometimes|required|boolean','staff_downloads'=>'sometimes|required|boolean','allow_epub'=>'sometimes|required|boolean',
            'fine_per_day'=>'sometimes|required|numeric|decimal:0,2|min:0|max:1000000','fine_cap'=>'sometimes|required|numeric|decimal:0,2|min:0|max:10000000',
            'fine_grace_days'=>'sometimes|required|integer|min:0|max:365','fine_currency'=>'sometimes|required|regex:/^[A-Z]{3}$/']);
        DB::transaction(function () use ($user,$data) {
            DB::table('library_policies')->updateOrInsert(['school_id' => $user->school_id], array_merge($data,['updated_at' => now(),'created_at' => now()]));
            $this->library->event($user,'policy_update','Updated borrowing rules: '.json_encode($data));
        });
        return $this->redirect('settings','Borrowing rules saved. Existing due dates remain as recorded.');
    }

    // Old mutation URLs remain review pages. Every state change now requires POST.
    public function legacyBookDelete(int $id) { return $this->bookForm($id); }
    public function legacyLoanEdit(int $id) { return $this->returnForm($id); }
    public function legacyLoanUpdate() { abort(405, 'Use the library return or renewal form. Loan history cannot be overwritten.'); }
}
