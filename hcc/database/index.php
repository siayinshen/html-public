<?php
  $rel_pos="/hcc";
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/config.php";
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/auth.php";
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/api/scripts/patient.php";
  $db=pdo_init();
  if(isset($_POST['form_action'])){
    switch($_POST['form_action']){ // picks which function to trigger
      case "create": //basic input sanitisation
        if(isset($_POST['research_consent']) && $_POST['research_consent']=="on"){
          $_POST['research_consent'] = 1;
        }else{
          $_POST['research_consent'] = 0;
        }
        $_POST['preferred_contact'] = Array(
          "contact_detail"=>$_POST['contact_detail'],
          "contact_type"=>intval($_POST['contact_type'])
        );
        $outcome_create = createPatient($db,$_POST); //return the outcome for later use
        break;
      case "search":
        $to_send=[];
        $to_set = Array("hospital_id","government_id","name","dob");
        foreach ($to_set as $what){
          $to_send[$what]=$_POST[$what];
        }
        $outcome_search = findPatient($db,$to_send); //return the outcome for later use
        break;
      case "delete":
        foreach ($_POST as $what){ //iterate over requested deletes and delete
          var_dump($what);
        }
        break;
      default:
        exit();
        break;
    }
  }
?>
<!DOCTYPE html>
<html lang="en-US">
<head>
<?php
  $page_vars = Array(
    "title" =>"Home",
  );
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/pageinit.php";
?>
<style>
  .patient-table{
    width: 95%;
  }
  .patient-table-row-header{
    cursor:pointer;
    font-weight: bold;
    background: #eee;
  }
  .patient-table-row{
    cursor:pointer;
  }
  .patient-table-row:hover td{
    background: #aaf;
  }
  .patient-table-row:nth-of-type(even){
    background: #eef;
  }
  .patient-table-warn{
    background: #ffa !important;
  }
  .patient-table-cell{
  
  }
</style>
</head>
<body>
<?php
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/header.php";
?>

<div class="generic-info">
<form class="generic-info-box-narrow" method="POST">
  <p><b>ADD NEW PATIENT</b></p><hr>
  <input type="hidden" name="form_action" value="create" required></input><br>
  <input type="text" name="hospital_id" placeholder="K number" required></input><br>
  <input type="text" name="government_id" placeholder="NHS number" required></input><br>
  <input type="text" name="name" placeholder="Patient name" required></input><br>
  DOB:<input type="date" name="dob" required></input><br>
  <input type="phone" id="c_det" name="contact_detail" placeholder="Contact details" required></input>
  <?php //see sql table contact_modality before editing option values
    $ug_select_html="";
    foreach($authed_user["usergroups"] as $ug){
      $ug_select_html.= "<option value='" . intval($ug->usergroup_id) . "'>". base64_decode($ug->group_name) ."</option>";
    }
    
  ?>
  <select id="c_typ" name="contact_type">
    <option value="1">Phone</option>
    <option value="2">Email</option>
  </select><br>
  Research consent: <input type="checkbox" name="research_consent"></input><br>
  Responsible trust:
  <select name="usergroup">
    <?php echo $ug_select_html; ?>
  </select><br>
  <textarea name="diagnosis" placeholder="Diagnosis"></textarea><br>
  <textarea name="treatment_plan" placeholder="Treatment Plan"></textarea><br>
  <textarea name="medical_history" placeholder="More Information"></textarea><br>
  <p>
    <input type="submit" value="Create Patient"></input>
    <input type="reset" value="Reset Form"></input>
  </p>
<?php
  if(isset($outcome_create)){
    echo("<span class='message-".$outcome_create["status"]."'>");
    echo(implode("<br>",$outcome_create["message"]));
    echo("</span>");
  }
?>
</form>
<form class="generic-info-box-narrow" method="POST">
  <p><b>PATIENT SEARCH</b></p><hr>
  <input type="hidden" name="form_action" value="search" required></input><br>
  <input type="text" name="hospital_id" placeholder="K number"></input><br>
  <input type="text" name="government_id" placeholder="NHS number"></input><br>
  <input type="text" name="name" placeholder="Patient name"></input><br>
  DOB:<input type="date" name="dob"></input><br>
  <p>
    <input type="submit" value="Search for Patient"></input>
    <input type="reset" value="Reset Form"></input>
  </p>
</form>

