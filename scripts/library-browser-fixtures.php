<?php
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('APP_URL=http://localhost');
require __DIR__.'/../vendor/autoload.php';

class LibraryBrowserFixtures extends \Tests\Feature\LibraryManagementTest
{
    public function export(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_09_23_000003_create_rbac_tables.php'))->up();
        (require database_path('migrations/2026_09_23_000004_add_is_active_to_staff_roles.php'))->up();
        \Illuminate\Support\Facades\Schema::create('payment_history',fn(\Illuminate\Database\Schema\Blueprint $t)=>$t->string('status')->nullable());
        $this->admin->update(['name'=>'PIIE Administrator']);
        $output = base_path('output/library-upgrade');
        if (!is_dir($output)) mkdir($output, 0777, true);
        $this->issue()->assertSessionHasNoErrors();
        $loan = \App\Models\BookIssue::first();
        $loan->update(['due_date' => now()->subDays(3)->toDateString()]);
        $nursing = $this->title(['name'=>'Clinical Nursing: A Practical Guide','isbn'=>'9780000000004','category'=>'Health Sciences','copies'=>12,'author'=>'Sample Faculty Editor','publisher'=>'Training Press','edition'=>'Second edition','publication_year'=>2024,'shelf_location'=>'HS / 610.73','description'=>'A synthetic catalogue entry for previewing the library workspace.\nIncludes care planning, patient safety and clinical skills.']);
        $this->library->initializeCopies($nursing);
        $this->library->reserve($this->admin,$nursing->id,$this->second->id);
        $other = $this->title(['name'=>'Business Management','isbn'=>'9780000000005','category'=>'Business','copies'=>5]);
        $this->library->initializeCopies($other);
        $other->holdings()->limit(1)->update(['status'=>'reference']);
        $this->actingAs($this->admin);
        $assistant=\App\Models\User::factory()->create(['school_id'=>$this->admin->school_id,'role_id'=>20,'name'=>'Library Assistant']);
        $this->post(route('admin.library.staff-access.update'),['user_id'=>$assistant->id,'template'=>'assistant_librarian','action'=>'assign'])->assertSessionHasNoErrors();
        $this->post(route('admin.library.notices.store'),['title'=>'Welcome to the PIIE Library','body'=>'Find books, course readings and past papers in the catalogue. Bring your student or staff identification when collecting reserved books.','audience'=>'all'])->assertSessionHasNoErrors();
        \Illuminate\Support\Facades\DB::table('library_fines')->insert(['school_id'=>$this->admin->school_id,'loan_id'=>$loan->id,'user_id'=>$this->student->id,'reason'=>'overdue','amount'=>500,'currency'=>'UGX','created_at'=>now(),'updated_at'=>now()]);
        $pages = [
            'overview'=>route('admin.library.dashboard'), 'catalogue'=>route('admin.library.catalogue'),
            'title'=>route('admin.library.books.show',['id'=>$nursing->id]),
            'add-title'=>route('admin.library.books.create'), 'edit-title'=>route('admin.library.books.edit',['id'=>$nursing->id]),
            'loans'=>route('admin.library.loans'), 'checkout'=>route('admin.library.checkout',['book_id'=>$nursing->id,'member_search'=>'PIIE']),
            'return'=>route('admin.library.loans.return',['id'=>$loan->id]),
            'reservations'=>route('admin.library.reservations'), 'reports'=>route('admin.library.reports'), 'rules'=>route('admin.library.settings'),
            'requests'=>route('admin.library.requests'), 'notices'=>route('admin.library.notices'), 'fines'=>route('admin.library.fines'),
            'blocks'=>route('admin.library.blocks'), 'staff-access'=>route('admin.library.staff-access'),
        ];
        foreach ($pages as $name=>$url) file_put_contents($output.'/'.$name.'.html',$this->get($url)->assertOk()->getContent());
        $this->actingAs($this->student);
        foreach (['student-overview'=>'dashboard','student-catalogue'=>'catalogue','student-loans'=>'loans','student-reservations'=>'reservations','student-requests'=>'requests','student-fines'=>'fines','student-notices'=>'notices','student-readings'=>'course-resources'] as $name=>$page)
            file_put_contents($output.'/'.$name.'.html',$this->get(route('student.library.'.$page))->assertOk()->getContent());
        $librarian=\App\Models\User::factory()->create(['school_id'=>$this->admin->school_id,'role_id'=>5]);
        $this->actingAs($librarian);
        file_put_contents($output.'/librarian-overview.html',$this->get(route('librarian.library.dashboard'))->assertOk()->getContent());
        $teacher=\App\Models\User::factory()->create(['school_id'=>$this->admin->school_id,'role_id'=>3,'name'=>'PIIE Lecturer']);
        $this->actingAs($teacher);
        foreach(['teacher-overview'=>'dashboard','teacher-requests'=>'requests','teacher-readings'=>'course-resources'] as $name=>$page)
            file_put_contents($output.'/'.$name.'.html',$this->get(route('teacher.library.'.$page))->assertOk()->getContent());
        $root=\App\Models\User::factory()->create(['role_id'=>1,'school_id'=>null,'name'=>'PIIE Super Admin']);
        $this->actingAs($root);
        foreach(['root-overview'=>'dashboard','root-settings'=>'settings','root-staff'=>'staff-access'] as $name=>$page)
            file_put_contents($output.'/'.$name.'.html',$this->get(route('superadmin.library.'.$page,['school'=>$this->admin->school_id]))->assertOk()->getContent());
        echo json_encode(['directory'=>$output,'pages'=>count($pages)+15]).PHP_EOL;
    }
}
(new LibraryBrowserFixtures('test_all_admin_workspace_pages_render_and_catalogue_searches_metadata'))->export();
