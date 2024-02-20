<?php
  $rel_pos="/hcc";
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/config.php";
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/auth.php";
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/api/scripts/patient.php";
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/api/scripts/doctor.php";
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/api/scripts/appointment.php";
  $db=pdo_init();
//Retrieve patient id
  $ptid = [];//placeholder var for regex
  preg_match('/\d+(?=$|\?|#)/', $_SERVER['REQUEST_URI'],$ptid);
//Logic to handle retrieving patient
  $outcome = Array("status"=>"error","message"=>[],"sql"=>[]);
  $appt_location_list = [];
  $appt_template_list = [];
  $booked_appt_list=[];
  $patient_contact_list = [];
  $doctor_contact_list = [];
  $outcome_temp = [];
  $message_list = [];
  
//placeholder html strings
  $html_strings=Array(
   "pmh_edit_outcome"=>"",
   "dem_edit_outcome"=>"",
   "book_appt_outcome"=>"",
   "create_template_outcome"=>"",
   "outcome_book_appt"=>"",
   "outcome_delete_template"=>"",
   "appt_book_list"=>"",
   "patient_contact_select"=>"",
   "doctor_contact_select"=>"",
   "appt_location_dropdown"=>"",
   "appt_template_dropdown"=>"",
   "message_list"=>""
  );

