<?php
  $rel_pos="/hcc";
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/config.php";
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/auth.php";
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/api/scripts/patient.php";
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/api/scripts/doctor.php";
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/api/scripts/appointment.php";
  $db=pdo_init();
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
   "appt_booking_status"=>"",
   "appt_edit_outcome"=>"",
   "appt_info"=>[],
   "file_insert_outcome"=>""
  );
    
//Retrieve patient id
  $apid = [];//placeholder var for regex
  preg_match('/\d+(?=$|\?|#)/', $_SERVER['REQUEST_URI'],$apid);

//Logic to handle retrieving appointment
  $appt_outcome = Array("status"=>"error","message"=>["Invalid appointment."],"sql"=>[]);
  $outcome = Array("status"=>"error","message"=>[],"sql"=>[]);
  $appt_location_list = [];
  $appt_template_list = [];
  $patient_contact_list = [];
  $doctor_contact_list = [];
  $outcome_temp = [];
  $appt_docs = [];
  //extend outcome logic if patient not found
  if(count($apid)>0 && intval($apid[0])>0){
    $appointment_id=intval($apid[0]);
    $appt_outcome = getAppointmentById($db,$appointment_id);
    if($appt_outcome['status']=="success"){
      if(count($appt_outcome['sql'])==0){ //if no patients abort
        $outcome['status']='error';
        $outcome["message"] = ["Appointment #".$appointment_id." not found"];
      }else{ //else proceed by setting patient_id
        $patient_id = intval($appt_outcome["sql"][0]->patient_id);
        $outcome=getPatientById($db,$patient_id);
        $outcome['token'] = clean_patient_token($outcome['sql'][0]);// clean token
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
              case "edit-appt":
                if($authed_user["privilege"]>2 || ($authed_user["privilege"]>1 && is_doctor_responsible($db,$authed_user,$patient_id))){ 
                //edit patient if doctor responsible or has at least ug privs or has edit rights and is the responsible doctor
                  $to_send=[];
                  $to_set = Array("booked_date_date","appt_outcome","appt_name","appt_purpose","location");
                  foreach ($to_set as $what){ //transfer accepted fields over from form
                    if(array_key_exists($what,$_POST) &&
                       strlen($_POST[$what]) > 0 &&
                       ($what=="booked_date_date" ||
                       ($_POST[$what]!=$appt_outcome["sql"][0]->$what &&
                       base64_encode($_POST[$what]) !=$appt_outcome["sql"][0]->$what)) 
                      ){
                      switch($what){
                      case "booked_date_date":
                        if(array_key_exists("booked_date_time",$_POST)){
                          $due_date=$_POST["booked_date_date"] . 
                                               " " .
                                               $_POST["booked_date_time"] .
                                               ":00";
                        }else{
                          $due_date=$_POST["booked_date_date"] .
                                               " 00:00:00";
                        }
                        if(strtotime($due_date)!= strtotime($appt_outcome["sql"][0]->due_date)){
                          $to_send["due_date"] = date("Y-m-d H:i:s",strtotime($due_date ));
                        }
                        break;
                      case "location":
                        if(intval($_POST[$what])==-1){
                          if(array_key_exists("location_new",$_POST)){
                            $to_send[$what]=$_POST["location_new"];
                            if(array_key_exists("usergroup",$_POST)){
                              $to_send["usergroup"]=$_POST["usergroup"]; //actually required to create new location
                            }
                          }
                        }else{
                          $to_send[$what]=intval($_POST[$what]);
                        }
                        break;
                      default:
                        $to_send[$what]=$_POST[$what];
                        break;
                      }
                    }
                  }
                  if(count($to_send)>0){
                    $outcome_appt_edit = editAppointment($db,$appointment_id,$to_send); //return the outcome for later use
                  }
                  $html_strings["appt_edit_outcome"] = "<span class='message-".$outcome_appt_edit["status"]."'>".implode("<br>",$outcome_appt_edit["message"])."</span>";
                }
              break;
              case "upload-files":
                include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/api/scripts/mime.php";
                // IMPORTANT - DEFAULT upload_max_filesize is 2M and post_max_size is 8M
                $fmime = finfo_open(FILEINFO_MIME_TYPE);
                for($file=0;$file<count($_FILES["appt_files"]["name"]);$file++){
                  if(!$_FILES['appt_files']['error'][$file]){
                    $params = [];
                    $params["mime_type"] = finfo_file($fmime, $_FILES['appt_files']['tmp_name'][$file]);
                    $fext = mime_to_ext($params["mime_type"]);
                    $params["filename"] = $_FILES['appt_files']['name'][$file];
                    //append correct extension if necessary
                    if(!preg_match("/\\".$fext."$/m", $params["filename"])){
                      $params["filename"] .= $fext;
                    }
                    $params["content"] = $_FILES["appt_files"]["tmp_name"][$file]; //function will encode for us
                    $file_create_status = createAppointmentFile($db,$appointment_id,$params);
                    $html_strings["file_insert_outcome"] .= "<span class='message-".$file_create_status["status"]."'>".implode("<br>",$file_create_status["message"])."</span><br>";
                  }
                }
                finfo_close($fmime);
              break;
              default:
                echo "Null action";
                //exit();
                break;
            }
            //regenerate tokens
            $appt_outcome = getAppointmentById($db,$appointment_id);
            $outcome=getPatientById($db,$patient_id);
          }

          $outcome['token'] = clean_patient_token($outcome['sql'][0]);// clean token
        }
        $appt_location_list = listUsergroupAppointmentLocations($db)["sql"];
        $appt_template_list = listMyAppointmentTemplates($db)["sql"];
        $patient_contact_list = getPatientContactListById($db,$patient_id)["sql"];
        $doctor_contact_list = getMyContactList($db)["sql"];
        $appt_docs = listAppointmentFilesForId($db,$appointment_id)["sql"];
        $appt_files = listAppointmentFilesForId($db,$appointment_id)["sql"];
    }
  }else{
    $outcome['message']=["Invalid appointment ID."];
  }
  pdo_kill($db);
