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
           $this->ownedMessageThread($id);
   
               $msg_user_details = DB::table('users')
               ->join('message_thrades', function ($join) {
                   // Join where the user is the sender
                   $join->on('users.id', '=', 'message_thrades.sender_id')
                       ->orWhere(function ($query) {
                           // Join where the user is the receiver
                           $query->on('users.id', '=', 'message_thrades.reciver_id');
                       });
               })
               ->select('users.id as user_id', 'message_thrades.id as thread_id', 'users.*', 'message_thrades.*')
               ->where('message_thrades.id', $id)
               ->where('message_thrades.school_id', auth()->user()->school_id)
               ->where('users.id', '<>', auth()->user()->id) // Exclude the authenticated user
               ->first();
   
               
               
           if ($request->ajax()) {
               $query = $request->input('query');
               
               // Search users by name or any other criteria
               $users = User::where('name', 'LIKE', "%{$query}%")
                   ->where('school_id', auth()->user()->school_id)
                   ->get();
   
               // Prepare HTML response
               $html = '';
   
               // Check if any users were found
               if ($users->isEmpty()) {
                   return response()->json('No User found');
               }
   
               foreach ($users as $user) {
                   
                   if (!empty($user)) {
                       $userInfo = json_decode($user->user_information);
                       
                       $user_image = !empty($userInfo->photo) 
                           ? asset('assets/uploads/user-images/' . $userInfo->photo) 
                           : asset('assets/uploads/user-images/thumbnail.png');
   
                       $html .= '
                           <div class="user-item d-flex align-items-center msg_us_src_list">
                               <a href="' . route('librarian.message.messagethrades', ['id' => $user->id]).'">
                                   <img src="' . $user_image . '" alt="User Image" style="width: 50px; height: 50px; border-radius: 50%;">
                                   <span class="ms-3">' . e($user->name) . '</span>
                               </a>
                           </div>
                       ';
                   }
               }
   
               return response()->json($html);
           }
   
   
           $chat_datas = Chat::where('school_id', auth()->user()->school_id)->where('message_thrade', $id)->get();
   
           $counter_condition = Chat::where('message_thrade', $id)->orderBy('id', 'desc')->first();
   
          
          if($counter_condition && $counter_condition->sender_id != auth()->user()->id){
               Chat::where('message_thrade', $id)->update(['read_status' => 1]);
           }
           
           return view('librarian.message.all_message', ['msg_user_details' => $msg_user_details], ['chat_datas' => $chat_datas]);
       }
   
       public function messagethrades($id){
           User::where('school_id', auth()->user()->school_id)->findOrFail($id);
   
           $exists = MessageThrade::where('reciver_id', $id)
                               ->where('school_id', auth()->user()->school_id)
                               ->where('sender_id', auth()->user()->id)
                               ->exists();
           if( $id != auth()->user()->id){
               if (!$exists) {
                   $message_thrades_data = [
                       'reciver_id' => $id,
                       'sender_id' => auth()->user()->id,
                       'school_id' => auth()->user()->school_id,
                   ];
           
                   MessageThrade::create($message_thrades_data);
           
                   //return redirect()->back()->with('message', 'User added successfully');
               }
       
               
               $message_thrades = MessageThrade::where('reciver_id', $id)
                                            ->where('school_id', auth()->user()->school_id)
                                            ->where('sender_id', auth()->user()->id)
                                            ->first();
               $msg_trd_id = $message_thrades->id;
               
               $msg_user_details = DB::table('users')
                   ->join('message_thrades', 'users.id', '=', 'message_thrades.reciver_id')
                   ->select('users.id as user_id', 'message_thrades.id as thread_id', 'users.*', 'message_thrades.*')
                   ->where('message_thrades.id', $msg_trd_id)
                   ->first();
       
                   $chat_datas = Chat::where('school_id', auth()->user()->school_id)->where('message_thrade', $msg_trd_id)->get();
       
                   // Combine all data into a single array
                   return view('librarian.message.all_message', ['id' => $msg_trd_id, 'msg_user_details' => $msg_user_details, 'chat_datas' => $chat_datas,]);
           }
           return redirect()->back()->with('error', 'You can not add you');
           
                           
       }
   
   
       public function chat_save(Request $request)
       {
           $data = $request->validate(['message_thrade' => 'required|integer', 'reciver_id' => 'required|integer', 'message' => 'required|string|max:5000']);
           $thread = $this->ownedMessageThread($data['message_thrade']);
           $recipient = (int) $thread->sender_id === (int) auth()->id() ? $thread->reciver_id : $thread->sender_id;
           User::where('school_id', auth()->user()->school_id)->findOrFail($recipient);
           abort_unless((int) $data['reciver_id'] === (int) $recipient, 422, 'Recipient does not match this conversation.');
           $chat_data = [
               'message_thrade' => $data['message_thrade'],
               'reciver_id' => $data['reciver_id'],
               'message' => $data['message'],
               'school_id' => auth()->user()->school_id,
               'sender_id' => auth()->user()->id,
               'read_status' => 0,
   
           ];
       
           // Create feedback entry
           Chat::create($chat_data);
   
           return redirect()->back();
       }
   
       public function chat_empty(Request $request)
       {
   
           if ($request->ajax()) {
               $query = $request->input('query');
   
               $users = User::where('name', 'LIKE', "%{$query}%")
                   ->where('school_id', auth()->user()->school_id)
                   ->get();
   
               $html = '';
   
               if ($users->isEmpty()) {
                   return response()->json('No User found');
               }
   
               foreach ($users as $user) {
                   $userInfo = json_decode($user->user_information);
                   $user_image = !empty($userInfo->photo) 
                       ? asset('assets/uploads/user-images/' . $userInfo->photo) 
                       : asset('assets/uploads/user-images/thumbnail.png');
   
                   $html .= '
                       <div class="user-item d-flex align-items-center msg_us_src_list">
                           <a href="' . route('librarian.message.messagethrades', ['id' => $user->id]).'">
                               <img src="' . $user_image . '" alt="User Image" style="width: 50px; height: 50px; border-radius: 50%;">
                               <span class="ms-3">' . e($user->name) . '</span>
                           </a>
                       </div>
                   ';
               }
   
               return response()->json($html);
           }
   
           // Pass the data to the view only if msg_user_details is not null
           return view('librarian.message.chat_empty');
       }

       private function ownedMessageThread($id): MessageThrade
       {
           return MessageThrade::where('school_id', auth()->user()->school_id)
               ->where(fn ($query) => $query->where('sender_id', auth()->id())->orWhere('reciver_id', auth()->id()))
               ->findOrFail($id);
       }
      

}
