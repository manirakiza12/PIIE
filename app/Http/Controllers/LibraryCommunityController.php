<?php
namespace App\Http\Controllers;

use App\Models\{Book, BookIssue, School, User};
use App\Support\Library\LibraryDigitalStorage;
use App\Support\Permissions\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Storage};

class LibraryCommunityController extends LibraryController
{
    public function institutions()
    {
        abort_unless((int)auth()->user()?->role_id===1,403);
        return view('library.institutions',['schools'=>School::orderBy('title')->paginate(20)]);
    }

    public function download(int $id)
    {
        $actor=$this->access('library.view',true);
        $book=Book::where('school_id',$actor->school_id)->where('is_active',true)->findOrFail($id);
        $policy=$this->library->policy($actor->school_id);
        $student=(int)$actor->role_id===7;
        abort_unless(($student ? $policy->student_downloads && $book->download_audience==='all' : $policy->staff_downloads),403);
        $response=app(LibraryDigitalStorage::class)->download($actor,$book);
        $this->library->event($actor,'digital_download','Downloaded approved digital resource #'.$id,$id);
        return $response;
    }

    public function requests()
    {
        $actor=$this->access('library.view',true);
        $requests=DB::table('library_requests')->where('school_id',$actor->school_id);
        if($this->reader()) $requests->where('user_id',$actor->id);
        $requests=$requests->orderByDesc('id')->paginate(15);
        return $this->view('requests',compact('requests'));
    }

    public function requestStore(Request $request)
    {
        $actor=$this->access('library.view',true);
        $data=$request->validate(['title'=>'required|string|max:255','author'=>'required|string|max:255',
            'resource_type'=>'required|in:book,ebook,journal,past_paper,reading,other','category'=>'nullable|string|max:100',
            'reason'=>'required|string|min:10|max:4000','resource_url'=>'nullable|url:https|max:1000']);
        if($request->hasFile('digital_file') && (int)$actor->role_id!==3) $this->access('library.digital');
        $digital=$request->hasFile('digital_file') ? app(LibraryDigitalStorage::class)->store($request,$actor,'requests') : [];
        try { DB::transaction(function () use ($actor,$data,$digital) {
            $id=DB::table('library_requests')->insertGetId(array_merge($data,$digital,['school_id'=>$actor->school_id,'user_id'=>$actor->id,'status'=>'pending','created_at'=>now(),'updated_at'=>now()]));
            $this->library->event($actor,'resource_request','Submitted recommendation/reading #'.$id.' for librarian approval.');
        }); } catch(\Throwable $e) { if($digital) Storage::disk('local')->delete($digital['digital_path']); throw $e; }
        return $this->redirect('requests','Request submitted for librarian review. Uploaded readings are private until approved.');
    }

    public function requestFile(int $id)
    {
        $actor=$this->access('library.digital');
        $record=DB::table('library_requests')->where('school_id',$actor->school_id)->where('id',$id)->first();
        abort_unless($record,404);
        return app(LibraryDigitalStorage::class)->download($actor,$record);
    }

    public function review(Request $request,int $id)
    {
        $actor=$this->access('library.digital');
        $data=$request->validate(['decision'=>'required|in:approved,rejected','review_note'=>'required|string|min:5|max:2000','download_audience'=>'required|in:all,staff']);
        DB::transaction(function () use ($actor,$id,$data) {
            $record=DB::table('library_requests')->where('school_id',$actor->school_id)->where('id',$id)->lockForUpdate()->first();
            abort_unless($record,404); if($record->status!=='pending') $this->library->fail('This request has already been reviewed.');
            $book=null;
            if($data['decision']==='approved') $book=Book::create(['school_id'=>$actor->school_id,'session_id'=>0,'timestamp'=>now()->timestamp,
                'name'=>$record->title,'author'=>$record->author,'category'=>$record->category,'resource_type'=>$record->resource_type,
                'resource_url'=>$record->resource_url,'digital_path'=>$record->digital_path,'digital_name'=>$record->digital_name,'digital_mime'=>$record->digital_mime,
                'download_audience'=>$data['download_audience'],'copies'=>0,'language'=>'English','is_active'=>true]);
            DB::table('library_requests')->where('id',$id)->update(['status'=>$data['decision'],'reviewed_by'=>$actor->id,'review_note'=>$data['review_note'],'book_id'=>$book?->id,'updated_at'=>now()]);
            $this->library->event($actor,'request_review','Request #'.$id.' '.$data['decision'].': '.$data['review_note'],$book?->id);
        },3);
        return $this->redirect('requests','Request reviewed. Approved resources are published in the catalogue.');
    }

    public function notices()
    {
        $actor=$this->access('library.view',true); $today=$this->library->today($actor);
        $notices=DB::table('library_notices')->where('school_id',$actor->school_id)->where('is_active',true)
            ->where(fn($q)=>$q->whereNull('expires_on')->orWhere('expires_on','>=',$today));
        if((int)$actor->role_id===7) $notices->where('audience','all');
        $notices=$notices->orderByDesc('id')->paginate(15);
        return $this->view('notices',compact('notices'));
    }