?>
<!DOCTYPE html>
<html lang="en-US">
<head>
<?php
  $page_vars = Array(
    "title" =>"View Appointment",
  );
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/pageinit.php";
?>
</head>
<body>
<?php
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/header.php";
?>
<div>
<?php
  if($appt_outcome['status']=="error"){
    $error_message=implode("<br>",$appt_outcome["message"]);
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
    //echo("<script>const booked_appt_list=".json_encode($booked_appt_list)."</script>");
    echo("<script>const patient_contact_list=".json_encode($patient_contact_list)."</script>");
    echo("<script>const doctor_contact_list=".json_encode($doctor_contact_list)."</script>");
    echo("<script>const file_info=".json_encode($appt_files)."</script>");
    //generate HTML snippets to insert into the big heredoc later
    $html_strings["appt_booking_status"] = ["Cancelled","Booked","Awaiting Results","Attended","Completed","Did Not Attend"][intval($appt_outcome["sql"][0]->appt_outcome)];
    $today_date_string = date("Y-m-d") ;
    foreach(["appt_name","appt_purpose","location_name","group_name"] as $what){
      if(isset($appt_outcome['sql'][0]->$what)){
        array_push($html_strings["appt_info"], base64_decode($appt_outcome['sql'][0]->$what));
      }else{
        array_push($html_strings["appt_info"], "<i>Not set</i>");
      }
    }
    switch(intval($appt_outcome["sql"][0]->appt_outcome)){
      case 0:
        if(is_null($appt_outcome["sql"][0]->cancelled_date)){
          array_push($html_strings["appt_info"], "<span class='message-error'>[Appointment cancelled]</span>");
        }else{
          array_push($html_strings["appt_info"], "<span class='message-error'>[Cancelled on {$appt_outcome["sql"][0]->cancelled_date}]</span>");
        }
        break;
      case 3:
      case 4:
        if(is_null($appt_outcome["sql"][0]->attended_date)){
          array_push($html_strings["appt_info"], "<span class='message-success'><b>[Appointment attended]</b></span>"); 
        }else{
          array_push($html_strings["appt_info"], "<span class='message-success'><b>[Attended on {$appt_outcome["sql"][0]->attended_date}]</b></span>"); 
        }
        break;
      default:
        $appt_days = intval((strtotime($appt_outcome['sql'][0]->due_date)-time())/86400);
        $s="";
        if($appt_days<0){
          $appt_days = -$appt_days;
          if($appt_days>1){$s="s";}
          array_push($html_strings["appt_info"], "<span class='message-error'><b>($appt_days day$s ago)</b></span>");
        }else if($appt_days>0){
          if($appt_days>1){$s="s";}
          array_push($html_strings["appt_info"], "<span>($appt_days day$s from now)</span>");
        }else{
          array_push($html_strings["appt_info"], "<span><b>(today)</b></span>");
        }
        break;
    }
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
    // for *-input-toggleable we save the text contents in the save parameter and insert an input node with name = id of span, value= innerHTML of span, type= type of span
    echo <<<HRD
  <div class="patient-card" method="post" style="cursor:pointer" onclick="window.location='../patient/{$outcome['token']['id']}'" >
    <div class="patient-card-left">
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
    </div>
  </div>
  {$html_strings["dem_edit_outcome"]}
  <div class="generic-info">
    <form class="generic-info-box" method="post">
      <p>
        <b>MEDICAL BACKGROUND</b>
        <input type="button" id="pmh-toggle" value='Edit' onclick="togglePMHEdit()"></input>
        <input type="hidden" name="form_action" value="edit_pmh" required></input>
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
    <form class="generic-info-box" id="appointment-form" method="post">
      <p>
        <b>APPOINTMENT DETAILS</b>
        <input type="button" id="ad-toggle" value='Edit' onclick="toggleAPEdit()"></input>
        <input type="hidden" id="appointment-form-action" name="form_action" value="edit-appt" required></input>
      </p><hr>
      <p>
        Current Status: <span class="ad-input-toggleable" id="appt_outcome" type="status">{$html_strings["appt_booking_status"]}</span><br>
        Booking Date: <span>{$appt_outcome['sql'][0]->booked_date}</span><br>
        Appointment Date: <span class="ad-input-toggleable" id="booked_date" type="datetime">{$appt_outcome['sql'][0]->due_date}</span> {$html_strings['appt_info'][4]}<br>
        Appointment Name: <span class="ad-input-toggleable" id="appt_name">{$html_strings['appt_info'][0]}</span><br>
        Appointment Purpose: <span class="ad-input-toggleable" id="appt_purpose">{$html_strings['appt_info'][1]}</span><br>
        Appointment Location: <span class="ad-input-toggleable" id="location" type="dropdown">{$html_strings['appt_info'][2]}</span><br>
        Relevant Trust: <span class="ad-input-toggleable" id="usergroup" type="trust_selector">{$html_strings['appt_info'][3]}</span>
      </p>
      <p>{$html_strings["appt_edit_outcome"]}<br>
        <input class="hide" type="submit" id="ad-submit" value='Submit' ></input>
        <input class="hide" type="reset" id="ad-reset" value='Clear' ></input>
      </p>
    </form>
    <form class="generic-info-box" id="files-form" method="post" accept-charset="utf-8" enctype="multipart/form-data"  onSubmit="document.getElementById('file-upload-status').innerHTML='Uploading files...<br>'">
      <p>
        <b>RELEVANT FILES</b>
        <input type="button" id="file-toggle" value='Edit' onclick="toggleFileEdit()"></input>
        <input type="hidden" id="files-form-action" name="form_action" value="upload-files" required></input>
      </p><hr>
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
      <p class="file-input-toggleable" type="file" id="appt_files[]"><span></span></p>
      <p>
        <span id="file-upload-status">{$html_strings["file_insert_outcome"]}</span><br>
        <input class="hide" type="submit" id="file-submit" value='Submit'></input>
        <input class="hide" type="reset" id="file-reset" value='Clear' ></input>
      </p>
    </form>
    <p></p>
  </div>
HRD;
  }
