<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\FrontendEvent;
use App\Models\Hostel;
use App\Models\HostelApplication;
use App\Models\HostelFee;
use App\Models\HostelRoom;
use App\Models\HostelRoomAllocation;
use App\Models\MessageThrade;
use App\Models\Noticeboard;
use App\Models\user;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Support\ProfilePhoto;
use App\Support\Audit\StatusChangeAudit;

class WardenController extends Controller
{
    public function wardenDashboard()
    {
        return view('warden.dashboard');
    }

    public function hostel_room_list()
    {
        $wardenId = auth()->user()->id;

        $hostel                    = Hostel::where('warden_id', $wardenId)->pluck('id')->toArray();
        $page_data['hostel_rooms'] = HostelRoom::whereIn('hostel_id', $hostel)->paginate(10);

        return view('warden.hostel_room.list', $page_data);
    }
    public function hostel_room_allocation_list()
    {
        $page_data['hostel_room_allocations'] = HostelRoomAllocation::where('school_id', auth()->user()->school_id)->paginate(10);
        return view('warden.hostel_room_allocation.list', $page_data);
    }
    public function create_hostel_room_allocation()
    {
        $page_data['hostel_rooms'] = HostelRoom::where('school_id', auth()->user()->school_id)->get();
        $page_data['students']     = User::where('role_id', 7)->where('school_id', auth()->user()->school_id)->get();
        return view('warden.hostel_room_allocation.create', $page_data);
    }
    public function store_hostel_room_allocation(Request $request)
    {
        $data              = $request->all();
        $data['school_id'] = auth()->user()->school_id;
        HostelRoomAllocation::create($data);

        $room = HostelRoom::where('school_id', auth()->user()->school_id)->find($data['room_id']);
        if ($room) {
            $currentOccupied = HostelRoomAllocation::where('room_id', $room->id)->count();
            $room->update(['occupied' => $currentOccupied]);
        }
        $application             = new HostelApplication();
        $application->student_id = $data['student_id'];
        $application->school_id  = auth()->user()->school_id;
        $application->hostel_id  = $room->hostel_id;
        $application->room_id    = $data['room_id'];
        $application->status     = 1;
        $application->save();
        return redirect()->route('warden.hostel.allocation_list')->with('message', 'Hostel Room Allocation created successfully');
    }
    public function edit_hostel_room_allocation($id)
    {
        $page_data['hostel_room_allocation'] = HostelRoomAllocation::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $page_data['hostel_rooms']           = HostelRoom::where('school_id', auth()->user()->school_id)->get();
        $page_data['students']               = User::where('role_id', 7)->where('school_id', auth()->user()->school_id)->get();
        return view('warden.hostel_room_allocation.edit', $page_data);
    }
    public function update_hostel_room_allocation(Request $request, $id)
    {
        $allocation = HostelRoomAllocation::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $oldRoomId  = $allocation->room_id;

        $data = $request->all();
        unset($data['_token']);
        $allocation->update($data);

        // Update occupied count for old and new room
        if ($oldRoomId != $data['room_id']) {
            $oldRoom = HostelRoom::where('school_id', auth()->user()->school_id)->find($oldRoomId);
            if ($oldRoom) {
                $oldRoom->update([
                    'occupied' => HostelRoomAllocation::where('room_id', $oldRoom->id)->count(),
                ]);
            }
        }

        $newRoom = HostelRoom::where('school_id', auth()->user()->school_id)->find($data['room_id']);
        if ($newRoom) {
            $newRoom->update([
                'occupied' => HostelRoomAllocation::where('room_id', $newRoom->id)->count(),
            ]);
        }
        return redirect()->route('warden.hostel.allocation_list')->with('message', 'Hostel Room Allocation updated successfully');
    }
    public function delete_hostel_room_allocation($id)
    {
        $allocation = HostelRoomAllocation::where('school_id', auth()->user()->school_id)->findOrFail($id);

        if ($allocation) {
            $roomId = $allocation->room_id;
            $allocation->delete();

            // Update occupied count
            $room = HostelRoom::where('school_id', auth()->user()->school_id)->find($roomId);
            if ($room) {
                $room->update([
                    'occupied' => HostelRoomAllocation::where('room_id', $room->id)->count(),
                ]);
            }
        }

        return redirect()->route('warden.hostel.allocation_list')
            ->with('message', 'Hostel Room Allocation deleted successfully');
    }
    public function applications()
    {
        $applications = HostelApplication::where('school_id', auth()->user()->school_id)
            ->orderBy('created_at', 'desc')
            ->get();

        return view('warden.hostel_applications.list', compact('applications'));
    }