//extend outcome logic if patient not found
  if(count($ptid)>0 && intval($ptid[0])>0){
    $patient_id=intval($ptid[0]);
    $outcome=getPatientById($db,$patient_id);
    if($outcome['status']=="success"){
      if(count($outcome['sql'])==0){ //if no patients abort
        $outcome['status']='error';
        $outcome["message"] = ["Patient #".$patient_id." not found"];
      }else{ //else proceed
        $outcome['token'] = clean_patient_token($outcome['sql'][0]);// clean token
        //handle any pending post requests
        if(isset($_POST['form_action'])){
          switch($_POST['form_action']){ // picks which function to trigger based on hidden input
            case "edit_pmh": //basic input sanitisation
              if($authed_user["privilege"]>2 || ($authed_user["privilege"]>1 && is_doctor_responsible($db,$authed_user,$patient_id))){ 
              //delete patient if doctor responsible or has at least ug privs or has edit rights and is the responsible doctor
                $to_send=[];
                $to_set = Array("diagnosis","medical_history","treatment_plan");
                foreach ($to_set as $what){ //transfer accepted fields over from form
                  if(array_key_exists($what,$_POST) && strlen($_POST[$what]) > 0 && $_POST[$what]!==$outcome["token"]["pmh_config"][$what]){
                    $to_send[$what]=$_POST[$what];
                  }
                }
                $outcome_pmh_edit = editPatient($db,$patient_id,$to_send); //return the outcome for later use
                $html_strings["pmh_edit_outcome"] = "<span class='message-".$outcome_pmh_edit["status"]."'>".implode("<br>",$outcome_pmh_edit["message"])."</span>";
              }
              break;
            case "edit_dem": //basic input sanitisation
              if($authed_user["privilege"]>2 || ($authed_user["privilege"]>1 && is_doctor_responsible($db,$authed_user,$patient_id))){ 
              //delete patient if doctor responsible or has at least ug privs or has edit rights and is the responsible doctor
                $to_send=["research_consent"=>0];//default value as not sent automatically
                $to_set = Array("hospital_id","government_id","name","dob","research_consent","usergroup");
                foreach ($to_set as $what){ //transfer accepted fields over from form, ignoring dupes
                  if(array_key_exists($what,$_POST) && strlen($_POST[$what]) > 0 && $_POST[$what]!==$outcome["token"][$what]){
                    if($what=="usergroup"){
                      $to_send["usergroup"]=intval($_POST[$what]);
                    }else{
                      $to_send[$what]=$_POST[$what];
                    }
                  }
                }
                $outcome_dem_edit = editPatient($db,$patient_id,$to_send); //return the outcome for later use
                $html_strings["dem_edit_outcome"] = "<span class='message-".$outcome_dem_edit["status"]."'>".implode("<br>",$outcome_dem_edit["message"])."</span>";
              }
              break;
            case "delete":
              if($authed_user["privilege"]>2 || ($authed_user["privilege"]>1 && is_doctor_responsible($db,$authed_user,$patient_id))){ 
              //delete patient if doctor responsible or has at least ug privs or has edit rights and is the responsible doctor
                if(array_key_exists("confirm_delete",$_POST) && $_POST["confirm_delete"]=="true"){ //double check
                  deletePatient($db,$patient_id);
                  header('Location: '. $rel_pos .'/database');
                }
              }
              break;
            case "delete-template":
              if(array_key_exists("appt_name_numeric",$_POST) && intval($_POST["appt_name_numeric"]) > 0){
                $outcome_delete_template=deleteAppointmentTemplate($db,intval($_POST["appt_name_numeric"]));
              }
              $html_strings["outcome_delete_template"] = "<span class='message-".$outcome_delete_template["status"]."'>".implode("<br>",$outcome_delete_template["message"])."</span>";
              break;
            case "save-template":
              if($authed_user["privilege"]>1){ 
                $to_send=[];
                $to_set = Array("usergroup_id","appt_name","appt_purpose","appt_loc","next_appt_date");
                foreach ($to_set as $what){ //transfer accepted fields over from form
                  if(array_key_exists($what,$_POST) && strlen($_POST[$what]) > 0){
                    switch($what){
                       case "usergroup_id":
                         $to_send["usergroup_id"]=intval($_POST[$what]);
                       break;
                       case "appt_loc": //if dropdown menu has other selected it will have val -1 so we ignore
                         if(intval($_POST["appt_loc_numeric"])>0){
                           $to_send["location"]=$_POST["appt_loc_numeric"];
                         }else{
                           $to_send["location"]=$_POST["appt_loc"];
                         }
                       break;
                       case "next_appt_date":
                         if(isset($_POST["appt_date"]) && strtotime($_POST["appt_date"]) > 0){;
                           $to_send["typical_interval"]= (strtotime($_POST["next_appt_date"]) - strtotime($_POST["appt_date"]))/86400;
                         }else{
                           $to_send["typical_interval"]= (strtotime($_POST["next_appt_date"]) - time())/86400;
                         }
                         $to_send["typical_interval"] = max(intval($to_send["typical_interval"]),0);
                       break;
                       default:
                         $to_send[$what]=$_POST[$what];
                       break;
                    }
                  }
                }
                if(isset($_POST["appt_name_numeric"])){
                  $outcome_save_template = createAppointmentTemplate($db,$to_send); //return the outcome for later use
                  //$outcome_save_template = editAppointmentTemplate($db,$to_send); //not implemented yet
                }else{
                  $outcome_save_template = createAppointmentTemplate($db,$to_send); //return the outcome for later use
                }
                $html_strings["create_template_outcome"] = "<span class='message-".$outcome_save_template["status"]."'>".implode("<br>",$outcome_save_template["message"])."</span>";
              }
              break;
            case "book-appt":
              if($authed_user["privilege"]>1){ 
                $to_set = Array("usergroup_id", "doctor_id","appt_name","appt_purpose","appt_loc","appt_date","next_appt_date","need_reminder");
                foreach ($to_set as $what){ //transfer accepted fields over from form
                  if(array_key_exists($what,$_POST) && strlen($_POST[$what]) > 0){
                    switch($what){
                       case "usergroup_id":
                         $to_send["usergroup"]=intval($_POST[$what]);
                       break;
                       case "appt_loc": //if dropdown menu has other selected it will have val -1 so we ignore
                         if(intval($_POST["appt_loc_numeric"])>0){
                           $to_send["location"]=$_POST["appt_loc_numeric"];
                         }else{
                           $to_send["location"]=$_POST["appt_loc"];
                         }
                       break;
                       case "appt_date":
                         $to_send["due_date"]=date("Y-m-d H:i:s",strtotime($_POST["appt_date"]." ".$_POST["appt_time"]));
                         break;
                       case "doctor_id":
                         $to_send[$what]=$authed_user["id"];
                         break;
                       case "next_appt_date":
                         if(isset($_POST["appt_date"]) && strtotime($_POST["appt_date"]) > 0){;
                           $to_send["typical_interval"]= (strtotime($_POST["next_appt_date"]) - strtotime($_POST["appt_date"]))/86400;
                         }else{
                           $to_send["typical_interval"]= (strtotime($_POST["next_appt_date"]) - time())/86400;
                         }
                         $to_send["typical_interval"] = max(intval($to_send["typical_interval"]),0);
                       break;
                       case "need_reminder":
                         if($_POST["what"]="on"){
                           //here we send the message and trust in the function to update the patient and appointment table
                           //$to_send["last_reminder"]=date("Y-m-d");
                         }
                       break;
                       default:
                         $to_send[$what]=$_POST[$what];
                       break;
                    }
                  }
                }
                $outcome_book_appt = createAppointment($db,$patient_id,$to_send); //return the outcome for later use
                $html_strings["outcome_book_appt"] = "<span class='message-".$outcome_book_appt["status"]."'>".implode("<br>",$outcome_book_appt["message"])."</span>";
              }
              break;
            case "send-message":
              if($authed_user["privilege"]>1 && strlen($_POST["message"])>0){
                $to_send=Array(
                  "is_outbound"=> 1
                );
                $to_set = Array("doctor_contact_id","patient_contact_id","reminder_for");
                foreach ($to_set as $what){ //transfer accepted fields over from form
                  if(array_key_exists($what,$_POST) && intval($_POST[$what]) >= 0){
                    $to_send[$what]=intval($_POST[$what]);
                  }
                }
                $outcome_message = messagePatient($db,$patient_id,$to_send,$_POST["message"]);
              }
              break;
            default:
              exit();
              break;
          }
          $outcome=getPatientById($db,$patient_id);//regenerate token
          $outcome['token'] = clean_patient_token($outcome['sql'][0]);// clean token
        }
        $appt_location_list = listUsergroupAppointmentLocations($db)["sql"];
        $appt_template_list = listMyAppointmentTemplates($db)["sql"];
        $booked_appt_list = listAppointmentForPatient($db,$patient_id)["sql"];
        $patient_contact_list= getPatientContactListById($db,$patient_id)["sql"];
        $doctor_contact_list= getMyContactList($db)["sql"];
        $message_list= listMessageByPatient($db,$patient_id)["sql"];
        $patient_files = listAppointmentFilesByPatient($db,$patient_id)["sql"];
      }
    }
  }else{
    $outcome['message']=["Invalid patient ID."];
  }

  pdo_kill($db);