    public function noticeStore(Request $request)
    {
        $actor=$this->access('library.notices');
        $data=$request->validate(['title'=>'required|string|max:255','body'=>'required|string|max:10000','audience'=>'required|in:all,staff',
            'expires_on'=>'nullable|date_format:Y-m-d|after_or_equal:'.$this->library->today($actor)]);
        DB::transaction(function () use ($actor,$data) {
            $id=DB::table('library_notices')->insertGetId(array_merge($data,['school_id'=>$actor->school_id,'created_by'=>$actor->id,'created_at'=>now(),'updated_at'=>now()]));
            $this->library->event($actor,'notice_publish','Published library notice #'.$id);
        });
        return $this->redirect('notices','Library notice published.');
    }

    public function noticeArchive(int $id)
    {
        $actor=$this->access('library.notices');
        DB::transaction(function () use ($actor,$id) {
            $record=DB::table('library_notices')->where('school_id',$actor->school_id)->where('id',$id)->lockForUpdate()->first(); abort_unless($record,404);
            DB::table('library_notices')->where('id',$id)->update(['is_active'=>false,'updated_at'=>now()]);
            $this->library->event($actor,'notice_archive','Archived library notice #'.$id);
        });
        return $this->redirect('notices','Notice archived.');
    }

    public function fines()
    {
        $actor=$this->access('library.fines',true);
        $fines=DB::table('library_fines')->where('school_id',$actor->school_id);
        if($this->reader()) $fines->where('user_id',$actor->id);
        $fines=$fines->orderByDesc('id')->paginate(20);
        return $this->view('fines',compact('fines'));
    }

    public function resolveFine(Request $request,int $id)
    {
        $actor=$this->access('library.fines');
        $data=$request->validate(['status'=>'required|in:paid,waived','resolution_note'=>'required|string|min:10|max:2000']);
        if($data['status']==='waived') $this->access('library.override');
        DB::transaction(function () use ($actor,$id,$data) {
            $fine=DB::table('library_fines')->where('school_id',$actor->school_id)->where('id',$id)->lockForUpdate()->first(); abort_unless($fine,404);
            if($fine->status!=='outstanding') $this->library->fail('This charge has already been resolved.');
            DB::table('library_fines')->where('id',$id)->update(array_merge($data,['resolved_by'=>$actor->id,'resolved_at'=>now(),'updated_at'=>now()]));
            $this->library->event($actor,'fine_'.$data['status'],'Charge #'.$id.' '.$data['status'].': '.$data['resolution_note'],null,$fine->loan_id);
        },3);
        return $this->redirect('fines','Library charge resolved.');
    }

    public function remind(int $id)
    {
        $actor=$this->access('library.issue');
        DB::transaction(function () use ($actor,$id) {
            $loan=BookIssue::where('school_id',$actor->school_id)->lockForUpdate()->findOrFail($id);
            if((int)$loan->status!==0 || !$loan->due_date || $loan->due_date->toDateString()>=$this->library->today($actor)) $this->library->fail('Only overdue open loans can receive a reminder.');
            if($loan->reminded_at && $loan->reminded_at->toDateString()===now()->toDateString()) $this->library->fail('This loan was already reminded today.');
            $member=User::where('school_id',$actor->school_id)->findOrFail($loan->student_id);
            $prefix=(int)$member->role_id===7 ? 'student' : ((int)$member->role_id===3?'teacher':'admin');
            \App\Support\Notifications\NotificationService::notify($member->id,$actor->school_id,'Library book overdue','Loan #'.$loan->id.' was due '.$loan->due_date->toDateString().'. Please visit the library return desk.',route($prefix.'.library.loans',[],false),'library');
            $loan->update(['reminded_at'=>now()]);
            $this->library->event($actor,'overdue_reminder','Posted portal reminder for member #'.$member->id,null,$loan->id);
        },3);
        return $this->redirect('loans','Overdue reminder delivered to the borrower’s portal notifications.');
    }

    public function blocks()
    {
        $actor=$this->access('library.override');
        $blocks=DB::table('library_member_blocks')->where('school_id',$actor->school_id)->orderByDesc('id')->paginate(20);
        return $this->view('blocks',compact('blocks'));
    }

    public function blockStore(Request $request)
    {
        $actor=$this->access('library.override');
        $data=$request->validate(['user_id'=>'required|integer','reason'=>'required|string|min:10|max:2000','is_active'=>'required|boolean']);
        DB::transaction(function () use ($actor,$data) {
            $member=$this->library->member($actor,(int)$data['user_id']);
            DB::table('library_member_blocks')->updateOrInsert(['school_id'=>$actor->school_id,'user_id'=>$member->id],['reason'=>$data['reason'],'is_active'=>$data['is_active'],'changed_by'=>$actor->id,'created_at'=>now(),'updated_at'=>now()]);
            $this->library->event($actor,'member_block_review','Member #'.$member->id.': '.($data['is_active']?'blocked':'unblocked').'; '.$data['reason']);
        },3);
        return $this->redirect('blocks','Library borrowing status updated.');
    }