</div>
<div class="generic-info">
<?php
//is search set?
if(isset($outcome_search)){
  if($outcome_search["status"]=="success"){
    $search_res_json = json_encode($outcome_search["sql"]);
    if(count($outcome_search["sql"])>0){
      if(count($outcome_search["sql"])==1){$s=="";}else{$s="s";}
      $search_greeter=("<p class='message-success'>Successfully found ".count($outcome_search["sql"])." patient".$s.". </p>");
      echo <<<HRD
    <script>const myPatientList={$search_res_json}</script>
    <div class="generic-info-box">
    <p><b>SEARCH RESULTS</b></p><hr>
    {$search_greeter}
    <table class="patient-table" id="my-patient-table">
    </table>
    <br>
    </div>
    <p></p>
HRD;
    }else{
      echo <<<HRD
    <script>const myPatientList={$search_res_json}</script>
    <div class="generic-info-box">
    <p><b>SEARCH RESULTS</b></p><hr>
    {$search_greeter}
    <span class='message'>Search returned zero patients.</span>
    <p></p>
    </div>
    <p></p>
HRD;
      echo("");
    }
  }else{
    echo("<span class='message-error'>Search function has encountered an error. Error message: <br>".implode("<br>",$outcome_search["message"])."</span>");
  }
}else{
  $my_patients = listMyPatients($db);
  if($my_patients["status"]=="success"){
    $my_patients_json = json_encode($my_patients["sql"]);
    if(count($my_patients["sql"])>0){
      if(count($my_patients["sql"])==1){$s=="";}else{$s="s";}
      $my_patient_greeter=("<p class='message-success'>You have ".count($my_patients["sql"])." patient".$s.". </p>");
      echo <<<HRD
    <script>const myPatientList={$my_patients_json}</script>
    <div class="generic-info-box">
    <p><b>MY PATIENTS</b></p><hr>
    {$my_patient_greeter}
    <table class="patient-table" id="my-patient-table">
    </table>
    <br>
    </div>
    <p></p>
  HRD;
    }else{
      echo("<span class='message-warn'>You have no patients.</span>");
    }
  }else{
    echo("<span class='message-error'>Unable to list any patients. Error message: <br>".implode("<br>",$outcome_create["message"])."</span>");
  }
}
?>

</div>
<script>
  document.getElementById("c_typ").addEventListener("change", function(e){
    document.getElementById("c_det").type=["","phone","email"][parseInt(e.target.value)];
  });
  function addPatientRow(table,info){
    let newRow=document.createElement("tr")
    newRow.className="patient-table-row"
    newRow.addEventListener("click",function(){window.location="./patient/"+info['id']})
    const headers=["k_number","nhs_number","pt_name","contact","doctor","last_appt","next_appt","last_rem","consent"]
    let i
    for(i=0;i<headers.length;i++){
      let newCell=document.createElement("td")
      switch(i){
        case 0:
          if(info["hospital_id"]!==null){
            newCell.innerHTML=htmlentities(atob(info["hospital_id"]))
          }
        break;
        case 1:
          if(info["government_id"]!==null){
            newCell.innerHTML=htmlentities(atob(info["government_id"]))
          }
        break;
        case 2:
          if(info["name"]!==null){
            newCell.innerHTML=htmlentities(atob(info["name"]))
          }
        break;
        case 3:
          if(info["contact_detail"]!==null){
            newCell.innerHTML=htmlentities(atob(info["contact_detail"]))
          }
        break;
        case 4:
          if(info["username"]!==null){
            newCell.innerHTML=htmlentities(atob(info["username"]))
          }
        break;
        case 5:
          if(info["last_appt_date"]!==null){
            newCell.innerHTML=htmlentities((info["last_appt_date"]))
          }else{
            newCell.innerHTML="<i>No appointment attended</i>"
            newRow.className += " patient-table-warn no-appointment"
          }
        break;
        case 6:
          if(info["next_appt_date"]!==null){
            newCell.innerHTML=htmlentities((info["next_appt_date"]))
          }else{
            newCell.innerHTML="<i>No appointment found</i>"
            newRow.className += " patient-table-warn no-appointment"
          }
        break;
        case 7:
          if(info["last_reminder_sent"]!==null){
            newCell.innerHTML=htmlentities((info["last_reminder_sent"]))
          }else{
            newCell.innerHTML="<i>No reminder sent</i>"
            newRow.className += " patient-table-warn no-reminder"
          }
        break;
        case 8:
          if(info["research_consent"]==true){
            newCell.innerHTML="Yes"
          }else{
            newCell.innerHTML="No"
          }
        break;
        default:
        break;
      }
      newRow.appendChild(newCell)
    }
    table.appendChild(newRow)
  }
  function sortList(list,column_names=[],sort=-1,dir=1){
    (dir>0?dir=1:dir=-1)
    sort=parseInt(sort)
    if(sort>=0 && list.length>1){
      let sortCol=column_names[sort]
      list.sort((a,b)=>(dir*(""+a[sortCol]).localeCompare(""+b[sortCol])))
    }
    generatePatientTable(list,sort,dir)
  }
  function generatePatientTable(appt_list,sort=-1,dir=1){
    let aTable = document.getElementById("my-patient-table")
    aTable.innerHTML=""
    if(appt_list.length > 0){
      let th = document.createElement("tr")
      th.className="patient-table-row-header noselect"
      let headers = ["K Number","NHS Number","Patient Name","Preferred Contact Details","Responsible Doctor","Last Attended","Next Appointment","Last Reminder Sent","Research Consent"]
      let i
      for(i=0;i<headers.length;i++){
        let newCell=document.createElement("td")
        let j = i
        newCell.innerHTML=headers[i]
        newCell.addEventListener("click",function(e){
          sortList(appt_list,["hospital_id", "government_id", "name", "contact_detail", "username", "last_appt_date", "next_appt_date", "last_reminder_sent", "research_consent"],j,e.target.getAttribute("dir"))
        })
        if(i==sort){newCell.dir=-dir}else{newCell.dir=1}
        th.appendChild(newCell)
      }
      aTable.appendChild(th)
      for(let pt=0;pt<myPatientList.length;pt++){
        addPatientRow(aTable,myPatientList[pt])
      }
    }else{
      aTable.remove()
      //document.getElementById("reminder-for").remove()
    }
  }
//Unconditionally generate HTML
generatePatientTable(myPatientList)
</script>

<?php
  pdo_kill($db);
?>
</body>
</html>