?>
<!DOCTYPE html>
<html lang="en-US">
<head>
<?php
  $page_vars = Array(
    "title" =>"View Patient",
  );
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/pageinit.php";
?>
</head>
<body>
<?php
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/header.php";
?>
<?php

?>
<div>
<?php
  if($outcome['status']=="error"){
    $error_message=implode("<br>",$outcome["message"]);
    echo <<<HRD
    <div class="generic-error">
      <p class="error-title">Error</p>
      <p class="error-message">{$error_message}</p>
    </div>
HRD;
  }else{
    //expose PHP variables to JS
    echo("<script>const patient=".json_encode($outcome['token'])."</script>");
    echo("<script>const appt_location_list=".json_encode($appt_location_list)."</script>");
    echo("<script>const appt_template_list=".json_encode($appt_template_list)."</script>");
    echo("<script>const booked_appt_list=".json_encode($booked_appt_list)."</script>");
    echo("<script>const patient_contact_list=".json_encode($patient_contact_list)."</script>");
    echo("<script>const doctor_contact_list=".json_encode($doctor_contact_list)."</script>");
    echo("<script>const message_list=".json_encode($message_list)."</script>");
    echo("<script>const file_info=".json_encode($patient_files)."</script>");
    //generate HTML snippets to insert into the big heredoc later
    $today_date_string = date("Y-m-d") ;
    foreach($appt_location_list as $what){
      $html_strings["appt_location_dropdown"] .= "<option value='".intval($what->id)."'>".htmlentities(base64_decode($what->location_name))."</option>";
    }
    foreach($appt_template_list as $what){
      $html_strings["appt_template_dropdown"] .= "<option value='".intval($what->id)."'>".htmlentities(base64_decode($what->appt_name))."</option>";
    }
    if(count($patient_contact_list)>0){
      foreach($patient_contact_list as $what){
        $html_strings["patient_contact_select"] .= "<option value='".intval($what->id)."'>".htmlentities($what->type)." (".htmlentities(base64_decode($what->contact_detail)).")</option>";
      }
    }else{
      $html_strings["patient_contact_select"] = "<option value='-1' selected disabled>No available patient contacts...</option>";
    }
    if(count($doctor_contact_list)>0){
      foreach($doctor_contact_list as $what){
        $html_strings["doctor_contact_select"] .= "<option value='".intval($what->id)."'>".htmlentities($what->type)." (".htmlentities(base64_decode($what->contact_detail)).")</option>";
      }
    }else{
      $html_strings["doctor_contact_select"] = "<option value='-1' selected disabled>You have no contact info...</option>";
    }
    if(!array_key_exists("diagnosis",$outcome['token']["pmh_config"]) || strlen($outcome['token']["pmh_config"]['diagnosis'])==0){
      $outcome['token']["pmh_config"]["diagnosis"]="<i>Not set</i>";
    }
    if(!array_key_exists("treatment_plan",$outcome['token']["pmh_config"]) || strlen($outcome['token']["pmh_config"]["treatment_plan"])==0){
      $outcome['token']["pmh_config"]["treatment_plan"]="<i>Not set</i>";
    }
    if(!array_key_exists("medical_history",$outcome['token']["pmh_config"]) || strlen($outcome['token']["pmh_config"]["medical_history"])==0){
      $outcome['token']["pmh_config"]["medical_history"]="<i>Not set</i>";
    }
    $neat_pat_dob=date("j F Y",strtotime($outcome['token']["dob"]));
    $usergroup_dropdown="";
    foreach($authed_user["usergroups"] as $what){
      $usergroup_dropdown .= "<option value='".intval($what->usergroup_id)."'>".htmlentities(base64_decode($what->group_name))."</option>";
    }
    //render dropdown options for booked appointments
    // we keep the table rendering in js to enable sorting
    $html_strings["appt_book_list"]= "<option value='-1' selected='selected'>No applicable appointment.</option>";
    foreach($booked_appt_list as $appt){
      $html_strings["appt_book_list"].= "<option value='".$appt->id."' onmouseup='genMessage(".$appt->id.")'>".base64_decode($appt->appt_name)." on ".$appt->due_date."</option>";
    }
    //render messages
    if(count($message_list)>0){
      foreach($message_list as $msg){
        if(intval($msg->is_outbound)){$mbdir = "out";}else{$mbdir = "in";}
        $html_strings["message_list"] .=
          "<div class='message-bubble message-bubble-$mbdir'><span class='message-metadata sm2'>TO: ".
          base64_decode($msg->patient_contact_detail).
          "</span><span class='message'>".
          base64_decode($msg->content).
          "</span><span class='message-sent sm2'>SENT: ".
          $msg->sent.
          "</span></div>";
      }
    }else{
      $html_strings["message_list"]="<div class='null-message noselect'><i>No messages yet. Be the first to send one!</i></div>";
    }
    
    // for *-input-toggleable we save the text contents in the save parameter and insert an input node with name = id of span, value= innerHTML of span, type= type of span
    echo <<<HRD
  <form class="patient-card" method="post" >
    <div class="patient-card-left">
      <input type="hidden" name="form_action" value="edit_dem" required></input><br>
      <span class="dem-input-toggleable patient-card-name" id="name">{$outcome['token']["name"]}</span>
      <span class="dem-input-toggleable" save2="{$outcome['token']["dob"]}" id="dob" type="date">{$neat_pat_dob}</span>
      <span><span>K Number: </span><span class="dem-input-toggleable patient-card-detail" id="hospital_id">{$outcome['token']["hospital_id"]}</span></span>
      <span><span>NHS Number: </span><span class="dem-input-toggleable patient-card-detail" id="government_id">{$outcome['token']["government_id"]}</span></span>
      <span><span>Preferred Contact Details: </span><span class="patient-card-detail" id="contact_detail">{$outcome['token']["contact_detail"]}</span></span>
    </div>
    <div class="patient-card-mid">
      <span>Doctor In Charge: {$outcome['token']["username"]}</span>
      <span>Responsible Trust: <span id="patient-card-usergroup">{$outcome['token']["usergroup_name"]}</span></span>
      <span><span>Research Consent: </span><span class="dem-input-toggleable" id="research_consent" type="checkbox">{$outcome['token']["research_consent"]}</span></span>
      <span>
        <input class="" type="button" id="dem-toggle" value='Edit' onclick="toggleDemEdit()"></input>
        <input class="hide" type="submit" id="dem-submit" value='Submit' ></input>
        <input class="hide" type="reset" id="dem-reset" value='Clear' ></input>
      </span>
    </div>
  </form>
  {$html_strings["dem_edit_outcome"]}
  <div class="generic-info">
    <form class="generic-info-box" method="post" >
      <p>
        <b>MEDICAL BACKGROUND</b>
        <input type="button" id="pmh-toggle" value='Edit' onclick="togglePMHEdit()"></input>
        <input type="hidden" name="form_action" value="edit_pmh" required></input><br>
      </p><hr>
      {$html_strings["pmh_edit_outcome"]}
      <p>Diagnosis: <br><span class="pmh-input-toggleable" id="diagnosis">{$outcome['token']["pmh_config"]["diagnosis"]}</span></p>
      <p>Treatment Plan: <br><span class="pmh-input-toggleable" id="treatment_plan">{$outcome['token']["pmh_config"]["treatment_plan"]}</span></p>
      <p>Other Information: <br><span class="pmh-input-toggleable" id="medical_history">{$outcome['token']["pmh_config"]["medical_history"]}</span></p>
      <p>
        <input class="hide" type="submit" id="pmh-submit" value='Submit' ></input>
        <input class="hide" type="reset" id="pmh-reset" value='Clear' ></input>
      </p>
    </form>
    <form class="generic-info-box-narrow" id="appointment-form" method="post">
      <p>
        <b>APPOINTMENTS</b><input type="hidden" id="appointment-form-action" name="form_action" value="book-appt" required></input>
      </p><hr>
      <p>Last seen on: {$outcome['token']["last_appt_date"]}</p>
      <p>Next appointment: {$outcome['token']["next_appt_date"]}</p>
      <table id="pt-appt-table" class="pt-appt-table"></table>
      <p>Book an appointment: </p>
      <span>
        Responsible Trust: 
        <select id="ug-select" name="usergroup_id">
          {$usergroup_dropdown}
        </select>
      </span><br>
      <div class="dropdown">
        <input type="text" autocomplete="off" id="appt-name-input" name="appt_name" placeholder="Appointment type/template" required></input>
        <select id="appt-name-select" name="appt_name_numeric" onchange="updateATComboBox()">
          {$html_strings["appt_template_dropdown"]}
          <option value="-1" selected>Custom template...</option>
        </select>
      </div>
      <textarea class="patient-form" type="text"  id="appt-purp-input" name="appt_purpose" placeholder="Appointment purpose" required></textarea><br>
      <div class="dropdown">
        <input type="text" autocomplete="off" onkeyup="document.getElementById('appt-loc-select').value=-1" id="appt-loc-input" name="appt_loc" placeholder="Appointment location" required >
        <select id="appt-loc-select" name="appt_loc_numeric" onchange="updateALComboBox()">
          {$html_strings["appt_location_dropdown"]}
          <option value="-1" selected>Other Location...</option>
        </select>
      </div>
      Booking Date: <input type="date" name="appt_date" id="appt_start_date" value="{$today_date_string}" required></input><input type="time" name="appt_time" id="appt_start_time" value="00:00" required></input><br>
      Expected Next Appointment Date: <input type="date" id="appt_next_date" name="next_appt_date" value={$today_date_string}></input><br>
      <span>Send a reminder? </span><input type="checkbox" name="need_reminder"></input><br>
      {$html_strings["create_template_outcome"]}{$html_strings["outcome_delete_template"]}{$html_strings["book_appt_outcome"]}{$html_strings["outcome_book_appt"]}
      <p>
        <input type="button" onclick="saveApptTemplate()" value='Save as Template'></input>
        <input type="button" onclick="delApptTemplate()" value='Delete Template'></input> 
        <input type="submit" value='Book Appointment'></input>
      </p>
    </form>
    <form class="generic-info-box-narrow" method="post">
      <p><b>MESSAGES</b></p><hr>
      <p>Last reminder: {$outcome['token']["last_reminder_sent"]}<br>
      Send message to: <select name="patient_contact_id">{$html_strings["patient_contact_select"]}</select></br>
      Receive replies at: <select name="doctor_contact_id">{$html_strings["doctor_contact_select"]}</select></br>
      <span>
      Choose an appointment that this applies to:
        <br>
        <select name="reminder_for" id="reminder_for" name="appointment_name">
          {$html_strings["appt_book_list"]}
        </select>
        <br>
      </span></p>
      <div class="message-holder">
        <div class="message-frame">
          {$html_strings["message_list"]}
        </div>
        <input type="hidden" name="form_action" value="send-message" required></input>
        <div class="textarea-box">
          <textarea class="textarea-mbox" id="message_box" name="message" placeholder="Type something...">
          </textarea>
          <input class="textarea-mbutton" type="submit" value='>'></input>
        </div>
      </div>
      <br>
    </form>
    <div class="generic-info-box">
      <p><b>FILES</b></p><hr>
        <p>
        <div class="file-picker">
          <div class="file-picker-row noselect">
            <div class="file-picker-tab file-picker-tab-selected" onclick="fileGroupDisplay(0)">Text</div>
            <div class="file-picker-tab" onclick="fileGroupDisplay(1)">Image</div>
            <div class="file-picker-tab" onclick="fileGroupDisplay(2)">Video</div>
            <div class="file-picker-tab" onclick="fileGroupDisplay(3)">File</div>
          </div>
          <div class="file-content">
            <div class="file-listy">
              <span><i>No text files found</i></span>   
            </div>
            <div class="file-listy">
              <span><i>No images found</i></span>   
            </div>
            <div class="file-listy">
              <span><i>No videos found</i></span>   
            </div>
            <div class="file-listy">
              <span><i>No documents found</i></span>   
            </div>
          </div>
        </div>
      </p>
    </div>
    <form method="post" id="del-form">
      <p>
        <input type="hidden" name="form_action" value="delete" required></input>
        <input type="hidden" name="confirm_delete" id="del-confirm" value="false" required></input>
        <input type="button" value='Delete Patient' onclick='confirmDelete()'></input>
      </p>
    </form>
  </div>
HRD;
  }