    public function courseLink(Request $request,int $id)
    {
        $actor=$this->access('library.view',true); abort_unless($this->prefix()==='teacher',403);
        $data=$request->validate(['course_offering_id'=>'required|integer']);
        $access=app(\App\Support\CourseContent\CourseContentAccess::class);
        $offering=$access->resolveOffering($actor,(int)$data['course_offering_id']);
        abort_unless($access->canLecturerManage($actor,$offering),403);
        DB::transaction(function () use ($actor,$offering,$id) {
            $book=$this->library->book($actor,$id); abort_unless($book->is_active,404);
            DB::table('library_course_resources')->updateOrInsert(['course_offering_id'=>$offering->id,'book_id'=>$id],['school_id'=>$actor->school_id,'linked_by'=>$actor->id,'created_at'=>now(),'updated_at'=>now()]);
            $this->library->event($actor,'course_resource_link','Linked approved resource to offering #'.$offering->id,$id);
        });
        return $this->redirect('course-resources','Approved library resource linked to your course allocation.');
    }

    public function courseResources()
    {
        $actor=$this->access('library.view',true); abort_unless($this->reader(),403);
        $access=app(\App\Support\CourseContent\CourseContentAccess::class);
        $links=DB::table('library_course_resources')->join('books','books.id','=','library_course_resources.book_id')
            ->where('library_course_resources.school_id',$actor->school_id)->where('books.school_id',$actor->school_id)->where('books.is_active',true)
            ->select('library_course_resources.*','books.name','books.author')->get()->filter(function ($link) use ($actor,$access) {
                try { $offering=$access->resolveOffering($actor,$link->course_offering_id);
                    return (int)$actor->role_id===7 ? $access->canStudentAccess($actor,$offering) : $access->canLecturerManage($actor,$offering);
                } catch(\Symfony\Component\HttpKernel\Exception\HttpException $e) { return false; }
            });
        return $this->view('course_resources',compact('links'));
    }

    public function staffAccess(Request $request)
    {
        $actor=$this->access('library.configure');
        $request->validate(['search'=>'nullable|string|max:200']);
        $staff=User::where('school_id',$actor->school_id)->whereNotIn('role_id',[1,2,6,7,8]);
        if($request->filled('search')) $staff->where(fn($q)=>$q->where('name','like','%'.$request->search.'%')->orWhere('code','like','%'.$request->search.'%')->orWhere('email','like','%'.$request->search.'%'));
        $staff=$staff->orderBy('name')->paginate(20)->withQueryString();
        $assigned=DB::table('user_staff_roles')->join('staff_roles','staff_roles.id','=','user_staff_roles.staff_role_id')
            ->where('user_staff_roles.school_id',$actor->school_id)->where('staff_roles.school_id',$actor->school_id)
            ->whereIn('staff_roles.name',['Library Manager','Assistant Librarian'])->select('user_staff_roles.user_id','staff_roles.id','staff_roles.name')->get();
        return $this->view('staff_access',compact('staff','assigned'));
    }

    public function assignStaff(Request $request)
    {
        $actor=$this->access('library.configure');
        $data=$request->validate(['user_id'=>'required|integer','template'=>'required|in:library_officer,assistant_librarian','action'=>'required|in:assign,remove']);
        DB::transaction(function () use ($actor,$data) {
            $target=User::where('school_id',$actor->school_id)->lockForUpdate()->findOrFail($data['user_id']);
            if((int)$target->role_id===5 && $data['template']==='assistant_librarian') $this->library->fail('The built-in Librarian already has manager access. Use a Generic Staff account for an assistant role.');
            $template=\App\Support\Permissions\PermissionRegistry::templates()[$data['template']];
            $service=app(\App\Support\Permissions\PermissionAssignmentService::class);
            $role=DB::table('staff_roles')->where('school_id',$actor->school_id)->where('name',$template['name'])->first();
            if($data['action']==='assign') {
                if(!$role) {
                    $id=$service->createStaffRole($actor,$template['name'],$template['permissions'],substr($template['description'],0,255),$actor->school_id);
                    $role=DB::table('staff_roles')->where('id',$id)->first();
                }
                $expected=app(PermissionService::class)->withDependencies($template['permissions']); $actual=$service->bundle($role->id);
                sort($expected);sort($actual);
                if($expected!==$actual) $this->library->fail('This named role has different permissions. Review it in Staff → Roles & Permissions before assigning it.');
                $service->assignStaffRole($actor,$target,$role->id);
            } elseif($role) $service->removeStaffRole($actor,$target,$role->id);
            $this->library->event($actor,'library_role_review',ucfirst($data['action']).' '.$template['name'].' for staff #'.$target->id);
        },3);
        return $this->redirect('staff-access','Library role assignment updated. Catalogue and circulation records remain with the institution.');
    }
}
