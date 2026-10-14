<?php
namespace Tests\Feature;

use App\Models\{Book, BookIssue, LibraryCopy, LibraryReservation, User};
use App\Support\Library\LibraryService;
use Illuminate\Support\Facades\{DB, Http};
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class LibraryManagementTest extends TestCase
{
    use AdmissionsTestHelper;
    protected User $admin;
    protected User $student;
    protected User $second;
    protected Book $book;
    protected LibraryService $library;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        (require database_path('migrations/2026_10_14_160000_upgrade_library_management.php'))->up();
        $school = $this->makeSchool(['title' => 'Training Library']);
        $this->admin = $this->makeAdminUser($school);
        $this->student = User::factory()->create(['school_id'=>$school,'role_id'=>7,'name'=>'Amina Reader','code'=>'PIIE-0001','account_status'=>'active']);
        $this->second = User::factory()->create(['school_id'=>$school,'role_id'=>7,'name'=>'Brian Reader','code'=>'PIIE-0002','account_status'=>'active']);
        $this->book = $this->title();
        $this->library = app(LibraryService::class);
        $this->actingAs($this->admin);
        Http::preventStrayRequests();
    }

    protected function title(array $changes = []): Book
    {
        return Book::create(array_replace(['school_id'=>$this->admin->school_id,'name'=>'Foundations of Computing','author'=>'Sample Author','copies'=>2,
            'session_id'=>1,'timestamp'=>now()->timestamp,'language'=>'English','is_active'=>true,'isbn'=>'9780000000001','category'=>'Computing','shelf_location'=>'C-01'],$changes));
    }

    protected function issue(?User $member = null, ?Book $book = null)
    {
        return $this->actingAs($this->admin)->post(route('admin.library.checkout.store'), ['book_id'=>($book ?? $this->book)->id,'student_id'=>($member ?? $this->student)->id]);
    }

    public function test_all_admin_workspace_pages_render_and_catalogue_searches_metadata(): void
    {
        foreach (['dashboard','catalogue','loans','reservations','checkout','reports','settings','books.create'] as $page) $this->get(route('admin.library.'.$page))->assertOk()->assertSee('assets/css/library.css',false);
        $this->get(route('admin.library.books.show',['id'=>$this->book->id]))->assertOk()->assertSee('Foundations of Computing');
        $this->get(route('admin.library.catalogue',['search'=>'9780000000001']))->assertOk()->assertSee('Foundations of Computing');
        $this->get(route('admin.library.catalogue',['category'=>'Medicine']))->assertOk()->assertDontSee('Foundations of Computing');
    }

    public function test_title_save_generates_unique_accessions_and_updates_same_title(): void
    {
        $payload=['name'=>'Clinical Nursing','author'=>'Nurse Author','copies'=>3,'language'=>'English','is_active'=>1,'isbn'=>'9780000000002'];
        $this->post(route('admin.library.books.store'),$payload)->assertSessionHasNoErrors();
        $book=Book::where('name','Clinical Nursing')->firstOrFail();
        $this->assertSame(3,$book->holdings()->count());
        $this->assertSame(3,$book->holdings()->distinct()->count('accession'));
        $this->post(route('admin.library.books.update',['id'=>$book->id]),$payload)->assertSessionHasNoErrors();
        $this->assertSame(3,$book->holdings()->count());
        $this->post(route('admin.library.books.update',['id'=>$book->id]),array_replace($payload,['copies'=>4]))->assertSessionHasNoErrors();
        $this->assertSame(4,$book->holdings()->count());
        $this->post(route('admin.library.books.update',['id'=>$book->id]),array_replace($payload,['copies'=>1]))->assertSessionHasErrors('copies');
        $this->assertSame(4,$book->fresh()->copies);
    }

    public function test_checkout_enforces_stock_and_duplicate_borrowing(): void
    {
        $this->issue()->assertSessionHasNoErrors();
        $loan=BookIssue::firstOrFail();
        $this->assertNotNull($loan->copy_id);
        $this->assertSame(now()->addDays(14)->toDateString(),$loan->due_date->toDateString());
        $this->issue()->assertSessionHasErrors('library');
        $this->issue($this->second)->assertSessionHasNoErrors();
        $third=User::factory()->create(['role_id'=>7,'school_id'=>$this->admin->school_id]);
        $this->issue($third)->assertSessionHasErrors('library');
        $this->assertSame(2,BookIssue::count());
        $this->assertSame(0,$this->library->available($this->book));
    }

    public function test_specific_copy_cannot_be_reissued_or_selected_from_another_title(): void
    {
        $this->issue()->assertSessionHasNoErrors(); $loan=BookIssue::first();
        $this->post(route('admin.library.checkout.store'),['book_id'=>$this->book->id,'student_id'=>$this->second->id,'copy_id'=>$loan->copy_id])->assertSessionHasErrors('copy_id');
        $other=$this->title(['isbn'=>'9780000000003']);
        $this->post(route('admin.library.checkout.store'),['book_id'=>$other->id,'student_id'=>$this->second->id,'copy_id'=>$loan->copy_id])->assertSessionHasErrors('copy_id');
        $this->assertSame(1,BookIssue::count());
    }

    public function test_return_is_audited_releases_stock_and_cannot_close_twice(): void
    {
        $this->issue()->assertSessionHasNoErrors(); $loan=BookIssue::first();
        $this->get(route('admin.library.loans.return',['id'=>$loan->id]))->assertOk()->assertSee('Return desk');
        $this->assertSame(0,$loan->fresh()->status);
        $url=route('admin.library.loans.return.store',['id'=>$loan->id]);
        $this->post($url,['condition'=>'good'])->assertSessionHasNoErrors();
        $this->assertSame(1,$loan->fresh()->status); $this->assertNotNull($loan->fresh()->returned_at);
        $this->assertSame($this->admin->id,$loan->fresh()->returned_by);
        $this->assertSame(2,$this->library->available($this->book));
        $this->post($url,['condition'=>'good'])->assertSessionHasErrors('library');
        $this->assertSame(1,DB::table('library_events')->where('action','return')->count());
    }

    public function test_damaged_and_lost_returns_are_not_available_until_inventory_review(): void
    {
        $this->issue()->assertSessionHasNoErrors(); $loan=BookIssue::first();
        $this->post(route('admin.library.loans.return.store',['id'=>$loan->id]),['condition'=>'damaged'])->assertSessionHasNoErrors();
        $this->assertSame('damaged',$loan->copy->fresh()->status); $this->assertSame(1,$this->library->available($this->book));
        $this->issue($this->second)->assertSessionHasNoErrors(); $lost=BookIssue::orderByDesc('id')->first();
        $this->post(route('admin.library.loans.return.store',['id'=>$lost->id]),['condition'=>'lost'])->assertSessionHasNoErrors();
        $this->assertSame(2,$lost->fresh()->status); $this->assertSame(0,$this->library->available($this->book));
        $copy=$loan->copy->fresh();
        $this->post(route('admin.library.copies.update',['id'=>$copy->id]),['accession'=>$copy->accession,'condition'=>'good','status'=>'circulating','notes'=>'Repaired'])->assertSessionHasNoErrors();
        $this->assertSame(1,$this->library->available($this->book));
    }

    public function test_renewal_enforces_limit_overdue_and_reservations(): void
    {
        $this->issue()->assertSessionHasNoErrors(); $loan=BookIssue::first();
        $url=route('admin.library.loans.renew',['id'=>$loan->id]);
        $this->post($url)->assertSessionHasNoErrors(); $this->assertSame(1,$loan->fresh()->renewal_count);
        $this->post($url)->assertSessionHasErrors('library');
        $loan->refresh()->update(['renewal_count'=>0,'due_date'=>now()->subDay()->toDateString()]);
        $this->post($url)->assertSessionHasErrors('library');
        $loan->update(['due_date'=>now()->addDay()->toDateString()]);
        $this->library->reserve($this->admin,$this->book->id,$this->second->id);
        $this->post($url)->assertSessionHasErrors('library');
        $this->assertSame(0,$loan->fresh()->renewal_count);
    }

    public function test_reservation_queue_duplicate_expiry_and_fulfilment(): void
    {
        $this->actingAs($this->student)->post(route('student.library.books.reserve',['id'=>$this->book->id]))->assertSessionHasNoErrors();
        $this->post(route('student.library.books.reserve',['id'=>$this->book->id]))->assertSessionHasErrors('library');
        $this->issue($this->second)->assertSessionHasErrors('library');
        $this->issue()->assertSessionHasNoErrors(); $this->assertSame('fulfilled',LibraryReservation::first()->status);
        $this->library->reserve($this->admin,$this->book->id,$this->second->id);
        LibraryReservation::where('status','waiting')->update(['expires_on'=>now()->subDay()->toDateString()]);
        $third=User::factory()->create(['role_id'=>7,'school_id'=>$this->admin->school_id]);
        $this->issue($third)->assertSessionHasNoErrors();
    }

    public function test_student_can_only_renew_and_cancel_their_own_records(): void
    {
        $this->issue($this->second)->assertSessionHasNoErrors(); $loan=BookIssue::first();
        $this->actingAs($this->student)->post(route('student.library.loans.renew',['id'=>$loan->id]))->assertNotFound();
        $this->library->reserve($this->admin,$this->book->id,$this->student->id);
        $hold=LibraryReservation::first();
        $this->actingAs($this->second)->post(route('student.library.reservations.cancel',['id'=>$hold->id]))->assertNotFound();
        $this->actingAs($this->student)->post(route('student.library.reservations.cancel',['id'=>$hold->id]))->assertSessionHasNoErrors();
        $this->assertSame('cancelled',$hold->fresh()->status);
        $this->get(route('student.library.loans'))->assertOk()->assertDontSee('Foundations of Computing');
        $this->post(route('admin.library.books.store'),[])->assertStatus(302);
    }

    public function test_overdue_and_loan_limit_policy_blocks_new_loans(): void
    {
        $this->issue()->assertSessionHasNoErrors();
        $other=$this->title(['isbn'=>'9780000000003']);
        BookIssue::first()->update(['due_date'=>now()->subDay()->toDateString()]);
        $this->issue(null,$other)->assertSessionHasErrors('library');
        $this->actingAs($this->admin)->post(route('admin.library.settings.update'),['loan_days'=>21,'max_loans'=>1,'max_renewals'=>0,'reservation_days'=>5,'block_overdue'=>0])->assertSessionHasNoErrors();
        $this->issue(null,$other)->assertSessionHasErrors('library');
        $this->assertSame(1,BookIssue::count());
        $this->assertSame(now()->subDay()->toDateString(),BookIssue::first()->due_date->toDateString());
    }

    public function test_cross_school_books_members_loans_and_copies_are_inaccessible(): void
    {
        $other=$this->makeSchool(); $foreign=$this->title(['school_id'=>$other,'isbn'=>'9780000000003']);
        $member=User::factory()->create(['school_id'=>$other,'role_id'=>7]);
        $this->get(route('admin.library.books.show',['id'=>$foreign->id]))->assertNotFound();
        $this->issue(null,$foreign)->assertNotFound();
        $this->issue($member)->assertNotFound();
        $this->library->initializeCopies($foreign); $copy=$foreign->holdings()->first();
        $this->post(route('admin.library.copies.update',['id'=>$copy->id]),['accession'=>'foreign','status'=>'circulating','condition'=>'good'])->assertNotFound();
        $this->get(route('admin.library.catalogue',['search'=>'9780000000003']))->assertOk()->assertDontSee('Foundations of Computing');
        $this->assertSame(0,BookIssue::count());
    }

    public function test_disabled_borrowers_and_reference_stock_are_rejected(): void
    {
        $this->student->update(['account_status'=>'disable']);
        $this->issue()->assertSessionHasErrors('student_id');
        $this->library->initializeCopies($this->book);
        $this->book->holdings()->update(['status'=>'reference']);
        $this->issue($this->second)->assertSessionHasErrors('library');
        $this->assertSame(0,BookIssue::count());
    }

    public function test_copy_on_loan_and_title_with_loans_cannot_be_archived(): void
    {
        $this->issue()->assertSessionHasNoErrors(); $copy=BookIssue::first()->copy;
        $this->post(route('admin.library.copies.update',['id'=>$copy->id]),['accession'=>$copy->accession,'status'=>'withdrawn','condition'=>'good'])->assertSessionHasErrors('library');
        $this->post(route('admin.library.books.update',['id'=>$this->book->id]),['name'=>$this->book->name,'author'=>$this->book->author,'copies'=>2,'language'=>'English','is_active'=>0])->assertSessionHasErrors('library');
        $this->assertTrue($this->book->fresh()->is_active);
        $this->assertSame('circulating',$copy->fresh()->status);
    }

    public function test_legacy_loans_consume_stock_across_sessions_without_inventing_dates(): void
    {
        $legacy=BookIssue::create(['school_id'=>$this->admin->school_id,'book_id'=>$this->book->id,'student_id'=>$this->student->id,
            'class_id'=>1,'session_id'=>999,'issue_date'=>'1600000000','timestamp'=>1600000000,'status'=>0]);
        $this->issue($this->second)->assertSessionHasNoErrors();
        $this->assertNotNull($legacy->fresh()->copy_id); $this->assertNull($legacy->fresh()->due_date);
        $this->assertSame('1600000000',$legacy->fresh()->issue_date);
        $this->get(route('admin.library.loans',['state'=>'legacy']))->assertOk()->assertSee('Due date not recorded');
        $this->post(route('admin.library.loans.renew',['id'=>$legacy->id]))->assertSessionHasErrors('library');
    }

    public function test_legacy_get_urls_are_safe_review_pages_and_cannot_delete_history(): void
    {
        $this->issue()->assertSessionHasNoErrors(); $loan=BookIssue::first();
        foreach (['admin.book_issue.return','admin.book_issue.delete'] as $route) $this->get(route($route,['id'=>$loan->id]))->assertOk();
        $this->get(route('admin.book.delete',['id'=>$this->book->id]))->assertOk();
        $this->assertSame(0,$loan->fresh()->status); $this->assertNotNull($this->book->fresh());
        $this->post(route('admin.book_issue.update',['id'=>$loan->id]),['status'=>1])->assertStatus(405);
        $this->assertSame(0,$loan->fresh()->status);
    }

    public function test_csv_export_is_scoped_and_neutralizes_spreadsheet_formulas(): void
    {
        $this->book->update(['name'=>'=HYPERLINK("https://example.test")']);
        $foreign=$this->title(['school_id'=>$this->makeSchool(),'name'=>'Other School Secret','isbn'=>'9780000000003']);
        $response=$this->get(route('admin.library.export',['type'=>'catalogue']));
        $response->assertOk(); $csv=$response->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK",$csv); $this->assertStringNotContainsString($foreign->name,$csv);
        $this->issue()->assertSessionHasNoErrors();
        $this->get(route('admin.library.export',['type'=>'loans']))->assertOk();
    }

    public function test_librarian_workspace_and_student_catalogue_are_functional(): void
    {
        $librarian=User::factory()->create(['school_id'=>$this->admin->school_id,'role_id'=>5]);
        $this->actingAs($librarian)->get(route('librarian.library.dashboard'))->assertOk()->assertSee('Library');
        $this->get(route('librarian.library.checkout',['member_search'=>'PIIE-0001']))->assertOk()->assertSee('Amina Reader');
        $this->actingAs($this->student)->get(route('student.library.dashboard'))->assertOk()->assertSee('My active loans');
        $this->get(route('student.library.catalogue'))->assertOk()->assertSee($this->book->name);
        $this->get(route('student.library.books.show',['id'=>$this->book->id]))->assertOk()->assertSee('Reserve this title');
        Http::assertNothingSent();
    }

    public function test_restricted_staff_cannot_bypass_library_menu_permissions(): void
    {
        $this->admin->menu_permission=json_encode(['admin.dashboard']); $this->admin->save();
        $this->get(route('admin.library.catalogue'))->assertForbidden();
        $this->post(route('admin.library.checkout.store'),['book_id'=>$this->book->id,'student_id'=>$this->student->id])->assertForbidden();
        $this->assertSame(0,BookIssue::count());
        $this->admin->menu_permission=json_encode(['admin.book.book_list']); $this->admin->save();
        $this->get(route('admin.library.dashboard'))->assertOk();
    }

    public function test_inconsistent_legacy_references_do_not_disclose_other_school_records(): void
    {
        $other=$this->makeSchool(); $foreign=$this->title(['school_id'=>$other,'name'=>'Foreign Private Title','isbn'=>'9780000000003']);
        $member=User::factory()->create(['school_id'=>$other,'role_id'=>7,'name'=>'Foreign Private Member']);
        BookIssue::create(['school_id'=>$this->admin->school_id,'book_id'=>$foreign->id,'student_id'=>$member->id,'class_id'=>0,'session_id'=>0,'issue_date'=>now()->timestamp,'timestamp'=>now()->timestamp,'status'=>0]);
        foreach (['dashboard','loans'] as $page) $this->get(route('admin.library.'.$page))->assertOk()->assertDontSee('Foreign Private Title')->assertDontSee('Foreign Private Member');
        $csv=$this->get(route('admin.library.export',['type'=>'loans']))->streamedContent();
        $this->assertStringNotContainsString('Foreign Private',$csv);
    }

    public function test_legacy_stock_shortage_is_not_hidden_by_accession_generation(): void
    {
        $this->book->update(['copies'=>1]);
        foreach ([$this->student,$this->second] as $member) BookIssue::create(['school_id'=>$this->admin->school_id,'book_id'=>$this->book->id,'student_id'=>$member->id,'class_id'=>0,'session_id'=>0,'issue_date'=>now()->timestamp,'timestamp'=>now()->timestamp,'status'=>0]);
        $third=User::factory()->create(['role_id'=>7,'school_id'=>$this->admin->school_id]);
        $this->issue($third)->assertSessionHasErrors('library');
        $this->assertSame(2,BookIssue::count());
        $this->assertSame(1,$this->book->fresh()->copies);
        $this->assertSame(0,$this->library->available($this->book));
    }

    public function test_damaged_copy_cannot_be_marked_circulating_and_unsafe_resource_urls_are_rejected(): void
    {
        $this->library->initializeCopies($this->book); $copy=$this->book->holdings()->first();
        $this->post(route('admin.library.copies.update',['id'=>$copy->id]),['accession'=>$copy->accession,'status'=>'circulating','condition'=>'damaged'])->assertSessionHasErrors('status');
        $this->post(route('admin.library.books.store'),['name'=>'Unsafe Link','author'=>'Author','copies'=>1,'language'=>'English','is_active'=>1,'resource_url'=>'javascript:alert(1)'])->assertSessionHasErrors('resource_url');
        $this->assertSame(1,Book::count());
    }
}