?>
</div>
<style>
  .textarea-box{
    position: relative;
  }
  .textarea-mbox{
    min-width: 100%;
    max-width: 100%;
    padding-right: 4.5rem;
  }
  .textarea-mbutton{
    position: absolute;
    right: 1.5rem;
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
    font-size: 2em;
    font-weight: bold;
  }
  .error-message{
    font-size: 1.5em;
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
    font-size: 2em;
  }

  .patient-form, .pmh-input-toggleable > textarea{
    min-width: calc(100% - 1.25rem);
    max-width: calc(100% - 1.25rem);
  }

  .appt-header{
    font-weight: bold;
    background: #eee;
  }
  .appt-table-row{
    cursor:pointer;
  }
  .pt-appt-table{
    border: 1px solid black;
  }
  .appt-table-row:hover td{
    background: #aaf;
  }

</style>
<script>
/*
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
*/
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

function toggleAPEdit(){
  let input
  let input2
  let docList = document.querySelectorAll(".ad-input-toggleable")
  let i
  if(docList[0].firstChild.nodeName!=="INPUT" && docList[0].firstChild.nodeName!=="SELECT"){
    document.getElementById("ad-toggle").value="Cancel"
    document.getElementById("ad-submit").style.display="inline"
    document.getElementById("ad-reset").style.display="inline"
    for(i=0;i<docList.length;i++){
      input = document.createElement("input")
      input.placeholder="Type something..."
      input.name=docList[i].id
      if(docList[i].firstChild===null){
        input.value=""
      }else if(docList[i].firstChild.nodeName=="#text"){
        input.value=unhtmlentities(docList[i].innerHTML)
      }
      if(typeof(docList[i].attributes.type) ==="undefined"){
        input.type="text"
        input.placeholder="Type something..."
        input.required="required"
      }else{
        switch(docList[i].attributes.type.value){
          case "datetime":
            let dth = document.createElement("span")
            input.type="date"
            input.name += "_date"
            let dt = new Date(Date.parse(docList[i].innerHTML))
            input.value=dt.getFullYear()+(dt.getMonth()<9?"-0":"-")+(dt.getMonth()+1)+(dt.getDate()<10?"-0":"-")+(dt.getDate())
            input.required="required"
            input2 = document.createElement("input")
            input2.type="time"
            input2.name=docList[i].id+"_time"
            input2.value=(dt.getHours()<10?"0":"")+dt.getHours()+(dt.getMinutes()<10?":0":":")+dt.getMinutes()
            dth.appendChild(input)
            dth.appendChild(input2)
            input=dth
            break;
          case "status":
            input=document.createElement("select")
            input.name=docList[i].id;
            let options = ["Cancelled", "Booked", "Awaiting Results", "Attended", "Completed", "Did Not Attend"]
            for(let j=0;j<options.length;j++){
              let o = document.createElement("option")
              o.value=j
              o.innerHTML=options[j]
              if(docList[i].innerHTML==options[j]){
                o.setAttribute ("selected", "selected");
              }
              input.appendChild(o)
            }
            break;
          case "trust_selector":
            input = document.createElement("select")
            input.name = docList[i].id
            input.id = docList[i].id + "_selector"
            for(let j=0;j<authed_user.usergroups.length;j++){
              let o = document.createElement("option")
              o.value=parseInt(authed_user.usergroups[j].usergroup_id)
              o.innerHTML=atob(authed_user.usergroups[j].group_name)
              input.appendChild(o)
            }
            break;
          case "dropdown":
            let cb = document.createElement("div")
            cb.className="dropdown"
            input2 = document.createElement("input")
            input2.id="appt-loc-input"
            input2.required="required"
            input2.placeholder="Type something..."
            input2.name=docList[i].id+"_new"
            cb.appendChild(input2)
            input=document.createElement("select")
            input.id="appt-loc-select"
            input.name=docList[i].id
            for(j=0;j<appt_location_list.length;j++){
              let o = document.createElement("option")
              o.value=parseInt(appt_location_list[j].id)
              o.innerHTML=atob(appt_location_list[j].location_name)
              if(docList[i].innerHTML==o.innerHTML){
                input2.value=o.innerHTML
                o.setAttribute ("selected", "selected");
              }
              input.appendChild(o)
            }
            let o = document.createElement("option")
            o.value=-1
            o.innerHTML="Custom value..."
            input.appendChild(o)
            cb.appendChild(input)
            input.addEventListener("change",function(){try{updateALComboBox()}catch(e){}})
            input2.addEventListener("change",function(){try{document.getElementById('appt-loc-select').value=-1}catch(e){}})
            input=cb;
            break;
          default:
          break;
        }
      }
      docList[i].save = docList[i].innerHTML
      docList[i].innerHTML=""
      docList[i].appendChild(input)
    }
  }else{
    document.getElementById("ad-toggle").value="Edit"
    document.getElementById("ad-submit").style.display="none"
    document.getElementById("ad-reset").style.display="none"
    for(i=0;i<docList.length;i++){
      docList[i].innerHTML=docList[i].save
    }
  }
}

