<?php
namespace App\Support\Library;

use App\Models\{Book, BookIssue, LibraryCopy, LibraryReservation, User};
use App\Support\TenantTimezone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LibraryService
{
    public function today(User $actor): string
    {
        return now(app(TenantTimezone::class)->resolve($actor))->toDateString();
    }

    public function policy(int $school): object
    {
        return DB::table('library_policies')->where('school_id', $school)->first() ?? (object) [
            'loan_days' => 14, 'max_loans' => 3, 'max_renewals' => 1, 'reservation_days' => 7, 'block_overdue' => true,
        ];
    }

    public function fail(string $message, string $field = 'library'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public function event(User $actor, string $action, string $description, ?int $book = null, ?int $loan = null): void
    {
        DB::table('library_events')->insert(['school_id' => $actor->school_id, 'actor_id' => $actor->id,
            'action' => $action, 'description' => $description, 'book_id' => $book, 'loan_id' => $loan, 'created_at' => now()]);
    }

    public function book(User $actor, int $id): Book
    {
        return Book::where('school_id', $actor->school_id)->lockForUpdate()->findOrFail($id);
    }

    public function member(User $actor, int $id): User
    {
        $user = User::where('school_id', $actor->school_id)->lockForUpdate()->findOrFail($id);
        if (!$this->eligible($user)) $this->fail('Select an active student or staff member of this institution.', 'student_id');
        return $user;
    }

    public function eligible(User $user): bool
    {
        return (int) $user->role_id > 0 && !in_array((int) $user->role_id, [1, 6, 8], true)
            && $user->account_status !== 'disable' && !$user->isStaffPortalBlocked();
    }

    // Called under the title lock. Existing aggregate stock gains accession records
    // only when managed; historical dates/statuses are not invented or rewritten.
    public function initializeCopies(Book $book): void
    {
        $count = $book->holdings()->count();
        $missing = max(0, (int) $book->copies - $count);
        if ($missing > 1000) $this->fail('This title needs inventory review before generating more than 1,000 accession records.');
        for ($i = 1; $i <= $missing; $i++) {
            LibraryCopy::create(['school_id' => $book->school_id, 'book_id' => $book->id,
                'accession' => 'LIB-'.$book->id.'-'.str_pad((string) ($count + $i), 4, '0', STR_PAD_LEFT)]);
        }
        $legacy = $book->loans()->where('school_id', $book->school_id)->where('status', 0)->whereNull('copy_id')->orderBy('id')->get();
        $free = $book->holdings()->where('status', 'circulating')->whereDoesntHave('loans', fn ($q) => $q->where('status', 0))->orderBy('id')->get();
        foreach ($legacy as $loan) {
            if (!$copy = $free->shift()) break;
            $loan->update(['copy_id' => $copy->id]);
        }
    }

    public function available(Book $book): int
    {
        $active = $book->active_loans_count ?? $book->loans()->where('school_id', $book->school_id)->where('status', 0)->count();
        $unavailable = $book->unavailable_count ?? $book->holdings()->where('status', '!=', 'circulating')->count();
        return max(0, (int) $book->copies - (int) $active - (int) $unavailable);
    }

    public function queue(Book $book, string $today)
    {
        return LibraryReservation::where('school_id', $book->school_id)->where('book_id', $book->id)
            ->where('status', 'waiting')->where('expires_on', '>=', $today)->orderBy('id');
    }

    public function checkout(User $actor, array $data): BookIssue
    {
        return DB::transaction(function () use ($actor, $data) {
            $book = $this->book($actor, (int) $data['book_id']);
            $member = $this->member($actor, (int) $data['student_id']);
            $policy = $this->policy($actor->school_id);
            $today = $this->today($actor);
            if (!$book->is_active) $this->fail('This title is archived.');
            $this->initializeCopies($book);
            $loans = BookIssue::where('school_id', $actor->school_id)->where('student_id', $member->id)->where('status', 0);
            if ((clone $loans)->count() >= $policy->max_loans) $this->fail('This borrower has reached the loan limit.');
            if ($policy->block_overdue && (clone $loans)->whereDate('due_date', '<', $today)->exists()) $this->fail('Return overdue books before borrowing another title.');
            if ((clone $loans)->where('book_id', $book->id)->exists()) $this->fail('This borrower already has this title on loan.');
            if ($this->available($book) < 1) $this->fail('No circulating copy is available.');
            $hold = $this->queue($book, $today)->first();
            if ($hold && (int) $hold->user_id !== (int) $member->id) $this->fail('The next reservation belongs to another borrower. Serve the reservation queue first.');
            $copies = $book->holdings()->where('school_id', $actor->school_id)->where('status', 'circulating')
                ->whereDoesntHave('loans', fn ($q) => $q->where('status', 0));
            $copy = !empty($data['copy_id']) ? $copies->find($data['copy_id']) : $copies->orderBy('id')->first();
            if (!$copy) $this->fail('That copy is unavailable or belongs to another title.', 'copy_id');
            $due = \Illuminate\Support\Carbon::parse($today)->addDays($policy->loan_days)->toDateString();
            $loan = BookIssue::create(['school_id' => $actor->school_id, 'book_id' => $book->id, 'copy_id' => $copy->id,
                'student_id' => $member->id, 'class_id' => 0, 'session_id' => 0, 'issue_date' => now()->timestamp,
                'timestamp' => now()->timestamp, 'status' => 0, 'due_date' => $due, 'issued_by' => $actor->id, 'notes' => $data['notes'] ?? null]);
            if ($hold) $hold->update(['status' => 'fulfilled']);
            $this->event($actor, 'checkout', 'Issued '.$copy->accession.' to member #'.$member->id.'; due '.$due, $book->id, $loan->id);
            return $loan;
        }, 3);
    }

    public function returnLoan(User $actor, int $id, string $condition): void
    {
        DB::transaction(function () use ($actor, $id, $condition) {
            $initial = BookIssue::where('school_id', $actor->school_id)->findOrFail($id);
            $book = $this->book($actor, $initial->book_id);
            $this->initializeCopies($book);
            $loan = BookIssue::where('school_id', $actor->school_id)->lockForUpdate()->findOrFail($id);
            if ((int) $loan->status !== 0) $this->fail('This loan is already closed.');
            $loan->update(['status' => $condition === 'lost' ? 2 : 1, 'returned_at' => now(), 'returned_by' => $actor->id, 'return_condition' => $condition]);
            if ($loan->copy_id) LibraryCopy::where('school_id', $actor->school_id)->where('book_id', $book->id)->whereKey($loan->copy_id)
                ->update(['condition' => $condition === 'lost' ? 'poor' : $condition,
                    'status' => match ($condition) { 'lost' => 'lost', 'damaged' => 'damaged', default => 'circulating' }, 'updated_at' => now()]);
            // Unassigned legacy loans still reduce aggregate availability while open.
            // A lost/damaged unassigned legacy copy must remain unavailable after closure.
            elseif (in_array($condition, ['lost', 'damaged'], true)) $this->fail('Reconcile this title’s accession inventory before closing an unassigned loan as lost or damaged.');
            $this->event($actor, 'return', 'Closed loan #'.$loan->id.'; condition '.$condition, $book->id, $loan->id);
        }, 3);
    }

    public function renew(User $actor, int $id): void
    {
        DB::transaction(function () use ($actor, $id) {
            $initial = BookIssue::where('school_id', $actor->school_id)->findOrFail($id);
            $book = $this->book($actor, $initial->book_id);
            $loan = BookIssue::where('school_id', $actor->school_id)->lockForUpdate()->findOrFail($id);
            if ((int) $actor->role_id === 7 && (int) $loan->student_id !== (int) $actor->id) abort(404);
            $policy = $this->policy($actor->school_id); $today = $this->today($actor);
            if (!$book->is_active || (int) $loan->status !== 0 || !$loan->due_date) $this->fail('Only current loans with a due date can be renewed. Ask the librarian to review legacy loans.');
            if ($loan->due_date->toDateString() < $today) $this->fail('Overdue books must be returned before renewal.');
            if ($loan->renewal_count >= $policy->max_renewals) $this->fail('The renewal limit has been reached.');
            if ($this->queue($book, $today)->exists()) $this->fail('Another reservation is waiting; return this book instead of renewing.');
            $loan->update(['due_date' => $loan->due_date->addDays($policy->loan_days)->toDateString(), 'renewal_count' => $loan->renewal_count + 1]);
            $this->event($actor, 'renew', 'Renewed loan #'.$loan->id.' until '.$loan->due_date->toDateString(), $book->id, $loan->id);
        }, 3);
    }

    public function reserve(User $actor, int $bookId, int $memberId): void
    {
        DB::transaction(function () use ($actor, $bookId, $memberId) {
            $book = $this->book($actor, $bookId);
            $member = $this->member($actor, $memberId);
            if (!$book->is_active) $this->fail('Archived titles cannot be reserved.');
            if (BookIssue::where('school_id', $actor->school_id)->where('student_id', $member->id)->where('book_id', $book->id)->where('status', 0)->exists()) $this->fail('This borrower already has this title.');
            $today = $this->today($actor); $policy = $this->policy($actor->school_id);
            $holds = LibraryReservation::where('school_id', $actor->school_id)->where('user_id', $member->id)->where('status', 'waiting')->where('expires_on', '>=', $today);
            if ((clone $holds)->where('book_id', $book->id)->exists()) $this->fail('There is already a reservation for this title.');
            if ((clone $holds)->count() >= $policy->max_loans) $this->fail('The reservation limit has been reached.');
            LibraryReservation::create(['school_id' => $actor->school_id, 'book_id' => $book->id, 'user_id' => $member->id,
                'expires_on' => \Illuminate\Support\Carbon::parse($today)->addDays($policy->reservation_days)->toDateString()]);
            $this->event($actor, 'reserve', 'Reserved title for member #'.$member->id, $book->id);
        }, 3);
    }
}
