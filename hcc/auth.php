<?php
function getGoogleOAuthToken($scope="https://gmail.googleapis.com/auth/devstorage.read_only",$valid=100){
  global $access;
  $jwt="eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.";//hardcoded jwt header
  $claim=Array(
    "iss"=>$access["email"]["client_email"],
    "scope"=>$scope,
    "aud"=> "https://oauth2.googleapis.com/token",
    "exp"=>(strtotime("now")+intval($valid)),
    "iat"=>strtotime("now")
  );
  $jwt .= base64_encode(json_encode($claim));
  $jwt_signature = "";
  openssl_sign($jwt,$jwt_signature,openssl_pkey_get_private($access["email"]["private_key"]),"sha256WithRSAEncryption");
  $jwt.= ".". base64_encode($jwt_signature);
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL,"https://oauth2.googleapis.com/token");
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS,Array("grant_type"=>"urn:ietf:params:oauth:grant-type:jwt-bearer","assertion"=>$jwt));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $token = json_decode(curl_exec($ch));
  curl_close($ch);
  if(isset($token->access_token)){
    return Array("status"=>"success", "message"=>[$token->access_token]);
  }else if(isset($token->id_token)){
    return Array("status"=>"success", "message"=>[$token->id_token]);
  }else{
    return Array("status"=>"error", "message"=>[$token->error_description]);
  }
}

function pdo_init(){
  global $access;
  return new PDO('mysql:host=' . $access['mysql']['server'] . ';dbname='. $access['mysql']['db_name'] .';charset=utf8mb4', $access['mysql']['username'], $access['mysql']['password']);
}
function pdo_kill($db){
  $db=null;
}
// Clean patient token and set htmlentities - remember to edit this if you are adding new fields
function clean_patient_token($token){
  $keys=["id","hospital_id","government_id","name","dob","contact_detail","contact_type", "usergroup_id","username","last_appt_date","next_appt_date","last_reminder_sent","pmh_config","research_consent","usergroup_name"];
  $res = Array(
    "usergroup_name" => "<i>None</i>",
    "last_appt_date" => "<i>No appointment attended</i>",
    "next_appt_date" => "<i>No appointment found</i>",
    "last_reminder_sent" => "<i>No reminder sent</i>",
    "research_consent" => "No",
    "usergroup_name" => "<i>None</i>",
  );
  foreach($keys as $what){
    if(isset($token -> $what)){
      switch($what){
        case "id":
          $res[$what] = intval($token -> $what);
          break;
        case "hospital_id":
          $res[$what] = htmlentities(base64_decode($token -> $what));
          break;
        case "government_id":
          $res[$what] = htmlentities(base64_decode($token -> $what));
          break;
        case "name":
          $res[$what] = htmlentities(base64_decode($token -> $what));
          break;
        case "usergroup_name":
           $res[$what] = htmlentities(base64_decode($token -> $what));
           break;
        case "contact_detail":
          $res[$what] = htmlentities(base64_decode($token -> $what));
          break;
        case "contact_type":
          $res[$what] = intval($token -> $what);
          break;
        case "username":
          $res[$what] = htmlentities(base64_decode($token -> $what));
          break;
         case "last_appt_date":
          $res[$what] = htmlentities($token->$what);
          break;
        case "next_appt_date":
          $res[$what] = htmlentities($token->$what);
          break;
        case "last_reminder_sent":
          $res[$what] = htmlentities($token->$what);
          break;
        case "pmh_config":
          $res[$what] = json_decode(base64_decode($token -> $what),true);
          foreach ($res[$what] as $key => $value) {
            $res[$what][$key]=htmlentities($value);
          }
        break;
        case "research_consent":
          if($token -> $what == true){
            $res[$what] = "Yes";
          }
        break;
        default:
          $res[$what] = htmlentities($token -> $what);
        break;
      }
    }
  }
  return $res;
}

// Clear token cookie, and if page is not login page, redirect there and end execution
function kill_session(){
  global $domain, $rel_pos;
  setrawcookie("token", "^", 1, "/", $domain);
  session_regenerate_id(true);
  sleep(1);
  if(preg_match('/login\/index.php/',$_SERVER['SCRIPT_NAME']) == False){
    header('Location: '. $rel_pos .'/login');
    die();
  }
}

