<?php
  $rel_pos="/hcc";
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/config.php";
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/auth.php";
?>
<!DOCTYPE html>
<html lang="en-US">
<head>
<?php //var_dump($authed_user["info"]["username"]);
  $page_vars = Array(
    "title" =>"User Settings",
  );
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/pageinit.php";
?>
</head>
<body>
<?php
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/header.php";
?>
<?php
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/api/scripts/usergroup.php";
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/api/scripts/doctor.php";
  //placeholder html strings
  $html_strings=Array(
   "pw_change"=>""
  );
  $user_access_level_html=["no access", "read only access", "read and write access", "trust level access", "full system access"][intval($authed_user['privilege'])];
  $db=pdo_init();
  if(isset($_POST['form_action'])){
    switch($_POST['form_action']){ // picks which function to trigger based on hidden input
      case "change_pw": //basic input sanitisation
        if(isset($_POST['old_pw_hash']) && isset($_POST['new_pw_hash'])){
          $outcome_change = changeMyPassword($db,$_POST['old_pw_hash'],$_POST['new_pw_hash']);
          $html_strings["pw_change"] = "<span class='message-".$outcome_change["status"]."'>".implode("<br>",$outcome_change["message"])."</span><br>";
          if($outcome_change["status"]=="success"){//too late to send new header so we update using JS
            echo "<script>document.cookie='token=".base64_decode($authed_user["info"]["username"])."^".hash('sha512',hash('sha512',$_POST['new_pw_hash']).hash('sha512',hash('sha512',$_COOKIE['uid']))).";expires=".date("D, d M Y H:i:s T", time()+3600000)."; path=/'</script>";
          }
        }
      break;
      default:
      break;
    }
  }

  $usergroups_html=[];
  foreach($authed_user["usergroups"] as $ug){
    array_push($usergroups_html,"<span>" . base64_decode($ug->group_name) . " (<i>". base64_decode($ug->descr) ."</i>)</span>");
  }
  $ug_count = count($usergroups_html);
  $usergroups_html=implode("<br>",$usergroups_html);
  
  echo(<<<HRD
    <div class="page-content">
      <div class="generic-info">
      <form class="generic-info-box" id="user-settings" method="post">
      <input type="hidden" name="form_action" id="form_action"></input>
      <p><b>SETTINGS</b><hr></p>
      <p>You have {$user_access_level_html}.</p>
      <p>You are a member of {$ug_count} trust(s):<br>
       {$usergroups_html}
      </p>
      <p>
      Type your current password here before making any changes:<br>
      <input type="hidden" name="old_pw_hash" id="old_pw_hash"></input>
      <input type="text" name="old_pw" id="old_pw" placeholder="Current Password"></input><br>
      </p>
      <p>Your listed contact details are:<br>
      <i>No listed contact details</i><br>
      <input type="text" placeholder="Contact number">
      <select>
        <option value="1">Phone</option>
        <option value="2">Email</option>
      </select>
      <input type="submit" value="Add Contact"><input type="reset" value="Clear">
      </p>
      <p>Change password:<br>
      <input type="hidden" name="new_pw_hash" id="new_pw_hash"></input>
      <input type="text" name="new_pw" id="new_pw" placeholder="New Password"></input><br>
      <input type="text" name="new_pw1" id="new_pw1" placeholder="Confirm New Password"></input><br>
      {$html_strings["pw_change"]}<span id="pw-outcome"></span>
      <input type="button" value="Change Password" onclick="validatePwChange()"><input type="reset" value="Clear">
      </p>
      </form>
      </div>
    </div>
HRD);
  //var_dump(createUsergroup($db,Array("descr"=>"","group_name"=>"abc")));
  pdo_kill($db);
?>
<div>
</div>
<script>
<?php include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/api/js/sha3.js"; //include js for sha3() ?>

function validatePwChange(){
  document.getElementById("form_action").value = "change_pw"
  let new_pw = document.getElementById("new_pw").value
  let old_pw = document.getElementById("old_pw").value
  if(old_pw.length > 0 && new_pw.length > 0){
    if(new_pw === document.getElementById("new_pw1").value){
      document.getElementById("old_pw_hash").value = sha3(old_pw);
      document.getElementById("new_pw_hash").value = sha3(new_pw);
      document.getElementById("user-settings").submit();
    }else{
      document.getElementById("pw-outcome").innerHTML = "Passwords do not match.<br>";
      document.getElementById("pw-outcome").className += "message-error";
    }
  }else{
    document.getElementById("pw-outcome").innerHTML = "Passwords cannot be blank.<br>";
    document.getElementById("pw-outcome").className += "message-error";
  }
}
</script>
</body>
</html>