?>
</div>
<style>
  .message-holder{
    width:100%;
  }
  .message-frame{
    width: 100%;
    border: 1px solid black;
    background: #eeeeee;
    overflow-y: scroll;
    height: 20rem;
    position: relative;
    display: flex;
    flex-direction:column;
    padding: 0.5rem 0;
    word-wrap: break-word;
  }
  .message-bubble{
    max-width: 60%;
    margin: 0.5rem 1rem;
    padding: 0.75rem;
    display: flex;
    flex-direction: column;
  }
  .message-sent{
    color: #555;
    align-self: flex-end;
  }
  .message-metadata{
    color: #555;
  }
  .message-bubble-in{
    background:#ffffff;
    align-self: flex-start;
    border-radius: 0.5rem 0.5rem 0.5rem 0;
  }
  .message-bubble-out{
    background:#85beff;
    align-self: flex-end;
    border-radius: 0.5rem 0.5rem 0 0.5rem;
  }
  .null-message{
    position: absolute;
    left: 50%;
    top: 50%;
    transform: translate(-50%,-50%);
  }
  .textarea-box{
    position: relative;
  }
  .textarea-mbox{
    min-width: calc(100% - 4.62rem);
    max-width: calc(100% - 4.62rem);
    padding-right: 4rem;
  }
  .textarea-mbutton{
    position: absolute;
    right: 1rem;
    top: 50%;
    transform: translateY(-50%);
    font-size: 1.5em;
    font-weight: bold;
    border-radius: 1rem;
  }
  .generic-error{
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    background: #fe9;
    margin: 2rem;
    padding: 1rem;
  }
  .error-title{
    font-size: 2rem;
    font-weight: bold;
  }
  .error-message{
    font-size: 1.5rem;
  }
  .patient-card{
    background:#eee;
    display: flex;
    justify-content: space-between;
    width: calc(100% - 4rem);
    padding: 1rem 2rem;
  }
  .patient-card-left, .patient-card-mid{
    display: flex;
    flex-direction: column;
  }
  .patient-card-name{
    font-size: 2rem;
  }

  .patient-form, .pmh-input-toggleable > textarea{
    min-width: calc(100% - 1.6rem);
    max-width: calc(100% - 1.6rem);
  }

  .appt-header{
    font-weight: bold;
    background: #eee;
    cursor:pointer;
  }
  .appt-table-row{
    cursor:pointer;
  }
  .pt-appt-table{
    border: 1px solid black;
  }
  .appt-file-table{
    width: 80%;
    border: 1px solid black;
  }
  .appt-table-row:hover td{
    background: #aaf;
  }

