<?php 

use App\Models\Routine;
use App\Models\Subject;
use App\Models\User;
use App\Models\ClassRoom;
use App\Models\Session;

$active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

$routines = Routine::where(['class_id' => $class_id, 'section_id' => $section_id, 'session_id' => $active_session,'school_id' => auth()->user()->school_id])->get();

?>

@if(count($routines) > 0)
<div class="export position-relative">
  <button class="eBtn-3 dropdown-toggle float-end" type="button" id="defaultDropdown" data-bs-toggle="dropdown" data-bs-auto-close="true" aria-expanded="false">
    <span class="pr-10">
      <svg xmlns="http://www.w3.org/2000/svg" width="12.31" height="10.77" viewBox="0 0 10.771 12.31">
        <path id="arrow-right-from-bracket-solid" d="M3.847,1.539H2.308a.769.769,0,0,0-.769.769V8.463a.769.769,0,0,0,.769.769H3.847a.769.769,0,0,1,0,1.539H2.308A2.308,2.308,0,0,1,0,8.463V2.308A2.308,2.308,0,0,1,2.308,0H3.847a.769.769,0,1,1,0,1.539Zm8.237,4.39L9.007,9.007A.769.769,0,0,1,7.919,7.919L9.685,6.155H4.616a.769.769,0,0,1,0-1.539H9.685L7.92,2.852A.769.769,0,0,1,9.008,1.764l3.078,3.078A.77.77,0,0,1,12.084,5.929Z" transform="translate(0 12.31) rotate(-90)" fill="#00a3ff"></path>
      </svg>
    </span>
    {{ get_phrase('Export') }}
  </button>
  <ul class="dropdown-menu dropdown-menu-end eDropdown-menu-2">
    <li>
        <a class="dropdown-item" id="pdf" href="javascript:;" onclick="generatePDF()">{{ get_phrase('PDF') }}</a>
    </li>
    <li>
        <a class="dropdown-item" id="print" href="javascript:;" onclick="ePrintDiv('routine_report')">{{ get_phrase('Print') }}</a>
    </li>
  </ul>