    public function approveApplication($id)
    {
        $application = HostelApplication::where('school_id', auth()->user()->school_id)->findOrFail($id);

        $room = HostelRoom::where('school_id', auth()->user()->school_id)->find($application->room_id);
        if ($room->occupied >= $room->capacity) {
            return redirect()->back()->with('error', 'Room is already full');
        }

        $application->accepted_at = now();
        $application->status      = 1;
        $application->save();

        $room->occupied += 1;
        $room->save();

        $this->createRoomAllocation($application->student_id, $room->id);

        return redirect()->back()->with('success', 'Application approved successfully');
    }

    private function createRoomAllocation($studentId, $roomId)
    {
        HostelRoomAllocation::create([
            'student_id'   => $studentId,
            'room_id'      => $roomId,
            'allocated_on' => now(),
            'status'       => 1,
            'school_id'    => auth()->user()->school_id,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    public function rejectApplication($id)
    {
        $application = HostelApplication::where('school_id', auth()->user()->school_id)->findOrFail($id);

        if ($application->status == 1) {
            $room = HostelRoom::where('school_id', auth()->user()->school_id)->find($application->room_id);

            if ($room && $room->occupied > 0) {
                $room->occupied -= 1;
                $room->save();
            }

            HostelRoomAllocation::where('student_id', $application->student_id)
                ->where('room_id', $application->room_id)
                ->where('school_id', auth()->user()->school_id)
                ->delete();

            // Delete created fee records
            HostelFee::where('student_id', $application->student_id)
                ->where('room_id', $application->room_id)
                ->where('school_id', auth()->user()->school_id)
                ->where('status', 0) // only unpaid fees
                ->delete();
        }

        $application->status = 2;
        $application->save();

        return redirect()->back()->with('success', 'Application rejected successfully');
    }
    public function hostelFees(Request $request)
    {
        $search = $request->search ?? "";

        $studentsId = HostelApplication::where('status', 1)
            ->where('school_id', auth()->user()->school_id)
            ->distinct()
            ->pluck('student_id')
            ->toArray();

        $studentsQuery = User::whereIn('id', $studentsId)
            ->where('school_id', auth()->user()->school_id);

        if ($search != "") {

            $controller = new \App\Http\Controllers\CommonController();

            $studentsIdsFiltered = [];

            foreach ($studentsId as $sid) {

                $details = $controller->get_student_details_by_id($sid);

                // FIXED — GET STUDENT NAME CORRECTLY
                $studentObj  = User::find($sid);
                $studentName = strtolower($studentObj->name ?? '');

                $className = strtolower($details['class_name'] ?? '');

                if (
                    str_contains($studentName, strtolower($search)) ||
                    str_contains($className, strtolower($search))
                ) {
                    $studentsIdsFiltered[] = $sid;
                }
            }

            $studentsQuery->whereIn('id', $studentsIdsFiltered);
        }

        $students = $studentsQuery->paginate(20);

        return view('warden.hostel_fee_manager.list', compact('students', 'search'));
    }
    public function acceptOfflinePaymentHostel($id)
    {
        $fee = HostelFee::where('status', 0)->where('school_id', auth()->user()->school_id)->findOrFail($id);

        $fee->status = 1;
        $fee->save();

        StatusChangeAudit::hostelPayment($fee, 0, 'accepted');
        return redirect()->back()->with('message', get_phrase('Offline payment accepted successfully.'));
    }
    public function rejectOfflinePaymentHostel($id)
    {
        $fee = HostelFee::where('status', 0)->where('school_id', auth()->user()->school_id)->findOrFail($id);

        $fee->status = 2;
        $fee->save();

        StatusChangeAudit::hostelPayment($fee, 0, 'rejected');
        return redirect()->back()->with('message', get_phrase('Offline payment rejected successfully.'));
    }
    public function offlinePaymentList()
    {
        $pendingPayments = HostelFee::where('school_id', auth()->user()->school_id)
            ->where('status', 0)
            ->orderBy('fee_payment_date', 'DESC')
            ->paginate(20);

        return view('warden.hostel_fee_manager.offline_payments', compact('pendingPayments'));
    }

    public function profile()
    {
        return view('warden.profile.view');
    }

    public function profile_update(Request $request)
    {
        $data['name']        = $request->name;
        $data['email']       = $request->email;
        // Security Phase 2F: a self-service profile edit must not claim another account's login email.
        if (User::where('email', $request->email)->where('id', '!=', auth()->user()->id)->exists()) {
            return redirect()->back()->with('error', 'Email was already taken.');
        }
        $data['designation'] = $request->designation;

        $user_info['birthday'] = strtotime($request->eDefaultDateRange);
        $user_info['gender']   = $request->gender;
        $user_info['phone']    = $request->phone;
        $user_info['address']  = $request->address;

        if (empty($request->photo)) {
            $user_info['photo'] = $request->old_photo;
        } else {
            $file_name = ProfilePhoto::store($request->photo);
            if ($file_name === null) {
                return redirect()->back()->with('error', 'Profile photo must be a JPG or PNG image of at most 4 MB.');
            }
            $user_info['photo'] = $file_name;
        }

        $data['user_information'] = json_encode($user_info);

        User::where('id', auth()->user()->id)->update($data);

        return redirect(route('warden.profile'))->with('message', get_phrase('Profile info updated successfully'));
    }

    public function user_language(Request $request)
    {
        $data['language'] = $request->language;
        User::where('id', auth()->user()->id)->update($data);

        return redirect()->back()->with('message', 'You have successfully transleted language.');
    }

    public function password($action_type = null, Request $request)
    {

        if ($action_type == 'update') {

            if ($request->new_password != $request->confirm_password) {
                return back()->with("error", "Confirm Password Doesn't match!");
            }

            if (! Hash::check($request->old_password, auth()->user()->password)) {
                return back()->with("error", "Current Password Doesn't match!");
            }

            $data['password'] = Hash::make($request->new_password);
            User::where('id', auth()->user()->id)->update($data);

            return redirect(route('warden.password', 'edit'))->with('message', get_phrase('Password changed successfully'));
        }

        return view('warden.profile.password');
    }

    public function noticeboardList()
    {

        $notices = Noticeboard::get()->where('school_id', auth()->user()->school_id);

        $events = [];

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
                $info = [
                    'id'    => $notice['id'],
                    'title' => $notice['notice_title'],
                    'start' => date('Y-m-d', strtotime($notice['start_date'])),
                ];
            } else if ($notice['start_time'] != "" && ($notice['end_date'] == "" && $notice['end_time'] == "")) {
                $info = [
                    'id'    => $notice['id'],
                    'title' => $notice['notice_title'],
                    'start' => date('Y-m-d', strtotime($notice['start_date'])) . 'T' . $notice['start_time'],
                ];
            } else if ($notice['end_date'] != "" && ($notice['start_time'] == "" && $notice['end_time'] == "")) {
                $info = [
                    'id'    => $notice['id'],
                    'title' => $notice['notice_title'],
                    'start' => date('Y-m-d', strtotime($notice['start_date'])),
                    'end'   => $end_date,
                ];
            } else if ($notice['end_date'] != "" && $notice['start_time'] != "" && $notice['end_time'] != "") {
                $info = [
                    'id'    => $notice['id'],
                    'title' => $notice['notice_title'],
                    'start' => date('Y-m-d', strtotime($notice['start_date'])) . 'T' . $notice['start_time'],
                    'end'   => date('Y-m-d', strtotime($notice['end_date'])) . 'T' . $notice['end_time'],
                ];
            } else {
                $info = [
                    'id'    => $notice['id'],
                    'title' => $notice['notice_title'],
                    'start' => date('Y-m-d', strtotime($notice['start_date'])),
                ];
            }
            array_push($events, $info);
        }

        $events = json_encode($events);

        return view('warden.noticeboard.noticeboard', ['events' => $events]);
    }

    public function editNoticeboard($id = "")
    {
        $notice = Noticeboard::where('school_id', auth()->user()->school_id)->findOrFail($id);
        return view('warden.noticeboard.edit', ['notice' => $notice]);
    }

    /**
     * Show the event list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function eventList(Request $request)
    {
        $search = $request['search'] ?? "";

        if ($search != "") {

            $events = FrontendEvent::where(function ($query) use ($search) {
                $query->where('title', 'LIKE', "%{$search}%");
            })->paginate(10);

        } else {
            $events = FrontendEvent::where('school_id', auth()->user()->school_id)->paginate(10);
        }

        return view('warden.events.events', compact('events', 'search'));
    }

    //  Message

    public function allMessage(Request $request, $id)
    {
        return app(\App\Http\Controllers\MessagingController::class)->legacyShow($request, $id);
    }

    public function messagethrades($id)
    {
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

}