</style>
<script>
function saveApptTemplate(){
  document.getElementById("appointment-form-action").value="save-template"
  document.getElementById("appointment-form").submit()
}

function delApptTemplate(){
  if(confirm("Are sure you wish to delete this appointment template? Existing appointments using this template will be unaffected but you will need to recreate this template if you wish to reuse it. \r\n\r\nClick OK to confirm deletion or Cancel to abort.")){
    document.getElementById("appointment-form-action").value="delete-template"
    document.getElementById("appointment-form").submit()
  }
}
function togglePMHEdit(){
  let input
  let docList = document.querySelectorAll(".pmh-input-toggleable")
  let i
  if(docList[0].firstChild.nodeName!=="TEXTAREA"){
    document.getElementById("pmh-toggle").value="Cancel"
    document.getElementById("pmh-submit").style.display="inline"
    document.getElementById("pmh-reset").style.display="inline"
    for(i=0;i<docList.length;i++){
      input = document.createElement("textarea")
      input.placeholder="Type something..."
      input.required="required"
      input.name=docList[i].id
      if(docList[i].firstChild.nodeName=="#text"){
        input.value=unhtmlentities(docList[i].innerHTML)
      }
      docList[i].save = docList[i].innerHTML
      docList[i].innerHTML=""
      docList[i].appendChild(input)
    }
  }else{
    document.getElementById("pmh-toggle").value="Edit"
    document.getElementById("pmh-submit").style.display="none"
    document.getElementById("pmh-reset").style.display="none"
    for(i=0;i<docList.length;i++){
      docList[i].innerHTML=docList[i].save
    }
  }
}
function toggleDemEdit(){
  let input
  let docList = document.querySelectorAll(".dem-input-toggleable")
  let opar=document.getElementById("patient-card-usergroup")
  let i
  if(docList[0].firstChild.nodeName!=="INPUT"){
    document.getElementById("dem-toggle").value="Cancel"
    document.getElementById("dem-submit").style.display="inline"
    document.getElementById("dem-reset").style.display="inline"
    opar.save=opar.innerHTML
    let sel = document.createElement("select")
    sel.name="usergroup"
    for(i=0;i<authed_user.usergroups.length;i++){
      let opt = document.createElement("option")
      opt.value=authed_user.usergroups[i].usergroup_id
      opt.text=atob(authed_user.usergroups[i].group_name)
      sel.appendChild(opt)
    }
    opar.innerHTML=""
    opar.appendChild(sel)
    for(i=0;i<docList.length;i++){
      input = document.createElement("input")
      input.name=docList[i].id
      if(typeof(docList[i].attributes.type) ==="undefined"){
        input.type="text"
        input.placeholder="Type something..."
      }else{
        input.type=docList[i].attributes.type.value
      }
      switch(input.type){
        case "date":
          input.required="required"
          input.value=docList[i].attributes.save2.value
          break;
        case "checkbox":
          input.checked=(docList[i].innerHTML=='Yes')
          break;
        default:
          input.required="required"
          if(docList[i].firstChild.nodeName=="#text"){
            input.value=unhtmlentities(docList[i].innerHTML)
          }
          break;
      }
      docList[i].save = docList[i].innerHTML
      docList[i].innerHTML=""
      docList[i].appendChild(input)
    }
  }else{
    document.getElementById("dem-toggle").value="Edit"
    document.getElementById("dem-submit").style.display="none"
    document.getElementById("dem-reset").style.display="none"
    opar.innerHTML=opar.save
    for(i=0;i<docList.length;i++){
      docList[i].innerHTML=docList[i].save
    }
  }
}