</div>
<ul class="class_routine_content" id="routine_report">
  <li class="classRoutines-item d-flex align-items-center">
    <h4 class="title">{{ get_phrase('Saturday') }}</h4>
    <div class="classTime-items d-flex">
      <?php $perday_routines = Routine::where(['class_id' => $class_id, 'section_id' => $section_id, 'session_id' => $active_session,'school_id' => auth()->user()->school_id, 'day' => 'saturday'])->get();
      foreach($perday_routines as $perday_routine) { ?>
      <div class="classTime-item">
        <?php $subject = Subject::find($perday_routine['subject_id']); ?>
        <h4 class="subjectName">{{ $subject->name }}</h4>
        <ul class="classDetails">
          <li
            class="classDetails-info d-flex align-items-center"
          >
            <div class="icon">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="16.03"
                height="16.03"
                viewBox="0 0 11.777 11.777"
              >
                <path
                  id="schedule_FILL0_wght600_GRAD0_opsz24"
                  d="M8.679,9.634a.605.605,0,0,0,.464.2.674.674,0,0,0,.492-.211.679.679,0,0,0,0-.956L8.088,7.119V5.306A.641.641,0,0,0,7.9,4.834a.658.658,0,0,0-.485-.189.643.643,0,0,0-.485.2.662.662,0,0,0-.19.478V7.372a.787.787,0,0,0,.056.3.7.7,0,0,0,.155.239ZM7.414,13.3a5.724,5.724,0,0,1-2.3-.464,5.909,5.909,0,0,1-3.12-3.12,5.724,5.724,0,0,1-.464-2.3,5.724,5.724,0,0,1,.464-2.3,5.909,5.909,0,0,1,3.12-3.12,5.724,5.724,0,0,1,2.3-.464,5.724,5.724,0,0,1,2.3.464,5.9,5.9,0,0,1,3.12,3.12,5.724,5.724,0,0,1,.464,2.3,5.724,5.724,0,0,1-.464,2.3,5.9,5.9,0,0,1-3.12,3.12A5.724,5.724,0,0,1,7.414,13.3Zm0-5.889Zm0,4.4a4.253,4.253,0,0,0,3.113-1.279,4.234,4.234,0,0,0,1.286-3.12,4.234,4.234,0,0,0-1.286-3.12A4.253,4.253,0,0,0,7.414,3.015,4.251,4.251,0,0,0,4.3,4.294a4.233,4.233,0,0,0-1.286,3.12A4.233,4.233,0,0,0,4.3,10.534,4.251,4.251,0,0,0,7.414,11.813Z"
                  transform="translate(-1.525 -1.525)"
                  fill="#00A3FF"
                />
              </svg>
            </div>
            <p class="info">{{ $perday_routine['starting_hour'].':'.$perday_routine['starting_minute'].' - '.$perday_routine['ending_hour'].':'.$perday_routine['ending_minute'] }}</p>
          </li>
          <li
            class="classDetails-info d-flex align-items-center"
          >
            <div class="icon">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="11.33"
                height="14.71"
                viewBox="0 0 13.275 14.944"
              >
                <g
                  id="user_icon"
                  data-name="user icon"
                  transform="translate(-1368.531 -147.15)"
                >
                  <g
                    id="Ellipse_1"
                    data-name="Ellipse 1"
                    transform="translate(1370.609 147.15)"
                    fill="none"
                    stroke="#00A3FF"
                    stroke-width="2"
                  >
                    <ellipse
                      cx="4.576"
                      cy="4.435"
                      rx="4.576"
                      ry="4.435"
                      stroke="none"
                    />
                    <ellipse
                      cx="4.576"
                      cy="4.435"
                      rx="3.576"
                      ry="3.435"
                      fill="none"
                    />
                  </g>
                  <path
                    id="Path_41"
                    data-name="Path 41"
                    d="M1485.186,311.087a5.818,5.818,0,0,1,5.856-4.283,5.534,5.534,0,0,1,5.466,4.283"
                    transform="translate(-115.686 -149.241)"
                    fill="none"
                    stroke="#00A3FF"
                    stroke-width="2"
                  />
                </g>
              </svg>
            </div>
            <?php $teacher = User::find($perday_routine['teacher_id']); ?>
            <p class="info">{{ $teacher->name }}</p>
          </li>
          <li
            class="classDetails-info d-flex align-items-center"
          >
            <div class="icon">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="18.641"
                height="16.589"
                viewBox="0 0 18.641 16.589"
              >
                <path
                  id="house_FILL0_wght400_GRAD0_opsz48"
                  d="M13.263,23.089H8.311a.72.72,0,0,1-.743-.743v-7.18H5.563a.35.35,0,0,1-.347-.235.329.329,0,0,1,.1-.409L14.006,6.7a.718.718,0,0,1,.99,0l4.457,3.937V8.481a.72.72,0,0,1,.743-.743h.5a.72.72,0,0,1,.743.743v3.986l2.253,2.055a.363.363,0,0,1-.248.644H21.434v7.18a.72.72,0,0,1-.743.743H15.739V17.146H13.263ZM9.054,21.6h2.724V16.4a.72.72,0,0,1,.743-.743h3.961a.72.72,0,0,1,.743.743v5.2h2.724V13.16L14.5,8.208,9.054,13.16ZM12.4,12.937h4.209a1.689,1.689,0,0,0-.631-1.349,2.3,2.3,0,0,0-2.946,0A1.689,1.689,0,0,0,12.4,12.937Zm.124,2.724h0Z"
                  transform="translate(-5.183 -6.5)"
                  fill="#00a3ff"
                />
              </svg>
            </div>
            <?php $class_room = ClassRoom::find($perday_routine['room_id']); ?>
            <p class="info">{{ $class_room->name }}</p>
          </li>
        </ul>
      </div>
      <?php } ?>
    </div>
  </li>
  <li class="classRoutines-item d-flex align-items-center">
    <h4 class="title">{{ get_phrase('Sunday') }}</h4>
    <div class="classTime-items d-flex">
      <?php $perday_routines = Routine::where(['class_id' => $class_id, 'section_id' => $section_id, 'session_id' => $active_session,'school_id' => auth()->user()->school_id, 'day' => 'sunday'])->get();
      foreach($perday_routines as $perday_routine) { ?>
      <div class="classTime-item">
        <?php $subject = Subject::find($perday_routine['subject_id']); ?>
        <h4 class="subjectName">{{ $subject->name }}</h4>
        <ul class="classDetails">
          <li
            class="classDetails-info d-flex align-items-center"
          >
            <div class="icon">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="16.03"
                height="16.03"
                viewBox="0 0 11.777 11.777"
              >
                <path
                  id="schedule_FILL0_wght600_GRAD0_opsz24"
                  d="M8.679,9.634a.605.605,0,0,0,.464.2.674.674,0,0,0,.492-.211.679.679,0,0,0,0-.956L8.088,7.119V5.306A.641.641,0,0,0,7.9,4.834a.658.658,0,0,0-.485-.189.643.643,0,0,0-.485.2.662.662,0,0,0-.19.478V7.372a.787.787,0,0,0,.056.3.7.7,0,0,0,.155.239ZM7.414,13.3a5.724,5.724,0,0,1-2.3-.464,5.909,5.909,0,0,1-3.12-3.12,5.724,5.724,0,0,1-.464-2.3,5.724,5.724,0,0,1,.464-2.3,5.909,5.909,0,0,1,3.12-3.12,5.724,5.724,0,0,1,2.3-.464,5.724,5.724,0,0,1,2.3.464,5.9,5.9,0,0,1,3.12,3.12,5.724,5.724,0,0,1,.464,2.3,5.724,5.724,0,0,1-.464,2.3,5.9,5.9,0,0,1-3.12,3.12A5.724,5.724,0,0,1,7.414,13.3Zm0-5.889Zm0,4.4a4.253,4.253,0,0,0,3.113-1.279,4.234,4.234,0,0,0,1.286-3.12,4.234,4.234,0,0,0-1.286-3.12A4.253,4.253,0,0,0,7.414,3.015,4.251,4.251,0,0,0,4.3,4.294a4.233,4.233,0,0,0-1.286,3.12A4.233,4.233,0,0,0,4.3,10.534,4.251,4.251,0,0,0,7.414,11.813Z"
                  transform="translate(-1.525 -1.525)"
                  fill="#00A3FF"
                />
              </svg>
            </div>
            <p class="info">{{ $perday_routine['starting_hour'].':'.$perday_routine['starting_minute'].' - '.$perday_routine['ending_hour'].':'.$perday_routine['ending_minute'] }}</p>
          </li>
          <li
            class="classDetails-info d-flex align-items-center"
          >
            <div class="icon">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="11.33"
                height="14.71"
                viewBox="0 0 13.275 14.944"
              >
                <g
                  id="user_icon"
                  data-name="user icon"
                  transform="translate(-1368.531 -147.15)"
                >
                  <g
                    id="Ellipse_1"
                    data-name="Ellipse 1"
                    transform="translate(1370.609 147.15)"
                    fill="none"
                    stroke="#00A3FF"
                    stroke-width="2"
                  >
                    <ellipse
                      cx="4.576"
                      cy="4.435"
                      rx="4.576"
                      ry="4.435"
                      stroke="none"
                    />
                    <ellipse
                      cx="4.576"
                      cy="4.435"
                      rx="3.576"
                      ry="3.435"
                      fill="none"
                    />
                  </g>
                  <path
                    id="Path_41"
                    data-name="Path 41"
                    d="M1485.186,311.087a5.818,5.818,0,0,1,5.856-4.283,5.534,5.534,0,0,1,5.466,4.283"
                    transform="translate(-115.686 -149.241)"
                    fill="none"
                    stroke="#00A3FF"
                    stroke-width="2"
                  />
                </g>
              </svg>
            </div>
            <?php $teacher = User::find($perday_routine['teacher_id']); ?>
            <p class="info">{{ $teacher->name }}</p>
          </li>
          <li
            class="classDetails-info d-flex align-items-center"
          >
            <div class="icon">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="18.641"
                height="16.589"
                viewBox="0 0 18.641 16.589"
              >
                <path
                  id="house_FILL0_wght400_GRAD0_opsz48"
                  d="M13.263,23.089H8.311a.72.72,0,0,1-.743-.743v-7.18H5.563a.35.35,0,0,1-.347-.235.329.329,0,0,1,.1-.409L14.006,6.7a.718.718,0,0,1,.99,0l4.457,3.937V8.481a.72.72,0,0,1,.743-.743h.5a.72.72,0,0,1,.743.743v3.986l2.253,2.055a.363.363,0,0,1-.248.644H21.434v7.18a.72.72,0,0,1-.743.743H15.739V17.146H13.263ZM9.054,21.6h2.724V16.4a.72.72,0,0,1,.743-.743h3.961a.72.72,0,0,1,.743.743v5.2h2.724V13.16L14.5,8.208,9.054,13.16ZM12.4,12.937h4.209a1.689,1.689,0,0,0-.631-1.349,2.3,2.3,0,0,0-2.946,0A1.689,1.689,0,0,0,12.4,12.937Zm.124,2.724h0Z"
                  transform="translate(-5.183 -6.5)"
                  fill="#00a3ff"
                />
              </svg>
            </div>
            <?php $class_room = ClassRoom::find($perday_routine['room_id']); ?>
            <p class="info">{{ $class_room->name }}</p>
          </li>
        </ul>
      </div>
      <?php } ?>
    </div>
  </li>
  <li class="classRoutines-item d-flex align-items-center">
    <h4 class="title">{{ get_phrase('Monday') }}</h4>
    <div class="classTime-items d-flex">
      <?php $perday_routines = Routine::where(['class_id' => $class_id, 'section_id' => $section_id, 'session_id' => $active_session,'school_id' => auth()->user()->school_id, 'day' => 'monday'])->get();
      foreach($perday_routines as $perday_routine) { ?>
      <div class="classTime-item">
        <?php $subject = Subject::find($perday_routine['subject_id']); ?>
        <h4 class="subjectName">{{ $subject->name }}</h4>
        <ul class="classDetails">
          <li
            class="classDetails-info d-flex align-items-center"
          >
            <div class="icon">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="16.03"
                height="16.03"
                viewBox="0 0 11.777 11.777"
              >
                <path
                  id="schedule_FILL0_wght600_GRAD0_opsz24"
                  d="M8.679,9.634a.605.605,0,0,0,.464.2.674.674,0,0,0,.492-.211.679.679,0,0,0,0-.956L8.088,7.119V5.306A.641.641,0,0,0,7.9,4.834a.658.658,0,0,0-.485-.189.643.643,0,0,0-.485.2.662.662,0,0,0-.19.478V7.372a.787.787,0,0,0,.056.3.7.7,0,0,0,.155.239ZM7.414,13.3a5.724,5.724,0,0,1-2.3-.464,5.909,5.909,0,0,1-3.12-3.12,5.724,5.724,0,0,1-.464-2.3,5.724,5.724,0,0,1,.464-2.3,5.909,5.909,0,0,1,3.12-3.12,5.724,5.724,0,0,1,2.3-.464,5.724,5.724,0,0,1,2.3.464,5.9,5.9,0,0,1,3.12,3.12,5.724,5.724,0,0,1,.464,2.3,5.724,5.724,0,0,1-.464,2.3,5.9,5.9,0,0,1-3.12,3.12A5.724,5.724,0,0,1,7.414,13.3Zm0-5.889Zm0,4.4a4.253,4.253,0,0,0,3.113-1.279,4.234,4.234,0,0,0,1.286-3.12,4.234,4.234,0,0,0-1.286-3.12A4.253,4.253,0,0,0,7.414,3.015,4.251,4.251,0,0,0,4.3,4.294a4.233,4.233,0,0,0-1.286,3.12A4.233,4.233,0,0,0,4.3,10.534,4.251,4.251,0,0,0,7.414,11.813Z"
                  transform="translate(-1.525 -1.525)"
                  fill="#00A3FF"
                />
              </svg>
            </div>
            <p class="info">{{ $perday_routine['starting_hour'].':'.$perday_routine['starting_minute'].' - '.$perday_routine['ending_hour'].':'.$perday_routine['ending_minute'] }}</p>
          </li>
          <li
            class="classDetails-info d-flex align-items-center"
          >
            <div class="icon">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="11.33"
                height="14.71"
                viewBox="0 0 13.275 14.944"
              >
                <g
                  id="user_icon"
                  data-name="user icon"
                  transform="translate(-1368.531 -147.15)"
                >
                  <g
                    id="Ellipse_1"
                    data-name="Ellipse 1"
                    transform="translate(1370.609 147.15)"
                    fill="none"
                    stroke="#00A3FF"
                    stroke-width="2"
                  >
                    <ellipse
                      cx="4.576"
                      cy="4.435"
                      rx="4.576"
                      ry="4.435"
                      stroke="none"
                    />
                    <ellipse
                      cx="4.576"
                      cy="4.435"
                      rx="3.576"
                      ry="3.435"
                      fill="none"
                    />
                  </g>
                  <path
                    id="Path_41"
                    data-name="Path 41"
                    d="M1485.186,311.087a5.818,5.818,0,0,1,5.856-4.283,5.534,5.534,0,0,1,5.466,4.283"
                    transform="translate(-115.686 -149.241)"
                    fill="none"
                    stroke="#00A3FF"
                    stroke-width="2"
                  />
                </g>
              </svg>
            </div>
            <?php $teacher = User::find($perday_routine['teacher_id']); ?>
            <p class="info">{{ $teacher->name }}</p>
          </li>
          <li
            class="classDetails-info d-flex align-items-center"
          >
            <div class="icon">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="18.641"
                height="16.589"
                viewBox="0 0 18.641 16.589"
              >
                <path
                  id="house_FILL0_wght400_GRAD0_opsz48"
                  d="M13.263,23.089H8.311a.72.72,0,0,1-.743-.743v-7.18H5.563a.35.35,0,0,1-.347-.235.329.329,0,0,1,.1-.409L14.006,6.7a.718.718,0,0,1,.99,0l4.457,3.937V8.481a.72.72,0,0,1,.743-.743h.5a.72.72,0,0,1,.743.743v3.986l2.253,2.055a.363.363,0,0,1-.248.644H21.434v7.18a.72.72,0,0,1-.743.743H15.739V17.146H13.263ZM9.054,21.6h2.724V16.4a.72.72,0,0,1,.743-.743h3.961a.72.72,0,0,1,.743.743v5.2h2.724V13.16L14.5,8.208,9.054,13.16ZM12.4,12.937h4.209a1.689,1.689,0,0,0-.631-1.349,2.3,2.3,0,0,0-2.946,0A1.689,1.689,0,0,0,12.4,12.937Zm.124,2.724h0Z"
                  transform="translate(-5.183 -6.5)"
                  fill="#00a3ff"
                />
              </svg>
            </div>
            <?php $class_room = ClassRoom::find($perday_routine['room_id']); ?>
            <p class="info">{{ $class_room->name }}</p>
          </li>
        </ul>
      </div>
    <?php } ?>
    </div>
  </li>
  <li class="classRoutines-item d-flex align-items-center">
    <h4 class="title">{{ get_phrase('Tuesday') }}</h4>
    <div class="classTime-items d-flex">
      <?php $perday_routines = Routine::where(['class_id' => $class_id, 'section_id' => $section_id, 'session_id' => $active_session,'school_id' => auth()->user()->school_id, 'day' => 'tuesday'])->get();
      foreach($perday_routines as $perday_routine) { ?>
      <div class="classTime-item">
        <?php $subject = Subject::find($perday_routine['subject_id']); ?>
        <h4 class="subjectName">{{ $subject->name }}</h4>
        <ul class="classDetails">
          <li
            class="classDetails-info d-flex align-items-center"
          >
            <div class="icon">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="16.03"
                height="16.03"
                viewBox="0 0 11.777 11.777"
              >
                <path
                  id="schedule_FILL0_wght600_GRAD0_opsz24"
                  d="M8.679,9.634a.605.605,0,0,0,.464.2.674.674,0,0,0,.492-.211.679.679,0,0,0,0-.956L8.088,7.119V5.306A.641.641,0,0,0,7.9,4.834a.658.658,0,0,0-.485-.189.643.643,0,0,0-.485.2.662.662,0,0,0-.19.478V7.372a.787.787,0,0,0,.056.3.7.7,0,0,0,.155.239ZM7.414,13.3a5.724,5.724,0,0,1-2.3-.464,5.909,5.909,0,0,1-3.12-3.12,5.724,5.724,0,0,1-.464-2.3,5.724,5.724,0,0,1,.464-2.3,5.909,5.909,0,0,1,3.12-3.12,5.724,5.724,0,0,1,2.3-.464,5.724,5.724,0,0,1,2.3.464,5.9,5.9,0,0,1,3.12,3.12,5.724,5.724,0,0,1,.464,2.3,5.724,5.724,0,0,1-.464,2.3,5.9,5.9,0,0,1-3.12,3.12A5.724,5.724,0,0,1,7.414,13.3Zm0-5.889Zm0,4.4a4.253,4.253,0,0,0,3.113-1.279,4.234,4.234,0,0,0,1.286-3.12,4.234,4.234,0,0,0-1.286-3.12A4.253,4.253,0,0,0,7.414,3.015,4.251,4.251,0,0,0,4.3,4.294a4.233,4.233,0,0,0-1.286,3.12A4.233,4.233,0,0,0,4.3,10.534,4.251,4.251,0,0,0,7.414,11.813Z"
                  transform="translate(-1.525 -1.525)"
                  fill="#00A3FF"
                />
              </svg>
            </div>
            <p class="info">{{ $perday_routine['starting_hour'].':'.$perday_routine['starting_minute'].' - '.$perday_routine['ending_hour'].':'.$perday_routine['ending_minute'] }}</p>
          </li>
          <li
            class="classDetails-info d-flex align-items-center"
          >
            <div class="icon">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="11.33"
                height="14.71"
                viewBox="0 0 13.275 14.944"
              >
                <g
                  id="user_icon"
                  data-name="user icon"
                  transform="translate(-1368.531 -147.15)"
                >
                  <g
                    id="Ellipse_1"
                    data-name="Ellipse 1"
                    transform="translate(1370.609 147.15)"
                    fill="none"
                    stroke="#00A3FF"
                    stroke-width="2"
                  >
                    <ellipse
                      cx="4.576"
                      cy="4.435"
                      rx="4.576"
                      ry="4.435"
                      stroke="none"
                    />
                    <ellipse
                      cx="4.576"
                      cy="4.435"
                      rx="3.576"
                      ry="3.435"
                      fill="none"
                    />
                  </g>
                  <path
                    id="Path_41"
                    data-name="Path 41"
                    d="M1485.186,311.087a5.818,5.818,0,0,1,5.856-4.283,5.534,5.534,0,0,1,5.466,4.283"
                    transform="translate(-115.686 -149.241)"
                    fill="none"
                    stroke="#00A3FF"
                    stroke-width="2"
                  />
                </g>
              </svg>
            </div>
            <?php $teacher = User::find($perday_routine['teacher_id']); ?>
            <p class="info">{{ $teacher->name }}</p>
          </li>
          <li
            class="classDetails-info d-flex align-items-center"
          >
            <div class="icon">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="18.641"
                height="16.589"
                viewBox="0 0 18.641 16.589"
              >
                <path
                  id="house_FILL0_wght400_GRAD0_opsz48"
                  d="M13.263,23.089H8.311a.72.72,0,0,1-.743-.743v-7.18H5.563a.35.35,0,0,1-.347-.235.329.329,0,0,1,.1-.409L14.006,6.7a.718.718,0,0,1,.99,0l4.457,3.937V8.481a.72.72,0,0,1,.743-.743h.5a.72.72,0,0,1,.743.743v3.986l2.253,2.055a.363.363,0,0,1-.248.644H21.434v7.18a.72.72,0,0,1-.743.743H15.739V17.146H13.263ZM9.054,21.6h2.724V16.4a.72.72,0,0,1,.743-.743h3.961a.72.72,0,0,1,.743.743v5.2h2.724V13.16L14.5,8.208,9.054,13.16ZM12.4,12.937h4.209a1.689,1.689,0,0,0-.631-1.349,2.3,2.3,0,0,0-2.946,0A1.689,1.689,0,0,0,12.4,12.937Zm.124,2.724h0Z"
                  transform="translate(-5.183 -6.5)"
                  fill="#00a3ff"
                />
              </svg>
            </div>
            <?php $class_room = ClassRoom::find($perday_routine['room_id']); ?>
            <p class="info">{{ $class_room->name }}</p>
          </li>
        </ul>
      </div>
      <?php } ?>
    </div>
  </li>
  <li class="classRoutines-item d-flex align-items-center">
    <h4 class="title">{{ get_phrase('Wednesday') }}</h4>
    <div class="classTime-items d-flex">
      <?php $perday_routines = Routine::where(['class_id' => $class_id, 'section_id' => $section_id, 'session_id' => $active_session,'school_id' => auth()->user()->school_id, 'day' => 'wednesday'])->get();
      foreach($perday_routines as $perday_routine) { ?>
      <div class="classTime-item">
        <?php $subject = Subject::find($perday_routine['subject_id']); ?>
        <h4 class="subjectName">{{ $subject->name }}</h4>
        <ul class="classDetails">
          <li
            class="classDetails-info d-flex align-items-center"
          >
            <div class="icon">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="16.03"
                height="16.03"
                viewBox="0 0 11.777 11.777"
              >
                <path
                  id="schedule_FILL0_wght600_GRAD0_opsz24"
                  d="M8.679,9.634a.605.605,0,0,0,.464.2.674.674,0,0,0,.492-.211.679.679,0,0,0,0-.956L8.088,7.119V5.306A.641.641,0,0,0,7.9,4.834a.658.658,0,0,0-.485-.189.643.643,0,0,0-.485.2.662.662,0,0,0-.19.478V7.372a.787.787,0,0,0,.056.3.7.7,0,0,0,.155.239ZM7.414,13.3a5.724,5.724,0,0,1-2.3-.464,5.909,5.909,0,0,1-3.12-3.12,5.724,5.724,0,0,1-.464-2.3,5.724,5.724,0,0,1,.464-2.3,5.909,5.909,0,0,1,3.12-3.12,5.724,5.724,0,0,1,2.3-.464,5.724,5.724,0,0,1,2.3.464,5.9,5.9,0,0,1,3.12,3.12,5.724,5.724,0,0,1,.464,2.3,5.724,5.724,0,0,1-.464,2.3,5.9,5.9,0,0,1-3.12,3.12A5.724,5.724,0,0,1,7.414,13.3Zm0-5.889Zm0,4.4a4.253,4.253,0,0,0,3.113-1.279,4.234,4.234,0,0,0,1.286-3.12,4.234,4.234,0,0,0-1.286-3.12A4.253,4.253,0,0,0,7.414,3.015,4.251,4.251,0,0,0,4.3,4.294a4.233,4.233,0,0,0-1.286,3.12A4.233,4.233,0,0,0,4.3,10.534,4.251,4.251,0,0,0,7.414,11.813Z"
                  transform="translate(-1.525 -1.525)"
                  fill="#00A3FF"
                />
              </svg>
            </div>
            <p class="info">{{ $perday_routine['starting_hour'].':'.$perday_routine['starting_minute'].' - '.$perday_routine['ending_hour'].':'.$perday_routine['ending_minute'] }}</p>
          </li>
          <li
            class="classDetails-info d-flex align-items-center"
          >
            <div class="icon">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="11.33"
                height="14.71"
                viewBox="0 0 13.275 14.944"
              >
                <g
                  id="user_icon"
                  data-name="user icon"
                  transform="translate(-1368.531 -147.15)"
                >
                  <g
                    id="Ellipse_1"
                    data-name="Ellipse 1"
                    transform="translate(1370.609 147.15)"
                    fill="none"
                    stroke="#00A3FF"
                    stroke-width="2"
                  >
                    <ellipse
                      cx="4.576"
                      cy="4.435"
                      rx="4.576"
                      ry="4.435"
                      stroke="none"
                    />
                    <ellipse
                      cx="4.576"
                      cy="4.435"
                      rx="3.576"
                      ry="3.435"
                      fill="none"
                    />
                  </g>
                  <path
                    id="Path_41"
                    data-name="Path 41"
                    d="M1485.186,311.087a5.818,5.818,0,0,1,5.856-4.283,5.534,5.534,0,0,1,5.466,4.283"
                    transform="translate(-115.686 -149.241)"
                    fill="none"
                    stroke="#00A3FF"
                    stroke-width="2"
                  />
                </g>
              </svg>
            </div>
            <?php $teacher = User::find($perday_routine['teacher_id']); ?>
            <p class="info">{{ $teacher->name }}</p>
          </li>
          <li
            class="classDetails-info d-flex align-items-center"
          >
            <div class="icon">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="18.641"
                height="16.589"
                viewBox="0 0 18.641 16.589"
              >
                <path
                  id="house_FILL0_wght400_GRAD0_opsz48"
                  d="M13.263,23.089H8.311a.72.72,0,0,1-.743-.743v-7.18H5.563a.35.35,0,0,1-.347-.235.329.329,0,0,1,.1-.409L14.006,6.7a.718.718,0,0,1,.99,0l4.457,3.937V8.481a.72.72,0,0,1,.743-.743h.5a.72.72,0,0,1,.743.743v3.986l2.253,2.055a.363.363,0,0,1-.248.644H21.434v7.18a.72.72,0,0,1-.743.743H15.739V17.146H13.263ZM9.054,21.6h2.724V16.4a.72.72,0,0,1,.743-.743h3.961a.72.72,0,0,1,.743.743v5.2h2.724V13.16L14.5,8.208,9.054,13.16ZM12.4,12.937h4.209a1.689,1.689,0,0,0-.631-1.349,2.3,2.3,0,0,0-2.946,0A1.689,1.689,0,0,0,12.4,12.937Zm.124,2.724h0Z"
                  transform="translate(-5.183 -6.5)"
                  fill="#00a3ff"
                />
              </svg>
            </div>
            <?php $class_room = ClassRoom::find($perday_routine['room_id']); ?>
            <p class="info">{{ $class_room->name }}</p>
          </li>
        </ul>
      </div>
      <?php } ?>
    </div>
  </li>
  <li class="classRoutines-item d-flex align-items-center">
    <h4 class="title">{{ get_phrase('Thursday') }}</h4>
    <div class="classTime-items d-flex">
      <?php $perday_routines = Routine::where(['class_id' => $class_id, 'section_id' => $section_id, 'session_id' => $active_session,'school_id' => auth()->user()->school_id, 'day' => 'thursday'])->get();
      foreach($perday_routines as $perday_routine) { ?>
      <div class="classTime-item">
        <?php $subject = Subject::find($perday_routine['subject_id']); ?>
        <h4 class="subjectName">{{ $subject->name }}</h4>
        <ul class="classDetails">
          <li
            class="classDetails-info d-flex align-items-center"
          >
            <div class="icon">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="16.03"
                height="16.03"
                viewBox="0 0 11.777 11.777"
              >
                <path
                  id="schedule_FILL0_wght600_GRAD0_opsz24"
                  d="M8.679,9.634a.605.605,0,0,0,.464.2.674.674,0,0,0,.492-.211.679.679,0,0,0,0-.956L8.088,7.119V5.306A.641.641,0,0,0,7.9,4.834a.658.658,0,0,0-.485-.189.643.643,0,0,0-.485.2.662.662,0,0,0-.19.478V7.372a.787.787,0,0,0,.056.3.7.7,0,0,0,.155.239ZM7.414,13.3a5.724,5.724,0,0,1-2.3-.464,5.909,5.909,0,0,1-3.12-3.12,5.724,5.724,0,0,1-.464-2.3,5.724,5.724,0,0,1,.464-2.3,5.909,5.909,0,0,1,3.12-3.12,5.724,5.724,0,0,1,2.3-.464,5.724,5.724,0,0,1,2.3.464,5.9,5.9,0,0,1,3.12,3.12,5.724,5.724,0,0,1,.464,2.3,5.724,5.724,0,0,1-.464,2.3,5.9,5.9,0,0,1-3.12,3.12A5.724,5.724,0,0,1,7.414,13.3Zm0-5.889Zm0,4.4a4.253,4.253,0,0,0,3.113-1.279,4.234,4.234,0,0,0,1.286-3.12,4.234,4.234,0,0,0-1.286-3.12A4.253,4.253,0,0,0,7.414,3.015,4.251,4.251,0,0,0,4.3,4.294a4.233,4.233,0,0,0-1.286,3.12A4.233,4.233,0,0,0,4.3,10.534,4.251,4.251,0,0,0,7.414,11.813Z"
                  transform="translate(-1.525 -1.525)"
                  fill="#00A3FF"
                />
              </svg>
            </div>
            <p class="info">{{ $perday_routine['starting_hour'].':'.$perday_routine['starting_minute'].' - '.$perday_routine['ending_hour'].':'.$perday_routine['ending_minute'] }}</p>
          </li>
          <li
            class="classDetails-info d-flex align-items-center"
          >
            <div class="icon">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="11.33"
                height="14.71"
                viewBox="0 0 13.275 14.944"
              >
                <g
                  id="user_icon"
                  data-name="user icon"
                  transform="translate(-1368.531 -147.15)"
                >
                  <g
                    id="Ellipse_1"
                    data-name="Ellipse 1"
                    transform="translate(1370.609 147.15)"
                    fill="none"
                    stroke="#00A3FF"
                    stroke-width="2"
                  >
                    <ellipse
                      cx="4.576"
                      cy="4.435"
                      rx="4.576"
                      ry="4.435"
                      stroke="none"
                    />
                    <ellipse
                      cx="4.576"
                      cy="4.435"
                      rx="3.576"
                      ry="3.435"
                      fill="none"
                    />
                  </g>
                  <path
                    id="Path_41"
                    data-name="Path 41"
                    d="M1485.186,311.087a5.818,5.818,0,0,1,5.856-4.283,5.534,5.534,0,0,1,5.466,4.283"
                    transform="translate(-115.686 -149.241)"
                    fill="none"
                    stroke="#00A3FF"
                    stroke-width="2"
                  />
                </g>
              </svg>
            </div>
            <?php $teacher = User::find($perday_routine['teacher_id']); ?>
            <p class="info">{{ $teacher->name }}</p>
          </li>
          <li
            class="classDetails-info d-flex align-items-center"
          >
            <div class="icon">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="18.641"
                height="16.589"
                viewBox="0 0 18.641 16.589"
              >
                <path
                  id="house_FILL0_wght400_GRAD0_opsz48"
                  d="M13.263,23.089H8.311a.72.72,0,0,1-.743-.743v-7.18H5.563a.35.35,0,0,1-.347-.235.329.329,0,0,1,.1-.409L14.006,6.7a.718.718,0,0,1,.99,0l4.457,3.937V8.481a.72.72,0,0,1,.743-.743h.5a.72.72,0,0,1,.743.743v3.986l2.253,2.055a.363.363,0,0,1-.248.644H21.434v7.18a.72.72,0,0,1-.743.743H15.739V17.146H13.263ZM9.054,21.6h2.724V16.4a.72.72,0,0,1,.743-.743h3.961a.72.72,0,0,1,.743.743v5.2h2.724V13.16L14.5,8.208,9.054,13.16ZM12.4,12.937h4.209a1.689,1.689,0,0,0-.631-1.349,2.3,2.3,0,0,0-2.946,0A1.689,1.689,0,0,0,12.4,12.937Zm.124,2.724h0Z"
                  transform="translate(-5.183 -6.5)"
                  fill="#00a3ff"
                />
              </svg>
            </div>
            <?php $class_room = ClassRoom::find($perday_routine['room_id']); ?>
            <p class="info">{{ $class_room->name }}</p>
          </li>
        </ul>
      </div>
      <?php } ?>
    </div>
  </li>
  <li class="classRoutines-item d-flex align-items-center">
    <h4 class="title">{{ get_phrase('Friday') }}</h4>
    <div class="classTime-items d-flex">
      <?php $perday_routines = Routine::where(['class_id' => $class_id, 'section_id' => $section_id, 'session_id' => $active_session,'school_id' => auth()->user()->school_id, 'day' => 'friday'])->get();
      foreach($perday_routines as $perday_routine) { ?>
      <div class="classTime-item">
        <?php $subject = Subject::find($perday_routine['subject_id']); ?>
        <h4 class="subjectName">{{ $subject->name }}</h4>
        <ul class="classDetails">
          <li
            class="classDetails-info d-flex align-items-center"
          >
            <div class="icon">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="16.03"
                height="16.03"
                viewBox="0 0 11.777 11.777"
              >
                <path
                  id="schedule_FILL0_wght600_GRAD0_opsz24"
                  d="M8.679,9.634a.605.605,0,0,0,.464.2.674.674,0,0,0,.492-.211.679.679,0,0,0,0-.956L8.088,7.119V5.306A.641.641,0,0,0,7.9,4.834a.658.658,0,0,0-.485-.189.643.643,0,0,0-.485.2.662.662,0,0,0-.19.478V7.372a.787.787,0,0,0,.056.3.7.7,0,0,0,.155.239ZM7.414,13.3a5.724,5.724,0,0,1-2.3-.464,5.909,5.909,0,0,1-3.12-3.12,5.724,5.724,0,0,1-.464-2.3,5.724,5.724,0,0,1,.464-2.3,5.909,5.909,0,0,1,3.12-3.12,5.724,5.724,0,0,1,2.3-.464,5.724,5.724,0,0,1,2.3.464,5.9,5.9,0,0,1,3.12,3.12,5.724,5.724,0,0,1,.464,2.3,5.724,5.724,0,0,1-.464,2.3,5.9,5.9,0,0,1-3.12,3.12A5.724,5.724,0,0,1,7.414,13.3Zm0-5.889Zm0,4.4a4.253,4.253,0,0,0,3.113-1.279,4.234,4.234,0,0,0,1.286-3.12,4.234,4.234,0,0,0-1.286-3.12A4.253,4.253,0,0,0,7.414,3.015,4.251,4.251,0,0,0,4.3,4.294a4.233,4.233,0,0,0-1.286,3.12A4.233,4.233,0,0,0,4.3,10.534,4.251,4.251,0,0,0,7.414,11.813Z"
                  transform="translate(-1.525 -1.525)"
                  fill="#00A3FF"
                />
              </svg>
            </div>
            <p class="info">{{ $perday_routine['starting_hour'].':'.$perday_routine['starting_minute'].' - '.$perday_routine['ending_hour'].':'.$perday_routine['ending_minute'] }}</p>
          </li>
          <li
            class="classDetails-info d-flex align-items-center"
          >
            <div class="icon">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="11.33"
                height="14.71"
                viewBox="0 0 13.275 14.944"
              >
                <g
                  id="user_icon"
                  data-name="user icon"
                  transform="translate(-1368.531 -147.15)"
                >
                  <g
                    id="Ellipse_1"
                    data-name="Ellipse 1"
                    transform="translate(1370.609 147.15)"
                    fill="none"
                    stroke="#00A3FF"
                    stroke-width="2"
                  >
                    <ellipse
                      cx="4.576"
                      cy="4.435"
                      rx="4.576"
                      ry="4.435"
                      stroke="none"
                    />
                    <ellipse
                      cx="4.576"
                      cy="4.435"
                      rx="3.576"
                      ry="3.435"
                      fill="none"
                    />
                  </g>
                  <path
                    id="Path_41"
                    data-name="Path 41"
                    d="M1485.186,311.087a5.818,5.818,0,0,1,5.856-4.283,5.534,5.534,0,0,1,5.466,4.283"
                    transform="translate(-115.686 -149.241)"
                    fill="none"
                    stroke="#00A3FF"
                    stroke-width="2"
                  />
                </g>
              </svg>
            </div>
            <?php $teacher = User::find($perday_routine['teacher_id']); ?>
            <p class="info">{{ $teacher->name }}</p>
          </li>
          <li
            class="classDetails-info d-flex align-items-center"
          >
            <div class="icon">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="18.641"
                height="16.589"
                viewBox="0 0 18.641 16.589"
              >
                <path
                  id="house_FILL0_wght400_GRAD0_opsz48"
                  d="M13.263,23.089H8.311a.72.72,0,0,1-.743-.743v-7.18H5.563a.35.35,0,0,1-.347-.235.329.329,0,0,1,.1-.409L14.006,6.7a.718.718,0,0,1,.99,0l4.457,3.937V8.481a.72.72,0,0,1,.743-.743h.5a.72.72,0,0,1,.743.743v3.986l2.253,2.055a.363.363,0,0,1-.248.644H21.434v7.18a.72.72,0,0,1-.743.743H15.739V17.146H13.263ZM9.054,21.6h2.724V16.4a.72.72,0,0,1,.743-.743h3.961a.72.72,0,0,1,.743.743v5.2h2.724V13.16L14.5,8.208,9.054,13.16ZM12.4,12.937h4.209a1.689,1.689,0,0,0-.631-1.349,2.3,2.3,0,0,0-2.946,0A1.689,1.689,0,0,0,12.4,12.937Zm.124,2.724h0Z"
                  transform="translate(-5.183 -6.5)"
                  fill="#00a3ff"
                />
              </svg>
            </div>
            <?php $class_room = ClassRoom::find($perday_routine['room_id']); ?>
            <p class="info">{{ $class_room->name }}</p>
          </li>
        </ul>
      </div>
      <?php } ?>
    </div>
  </li>
</ul>
@else
<div class="empty_box center">
    <img class="mb-3" width="150px" src="{{ asset('assets/images/empty_box.png') }}" />
    <br>
    <span class="">{{ get_phrase('No data found') }}</span>
</div>
@endif


<script type="text/javascript">

  "use strict";

    function ePrintDiv(ePrintDivId) {
        window.printReport(ePrintDivId);
    }

    function generatePDF() {

        // Choose the element that our invoice is rendered in.
        const element = document.getElementById("routine_report");

        // clone the element
        var clonedElement = element.cloneNode(true);

        // change display of cloned element 
        $(clonedElement).css("display", "block");

        // Choose the clonedElement and save the PDF for our user.

        var opt = {
          margin:       1,
          filename:     'routine-{{ 'class_id-'.$class_id.'-'.'section-'.$section_id }}.pdf',
          image:        { type: 'jpeg', quality: 0.98 },
          html2canvas:  { scale: 2 }
        };

        // New Promise-based usage:
        window.exportReportPdf(clonedElement, opt.filename);

        // remove cloned element
        clonedElement.remove();
    }
</script>