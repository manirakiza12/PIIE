<?php
namespace Tests\Feature;

use App\Models\Book;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\CourseContentFixture;
use Tests\TestCase;

class LibraryCourseResourcesTest extends TestCase
{
    use CourseContentFixture { setUp as protected contentSetUp; }
    private Book $resource;

    protected function setUp(): void
    {
        $this->contentSetUp();
        (require database_path('migrations/2026_10_14_160000_upgrade_library_management.php'))->up();
        (require database_path('migrations/2026_10_14_170000_add_library_governance_and_digital_resources.php'))->up();
        $this->resource=Book::create(['school_id'=>$this->school,'name'=>'Approved Business Reading','author'=>'Faculty Editor','copies'=>0,
            'session_id'=>0,'timestamp'=>now()->timestamp,'is_active'=>true,'resource_type'=>'reading']);
    }

    public function test_allocated_lecturer_links_approved_resource_and_confirmed_student_sees_it(): void
    {
        $this->actingAs($this->lecturer)->post(route('teacher.library.books.course-link',['id'=>$this->resource->id]),['course_offering_id'=>$this->offering->id])->assertSessionHasNoErrors();
        $this->assertSame(1,DB::table('library_course_resources')->count());
        $this->actingAs($this->student)->get(route('student.library.course-resources'))->assertOk()->assertSee('Approved Business Reading');
        DB::table('course_registrations')->where('student_id',$this->student->id)->update(['status'=>'dropped']);
        $this->get(route('student.library.course-resources'))->assertOk()->assertDontSee('Approved Business Reading');
    }

    public function test_unallocated_lecturer_cannot_link_and_archived_resources_disappear(): void
    {
        DB::table('course_offering_lecturer_allocations')->where('user_id',$this->otherLecturer->id)->update(['status'=>'cancelled']);
        $this->actingAs($this->otherLecturer)->post(route('teacher.library.books.course-link',['id'=>$this->resource->id]),['course_offering_id'=>$this->offering->id])->assertNotFound();
        $this->assertSame(0,DB::table('library_course_resources')->count());
        $this->actingAs($this->lecturer)->post(route('teacher.library.books.course-link',['id'=>$this->resource->id]),['course_offering_id'=>$this->offering->id])->assertSessionHasNoErrors();
        $this->resource->update(['is_active'=>false]);
        $this->actingAs($this->student)->get(route('student.library.course-resources'))->assertOk()->assertDontSee('Approved Business Reading');
    }
}