function updateALComboBox(){
  if(document.getElementById('appt-loc-select').value > 0){
    let idx = appt_location_list.find(x=>(x.id==document.getElementById('appt-loc-select').value))
    document.getElementById('appt-loc-input').value = atob(idx.location_name)
    document.getElementById('ug-select').value = idx.usergroup_id
  }else(
    document.getElementById('appt-loc-input').value=""
  )
  document.getElementById('appt-loc-input').focus()
}

function updateATComboBox(){
  if(document.getElementById('appt-name-select').value > 0){
    let idx = appt_template_list.find(x=>(x.id==document.getElementById('appt-name-select').value))
    document.getElementById('appt-name-input').value = atob(idx.appt_name)
    document.getElementById('ug-select').value = idx.usergroup_id
    document.getElementById('appt-loc-select').value = idx.location
    updateALComboBox()
    document.getElementById('appt-purp-input').value = atob(idx.appt_purpose) 
    let s = new Date(document.getElementById('appt_start_date').value)
    s.setDate(s.getDate()+parseInt(idx.typical_interval))
    let m = s.getMonth() + 1
    let d = s.getDate()
    if(m < 10 ){m = "0" + m} //padding needed
    if(d < 10 ){d = "0" + d} 
    document.getElementById('appt_next_date').value = s.getFullYear() + "-"+ m + "-" + d
  }else(
    document.getElementById('appt-name-input').value=""
  )
  document.getElementById('appt-name-input').focus()
}

