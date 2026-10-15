<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Book;
use App\Models\BookIssue;
use App\Models\Session;
use App\Models\Classes;
use App\Models\User;
use App\Models\Noticeboard;
use App\Models\FrontendEvent;
use App\Models\MessageThrade;
use App\Models\Chat;

use Illuminate\Support\Facades\DB;
use App\Support\ProfilePhoto;

class LibrarianController extends Controller
{
    public function librarianDashboard()
    {
        return view('librarian.dashboard');
    }

    /**
     * Show the book list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function bookList(Request $request)
    {
        return app(LibraryController::class)->catalogue(request());
    }

    public function createBook()
    {
        return app(LibraryController::class)->bookForm();
    }

    public function bookCreate(Request $request)
    {
        return app(LibraryController::class)->saveBook(request());
    }

    public function editBook($id="")
    {
        return app(LibraryController::class)->bookForm((int) $id);
    }

    public function bookUpdate(Request $request, $id='')
    {
        return app(LibraryController::class)->saveBook(request(), (int) $id);
    }

    public function bookDelete($id)
    {
        return app(LibraryController::class)->legacyBookDelete((int) $id);
    }


    /**
     * Show the book list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function bookIssueList(Request $request)
    {
        return app(LibraryController::class)->loans(request());
    }

    public function createBookIssue()
    {
        return app(LibraryController::class)->checkoutForm(request());
    }

    public function bookIssueCreate(Request $request)
    {
        return app(LibraryController::class)->checkout(request());
    }

    public function editBookIssue($id="")
    {
        return app(LibraryController::class)->legacyLoanEdit((int) $id);
    }

    public function bookIssueUpdate(Request $request, $id="")
    {
        return app(LibraryController::class)->legacyLoanUpdate();
    }

    public function bookIssueReturn($id)
    {
        return app(LibraryController::class)->returnForm((int) $id);
    }

    public function bookIssueDelete($id)
    {
        return app(LibraryController::class)->legacyLoanEdit((int) $id);
    }

    function profile(){
        return view('librarian.profile.view');
    }

    function profile_update(Request $request){
        $data['name'] = $request->name;
        $data['email'] = $request->email;
        // Security Phase 2F: a self-service profile edit must not claim another account's login email.
        if (User::where('email', $request->email)->where('id', '!=', auth()->user()->id)->exists()) {
            return redirect()->back()->with('error', 'Email was already taken.');
        }
        $data['designation'] = $request->designation;
        
        $user_info['birthday'] = strtotime($request->eDefaultDateRange);
        $user_info['gender'] = $request->gender;
        $user_info['phone'] = $request->phone;
        $user_info['address'] = $request->address;


        if(empty($request->photo)){
            $user_info['photo'] = $request->old_photo;
        }else{
            $file_name = ProfilePhoto::store($request->photo);
            if ($file_name === null) {
                return redirect()->back()->with('error', 'Profile photo must be a JPG or PNG image of at most 4 MB.');
            }
            $user_info['photo'] = $file_name;
        }

        $data['user_information'] = json_encode($user_info);

        User::where('id', auth()->user()->id)->update($data);
        
        return redirect(route('librarian.profile'))->with('message', get_phrase('Profile info updated successfully'));
    }
    function user_language(Request $request){
        $data['language'] = $request->language;
        User::where('id', auth()->user()->id)->update($data);
        
        return redirect()->back()->with('message', 'You have successfully transleted language.');
    }

    function password($action_type = null, Request $request){



        if($action_type == 'update'){

            

            if($request->new_password != $request->confirm_password){
                return back()->with("error", "Confirm Password Doesn't match!");
            }


            if(!Hash::check($request->old_password, auth()->user()->password)){
                return back()->with("error", "Current Password Doesn't match!");
            }

            $data['password'] = Hash::make($request->new_password);
            User::where('id', auth()->user()->id)->update($data);

            return redirect(route('librarian.password', 'edit'))->with('message', get_phrase('Password changed successfully'));
        }

        return view('librarian.profile.password');
    }

    public function noticeboardList()
    {

        $notices = Noticeboard::get()->where('school_id', auth()->user()->school_id);

        $events = array();

        foreach ($notices as $notice) {
            if ($notice['end_date'] != "") {
                if ($notice['start_date'] != $notice['end_date']) {
                    $end_date = strtotime($notice['end_date']) + 24 * 60 * 60;
                    $end_date = date('Y-m-d', $end_date);
                } else {
                    $end_date = date('Y-m-d', strtotime($notice['end_date']));
                }
            }

            if ($notice['end_date'] == "" && $notice['start_time'] == "" && $notice['end_time'] == "") {
                $info = array(
                    'id' => $notice['id'],
                    'title' => $notice['notice_title'],
                    'start' => date('Y-m-d', strtotime($notice['start_date']))
                );
            } else if ($notice['start_time'] != "" && ($notice['end_date'] == "" && $notice['end_time'] == "")) {
                $info = array(
                    'id' => $notice['id'],
                    'title' => $notice['notice_title'],
                    'start' => date('Y-m-d', strtotime($notice['start_date'])) . 'T' . $notice['start_time']
                );
            } else if ($notice['end_date'] != "" && ($notice['start_time'] == "" && $notice['end_time'] == "")) {
                $info = array(
                    'id' => $notice['id'],
                    'title' => $notice['notice_title'],
                    'start' => date('Y-m-d', strtotime($notice['start_date'])),
                    'end' => $end_date
                );
            } else if ($notice['end_date'] != "" && $notice['start_time'] != "" && $notice['end_time'] != "") {
                $info = array(
                    'id' => $notice['id'],
                    'title' => $notice['notice_title'],
                    'start' => date('Y-m-d', strtotime($notice['start_date'])) . 'T' . $notice['start_time'],
                    'end' => date('Y-m-d', strtotime($notice['end_date'])) . 'T' . $notice['end_time']
                );
            } else {
                $info = array(
                    'id' => $notice['id'],
                    'title' => $notice['notice_title'],
                    'start' => date('Y-m-d', strtotime($notice['start_date']))
                );
            }
            array_push($events, $info);
        }

        $events = json_encode($events);

        return view('librarian.noticeboard.noticeboard', ['events' => $events]);
    }

    public function editNoticeboard($id = "")
    {
        $notice = Noticeboard::where('school_id', auth()->user()->school_id)->findOrFail($id);
        return view('librarian.noticeboard.edit', ['notice' => $notice]);
    }

    /**
     * Show the event list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function eventList(Request $request)
    {
        $search = $request['search'] ?? "";

        if($search != "") {

            $events = FrontendEvent::where(function ($query) use($search) {
                    $query->where('title', 'LIKE', "%{$search}%");
                })->paginate(10);

        } else {
            $events = FrontendEvent::where('school_id', auth()->user()->school_id)->paginate(10);
        }

        return view('librarian.events.events', compact('events', 'search'));
    }

       //  Message

       public function allMessage(Request $request, $id)
       {
        return app(\App\Http\Controllers\MessagingController::class)->legacyShow($request, $id);
    }
   
       public function messagethrades($id){
        return app(\App\Http\Controllers\MessagingController::class)->legacyStart($id);
    }
   
   
       public function chat_save(Request $request)
       {
        return app(\App\Http\Controllers\MessagingController::class)->legacySend($request);
    }
   
       public function chat_empty(Request $request)
       {
        return app(\App\Http\Controllers\MessagingController::class)->index($request);
    }

       private function ownedMessageThread($id): MessageThrade
       {
           return MessageThrade::where('school_id', auth()->user()->school_id)
               ->where(fn ($query) => $query->where('sender_id', auth()->id())->orWhere('reciver_id', auth()->id()))
               ->findOrFail($id);
       }
      

}