//Helper function that checks if user is responsible for an appointment
function test_doctor_owns_appointment($authed_user,$appointment_id){
  if($authed_user['privilege']>=4){
    return true;
  }
  try{
    // For the given appt_id, Does the given appt exist, and if so who is the doctor/ug in charge?
    $stmt = $db->prepare('SELECT doctor_id,usergroup FROM appointments WHERE appointment_id=?;');
    $stmt->execute([intval($patient_id)]);
    $res = $stmt->fetchAll(PDO::FETCH_CLASS);
    if(count($res)>0){
      if(intval($res[0]->doctor_id) == intval($authed_user["id"])){
        return true;
      }
      $ug = intval($res[0]->usergroup);
      foreach ( $authed_user["usergroups"] as $test ) {
        if( $ug == intval($test->usergroup_id)) {
          return true;
        }
      }
    }
  }catch(Exception $e){
    return false;
  }
  return false;
}

//Helper function that takes authed_user param and sees if is a member of given usergroup
function test_user_in_group($authed_user,$usergroup){
  if($authed_user['privilege']>=4){
    return true;
  }
  $usergroup = intval($usergroup);
  foreach ( $authed_user["usergroups"] as $test ) {
    if( $usergroup == intval($test->usergroup_id)) {
      return true;
    }
  }
  return false;
}
//Helper function that tests if authed_user is in a list of usergroups
function compare_usergroups($authed_user,$usergroups){
  if($authed_user['privilege']>=4){
    return true;
  }
  $user_memberships = [];
  foreach ( $authed_user["usergroups"] as $m ) {
    array_push($user_memberships,$m->usergroup_id);
  }
  if(count(array_intersect($user_memberships,$usergroups))>0){
    return true;
  }else{
    return false;
  }
}

//Helper function that tests if authed_user is in the patient's usergroup
function is_doctor_responsible($db,$authed_user,$patient_id){
  if($authed_user['privilege']>=4){
    return true;
  }
  $user_memberships = [];
  foreach ( $authed_user["usergroups"] as $m ) {//put usergroups in list
    array_push($user_memberships,intval($m->usergroup_id));
  }
  try{
    // For the given patient_id, Does the given template id exist, and if so what is the corresponding usergroup id?
    $stmt = $db->prepare('SELECT usergroup_id FROM patient_membership_table WHERE patient_id=?;');
    $stmt->execute([intval($patient_id)]);
    $res = $stmt->fetchAll(PDO::FETCH_CLASS);
    foreach($res as $what){
      if(in_array($what->usergroup_id,$user_memberships) ){
        return true;
      }
    }
  }catch(Exception $e){
    return false;
  }
  try{
    // Test if doctor is listed as lead consultants
    $stmt = $db->prepare('SELECT id FROM patient WHERE id=? AND lead_consultant=?;');
    $stmt->execute([intval($patient_id),$authed_user["id"]]);
    $res = $stmt->fetchAll(PDO::FETCH_CLASS);
    if(count($res)>=1){
      return true;
    }
  }catch(Exception $e){
    return false;
  }
  return false;
}

function base64_offsets($text){
  $text2 = ucfirst($text);
  return [
    "%".preg_replace('/[A-Za-z0-9]\=+/', '', base64_encode($text))."%",
    "%".preg_replace('/[A-Za-z0-9]\=+/', '', substr(base64_encode(' '.$text),2))."%",
    "%".preg_replace('/[A-Za-z0-9]\=+/', '', substr(base64_encode('  '.$text),3))."%",
    "%".preg_replace('/[A-Za-z0-9]\=+/', '', base64_encode($text2))."%",
    "%".preg_replace('/[A-Za-z0-9]\=+/', '', substr(base64_encode(' '.$text2),2))."%",
    "%".preg_replace('/[A-Za-z0-9]\=+/', '', substr(base64_encode('  '.$text2),3))."%"
  ];
}

//look at cookie uid for phpsessid
session_name('uid');
//start or resume session with phpsessid, if error flush phpsessid and token cookie and refresh page
try{
  session_start([
    'cookie_lifetime' => 3600,
  ]);
  //init post hash field if not initially set
  if(!isset($_SESSION["phash"])){
    $_SESSION["phash"]="";
  }
}catch (Exception $e){
  kill_session();
}