function confirmDelete(){
  document.getElementById("del-confirm").value=confirm("Are you absolutely sure you want to delete this patient? This is an irreversible process. \r\n\r\nClick OK to confirm deletion or Cancel to abort.");
  document.getElementById("del-form").submit();
}

  function addAppointmentRow(table,info){
    let newRow=document.createElement("tr")
    newRow.className="appt-table-row responsive"
    newRow.addEventListener("click",function(){window.location="../appointment/"+info['id']})
    let i
    for(i=0;i<5;i++){
      let newCell=document.createElement("td")
      switch(i){
        case 0:
          newCell.innerHTML=htmlentities(atob(info["appt_name"]))
        break;
        case 1:
          if(info["due_date"]!==null){
            newCell.innerHTML=htmlentities(info["due_date"])
          }else{
            newCell.innerHTML="<i>No date chosen</i>"
          }
        break;
        case 2:
          if(info["attended_date"]!==null){
            newCell.innerHTML=htmlentities(info["attended_date"])
          }else{
            newCell.innerHTML="<i>Not yet attended</i>"
          }
        break;
        case 3:
          if(info["last_reminder"]!==null){
            newCell.innerHTML=htmlentities(info["sent"])
          }else{
            newCell.innerHTML="<i>No reminder sent.</i>"
            newCell.innerHTML += "<span class='message-remind'> [Send Reminder]</span>"
            newCell.addEventListener("click",function(e){
              e.stopPropagation()
              genMessage(info['id'],info)
              document.getElementById("reminder_for").value=parseInt(info.id)
            })
          }
        break;
        case 4:
          let attendInfo = ["Cancelled", "Booked", "Awaiting Results", "Attended", "Completed", "Did Not Attend"]
          newCell.innerHTML=attendInfo[parseInt(info["appt_outcome"])]
        break;
        default:
        break;
      }
      newRow.appendChild(newCell)
    }
    table.appendChild(newRow)
  }
  function genMessage(id,info){
    let mbox = document.getElementById("message_box")
    if(typeof(info)=="undefined"){
      if(id>0){
        info = booked_appt_list.find(x=>x.id==id)
      }else{
        mbox.value=""
        return
      }
    }
    let dobj =new Date(Date.parse(info['due_date']))
    let dstr = 
      ["Mon, ","Tue, ","Wed, ", "Thu, ", "Fri, ", "Sat, ", "Sun, "][dobj.getDay()]+
      dobj.getDate()+
      [" January "," February "," March ", " April ", " May ", " June ", " July ", " August ", " September ", " October ", " November ", " December "][dobj.getMonth()]+
      dobj.getFullYear()+" at "+
      (((dobj.getHours()+11)%12)+1)+
      (dobj.getMinutes()<10?":0":":")+
      dobj.getMinutes()+
      (dobj.getHours()<12?" AM":" PM")
    mbox.value="Hello, your doctor would like to remind you that you have an appointment at "+atob(info['location_name'])+" scheduled for "+dstr+" for "+atob(info['appt_purpose'])+"."
    mbox.focus()
  }
  //helper sort function
  function sortList(list,column_names=[],sort=-1,dir=1){
    (dir>0?dir=1:dir=-1)
    sort=parseInt(sort)
    if(sort>=0){
      let sortCol=column_names[sort]
      list.sort((a,b)=>(dir*(""+a[sortCol]).localeCompare(""+b[sortCol])))
    }
    generateAppointmentTable(list,sort,dir)
  }
  //generate appointment table
  function generateAppointmentTable(appt_list,sort=-1,dir=1){
    let aTable = document.getElementById("pt-appt-table")
    aTable.innerHTML=""
    if(appt_list.length > 0){
      let th = document.createElement("tr")
      th.className="appt-header noselect"
      let headers = ["Appointment Name","Scheduled For", "Date Attended", "Last Reminder", "Status"]
      let i
      for(i=0;i<headers.length;i++){
        let newCell=document.createElement("td")
        let j = i
        newCell.innerHTML=headers[i]
        newCell.addEventListener("click",function(e){
          sortList(appt_list,["appt_name","due_date","attended_date","sent","appt_outcome"],j,e.target.getAttribute("dir"))
        })
        if(i==sort){newCell.dir=-dir}else{newCell.dir=1}
        th.appendChild(newCell)
      }
      aTable.appendChild(th)
      for(let appt=0;appt<appt_list.length;appt++){
        addAppointmentRow(aTable,appt_list[appt])
      }
    }else{
      aTable.remove()
      //document.getElementById("reminder-for").remove()
    }
  }
    
  function modalify(e,content=undefined){
    if(e===null){
      if(document.getElementById("mAnchor") !== null){
        document.getElementById("mAnchor").remove()
      }
    }else{
      let modala=document.createElement("div")
      modala.className="floating-modal-anchor"
      modala.id="mAnchor"
      let modalbg=document.createElement("div")
      modalbg.className="floating-modal-parent"
      let modal=document.createElement("div")
      modal.className="floating-modal"
      modal.addEventListener("click",function(e){e.stopPropagation()})
      let modalc=document.createElement("div")
      modalc.className="floating-modal-lid"
      let t = ""
      if(typeof(content)==="undefined"){
        t=e.target.cloneNode(true)
      }else{
        t=content.cloneNode(true)
        modalc.className="floating-modal-close"
        modalc.innerHTML="<span>X</span>"
      }
      modal.appendChild(t)
      modalbg.appendChild(modal)
      modalbg.appendChild(modalc)
      modala.appendChild(modalbg)
      modalc.addEventListener("click",function(){modalify(null)})
      modalbg.addEventListener("click",function(){modalify(null)})
      let h = document.querySelectorAll('.header')[0]
      h.parentNode.insertBefore(modala,h)
    }
  }
  function renderFiles(fInfo,el,variant=0){
    if(fInfo.length <=0){
      el.innerHTML="<span><i>No "+["text files","images","videos","documents"][variant]+" found</i></span>"
    }else{
      el.innerHTML=""
      switch(variant){
        case 0:
        case 2:
        case 3:
          let table = document.createElement("table")
          table.className="appt-file-table"
          let headers=document.createElement("tr")
          headers.className="appt-header noselect"
          let hList = ["Filename","Created On","Last Edited"]
          for(let h=0;h<hList.length;h++){
            let th = document.createElement("td")
            th.innerHTML = hList[h]
            headers.appendChild(th)
          }
          table.appendChild(headers)
          for(let i=0;i<fInfo.length;i++){
            let tr=document.createElement("tr")
            if(variant==3){
              tr.addEventListener("click",function(e){window.open("../files/"+fInfo[i]["content"], '_blank').focus();})            
            }else{
              let text = document.createElement("embed")
              text.src = "../files/"+fInfo[i]["content"]
              tr.addEventListener("click",function(e){modalify(e,text)})
            }
            tr.className="appt-table-row responsive noselect"
            let td1 = document.createElement("td")
            td1.className=""
            td1.innerHTML=htmlentities(atob(fInfo[i]["filename"]))
            tr.appendChild(td1)
            let td2 = document.createElement("td")
            td2.className=""
            td2.innerHTML=htmlentities(fInfo[i]["date_created"])
            tr.appendChild(td2)
            let td3 = document.createElement("td")
            td3.className=""
            td3.innerHTML=htmlentities(fInfo[i]["date_edited"])
            tr.appendChild(td3)
            table.appendChild(tr)
          }
          el.appendChild(table)
          break;
        case 1:
          for(let i=0;i<fInfo.length;i++){
            let pDiv = document.createElement("div")
            pDiv.className="image-holder noselect"
            let img = document.createElement("img")
            img.src = "../files/"+fInfo[i]["content"]
            pDiv.appendChild(img)
            el.appendChild(pDiv)
            img.addEventListener("click",function(e){modalify(e)})
          }
          break;
        default:
          break;
      }
    }
  }

  function fileGroupDisplay(n){
    let fList=document.querySelectorAll(".file-listy");
    renderFiles(file_info[n],fList[n],n)
    document.querySelectorAll(".file-picker-tab.file-picker-tab-selected")[0].className="file-picker-tab"
    for(let i=0;i<fList.length;i++){
      if(i===n){
        document.querySelectorAll(".file-picker-tab")[i].className="file-picker-tab file-picker-tab-selected"
        fList[i].style.display="flex"      
      }else{
        fList[i].style.display="none"
      }
    }
  }


//Unconditionally generate HTML
generateAppointmentTable(booked_appt_list)
fileGroupDisplay(0)
</script>
</body>
</html>