function toggleFileEdit(){
  let input
  let docList = document.querySelectorAll(".file-input-toggleable")
  let i
  if(docList[0].firstChild.nodeName!=="INPUT"){
    document.getElementById("file-toggle").value="Cancel"
    document.getElementById("file-submit").style.display="inline"
    document.getElementById("file-reset").style.display="inline"
    input = document.createElement("input")
    input.type="file"
    input.multiple="multiple"
    input.placeholder="Type something..."
    input.required="required"
    input.name=docList[0].id
    if(docList[0].firstChild.nodeName=="#text"){
      input.value=unhtmlentities(docList[0].innerHTML)
    }
    docList[0].save = docList[0].innerHTML
    docList[0].innerHTML=""
    docList[0].appendChild(input)
  }else{
    document.getElementById("file-toggle").value="Edit"
    document.getElementById("file-submit").style.display="none"
    document.getElementById("file-reset").style.display="none"
    for(i=0;i<docList.length;i++){
      docList[i].innerHTML=docList[i].save
    }
  }
}
/*
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
*/
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

function handleFileDisplays(n){

}
//copied text beyond this point
  function addAppointmentRow(table,info){
    let newRow=document.createElement("tr")
    newRow.className="appt-table-row responsive"
    newRow.addEventListener("click",function(){window.location="../appointment/"+info['id']})
    const headers=["appt_name","due_date","attended_date","last_reminder","cancelled"]
    let i
    for(i=0;i<headers.length;i++){
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
            newCell.innerHTML=htmlentities(info["last_reminder"])
          }else{
            newCell.innerHTML="<i>No reminder sent.</i>"
          }
          newCell.innerHTML += "<span class='message-remind'> [Send Reminder]</span>"
          newCell.addEventListener("click",function(e){
            e.stopPropagation()
            document.getElementById("message_box").value="Hello, your doctor would like to remind you that you have an appointment at "+atob(info['location_name'])+" scheduled for "+info['due_date']+" for "+atob(info['appt_purpose'])+"."
          })
        break;
        case 4:
          if(parseInt(info["cancelled"])==0){
            if(info["attended_date"]==null){
              newCell.innerHTML="Booked"
            }else{
              newCell.innerHTML="Attended"
            }
          }else{
            newCell.innerHTML="Cancelled"
          }
        break;
        default:
        break;
      }
      newRow.appendChild(newCell)
    }
    table.appendChild(newRow)
  }
      
  function modalify(e,content=undefined){
    if(e===null){
      document.getElementById("mAnchor").remove()
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
fileGroupDisplay(0)
  /*
  //Unconditionally generate HTML
  if(booked_appt_list.length > 0){
    let th = document.createElement("tr")
    th.className="appt-header"
    let headers = ["Appointment Name","Scheduled For", "Date Attended", "Last Reminder", "Status"]
    let i
    for(i=0;i<headers.length;i++){
      let newCell=document.createElement("td")
      newCell.innerHTML=headers[i]
      th.appendChild(newCell)
    }
    document.getElementById("pt-appt-table").appendChild(th)
    for(let appt=0;appt<booked_appt_list.length;appt++){
      addAppointmentRow(document.getElementById("pt-appt-table"),booked_appt_list[appt])
    }
  }else{
    document.getElementById("pt-appt-table").remove()
    document.getElementById("reminder-for").remove()
  }
  */
</script>
</body>
</html>