//create user object to pass to calling script
$authed_user=Array(
 "id"=>0,
 "privilege"=>0,
);
if(!isset($min_priv) ){
  $min_priv=1; //default priv to view
}
if(!isset($_COOKIE['token']) ){
  kill_session();
}else{
  try{
    $token=explode("^",$_COOKIE['token'],2); //try to extract base64(user) and sha3(sha3(password) + sha3(login_token))
    $db = pdo_init();
    $stmt = $db->prepare('SELECT * FROM doctor WHERE username=?;');
    $stmt->execute([base64_encode($token[0])]);
    $res = $stmt->fetchAll(PDO::FETCH_CLASS); //returns array of all matching users
    if(count($res) <= 0){
      pdo_kill($db);
      kill_session(); //reset all existing cookies if no user found
    }else{
      if($res[0]->privilege < $min_priv || $res[0]->id <= 0){
        sleep(1); //ratelimit
        die(); //die if user priv < min priv to view or if userid is lte 0 but retain session 
      }
      //hash with stored token
      $token_validation = hash('sha512',hash('sha512',$res[0]->password_hash) . hash('sha512',$res[0]->login_token));
      //hash with new token 
      //either old token is expired or we lost our old still valid token
      $token_validation1 = hash('sha512',hash('sha512',$res[0]->password_hash) . hash('sha512',hash('sha512',$_COOKIE['uid'])));
      // check if cookie hash matches hash with in date stored token, if not, does it match the hash with new token provided that it does not coincide with the old token?
      if(($token_validation == $token[1] && strtotime($res[0]->login_until) > time()) || ($token_validation1 != $token_validation && $token_validation1 == $token[1])){
        $authed_user["id"] = $res[0]->id;
        $authed_user["privilege"] = $res[0]->privilege;
        $authed_user["info"] = Array(
          "username" => $res[0]->username,
          "login_until" => $res[0]->login_until,
          "config" => $res[0]->config,
          "preferred_contact" => $res[0]->preferred_contact
        );
        $authed_user["usergroups"] = [];
        $stmt2 = $db->prepare('UPDATE doctor SET login_token = ? , login_until = ? WHERE id=?;'); //update login time in db
        if(isset($logout)){
          //if we want to logout, clear current active login sessions
          $stmt2->execute([NULL,date("Y-m-d H:i:s",0),$res[0]->id]);
          kill_session();
        }else{
          //if contingency triggered, update login token we will store in database
          if($token_validation1 == $token[1]){
            $utoken = hash('sha512',$_COOKIE['uid']);
          }
          //store login token in database
          $stmt2->execute([$utoken,date("Y-m-d H:i:s",(time() + 3600)),$res[0]->id]);
          //populate info on doctor's usergroups
          $stmt3 = $db->prepare('SELECT usergroup_id,group_name,descr FROM doctor_membership_table INNER JOIN usergroup ON doctor_membership_table.usergroup_id = usergroup.id WHERE doctor_membership_table.doctor_id = ? ORDER BY doctor_membership_table.usergroup_id ASC;'); //get membership info
          $stmt3->execute([$res[0]->id]);
          $authed_user["usergroups"] = $stmt3->fetchAll(PDO::FETCH_CLASS);
          if(preg_match('/login\/index.php/',$_SERVER['SCRIPT_NAME'])){
            header('Location: '. $rel_pos .'/'); //Logged in, redirect to home page on success
          }else{
            //update cookie expiry
            setcookie("uid",$_COOKIE["uid"],time()+3600,"/");
            setcookie("token",$_COOKIE["token"],time()+3600,"/");
            //test for repeated post submissions, if match phash clear else update phash
            if(count($_POST)>0){
              $phash = hash("sha512",json_encode($_POST));
              if($_SESSION["phash"]==$phash){
                $_POST=[];
                header('Location: '.$_SERVER['REQUEST_URI']);
                $_SESSION["phash"]="";
                exit();
              }else{
                $_SESSION["phash"]=$phash;
              }
            }
          }
        }
        pdo_kill($db);
      }else{
        pdo_kill($db); //close and kill session as invalid token
        kill_session();
      }
    }
  } catch (Exception $e){
    pdo_kill($db);
    kill_session();
  }
}
// returns a global variable $authed_user with parameters id[user id], privilege[user_privilege], info [username,login_until,config,preferred_contact], and usergroups[array of [usergroup_id,group_name]]
?>
