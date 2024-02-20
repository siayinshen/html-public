<?php
 function pdo_init(){
  global $access;
  return new PDO('mysql:host=' . $access['mysql']['server'] . ';dbname='. $access['mysql']['db_name'] .';charset=utf8mb4', $access['mysql']['username'], $access['mysql']['password']);
}
function pdo_kill($db){
  $db=null;
}

// Clear token cookie, and if page is not login page, redirect there and end execution
function kill_session(){
  global $domain, $rel_pos;
  echo "Hello there";
  setrawcookie("token", "^", 1, "/", $domain);
  session_regenerate_id(true);
  sleep(1);
  if(preg_match('/contact\/login\/index.php/',$_SERVER['SCRIPT_NAME']) == False){
    header('Location: '. $rel_pos .'/contact/login');
    die();
  }
}

//Helper function that checks if user has an appointment
function test_patient_has_appointment($authed_patient,$appointment_id){
  try{
    if(isset($appointment_id)){
      $stmt = $db->prepare('SELECT id FROM appointment WHERE patient_id=? AND id=?;');
      $stmt->execute([intval($authed_patient["id"]),intval($appointment_id)]);
      $res = $stmt->fetchAll(PDO::FETCH_CLASS);
      if(count($res)>0){
        return true;
      }
    }
    return false;
  }catch(Exception $e){
    return false;
  }
}

//Helper function that takes authed_patient param and sees if is a member of given usergroup
function test_user_in_group($authed_patient,$usergroup){
  $usergroup = intval($usergroup);
  foreach ( $authed_patient["usergroups"] as $test ) {
    if( $usergroup == intval($test->usergroup_id)) {
      return true;
    }
  }
  return false;
}
//Helper function that tests if authed_patient is in a list of usergroups
function compare_usergroups($authed_patient,$usergroups){
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
//look at cookie pid for phpsessid
session_name('pid');
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
$authed_patient=Array(
 "id"=>0,
 "privilege"=>-1,
 "is_pw_set"=>False
);
if(!isset($min_priv) ){
  $min_priv=0; //default priv to view
}
if(!isset($_COOKIE['token']) ){
  kill_session();
}else{
  try{
    $token=explode("^",$_COOKIE['token'],2); //try to extract base64(user) and sha3(sha3(password))
    $db = pdo_init();
    $stmt = $db->prepare('SELECT * FROM patient WHERE government_id=?;');
    $stmt->execute([base64_encode($token[0])]);
    $res = $stmt->fetchAll(PDO::FETCH_CLASS); //returns array of all matching users
//var_dump($res[0]);
    if(count($res) <= 0){
      pdo_kill($db);
      kill_session(); //reset all existing cookies if no user found
    }else{
      if($authed_patient['privilege'] < $min_priv || $res[0]->id <= 0){
        sleep(1); //ratelimit
        die(); //die if user priv < min priv to view or if userid is lte 0 but retain session 
      }
      //if there is a password then proceed, otherwise abort at this stage and set it to null
      if($res[0]->unique_ref == ''){
        $authed_patient['id']=intval($res[0]->id);
      }else{
        $authed_patient['privilege']==0;
        $authed_patient['is_pw_set']==True;
        //hash with stored token
        $token_validation = hash('sha512',hash('sha512',$res[0]->unique_ref).hash('sha512',$_COOKIE['pid']));
        // retrieve token
        if($token_validation == $token[1]){
          $authed_patient["id"] = $res[0]->id;
          $authed_patient["privilege"] = 0;
          $authed_patient["info"] = Array(
            "name" => $res[0]->name,
            "preferred_contact" => $res[0]->preferred_contact
          );
          $authed_patient["usergroups"] = [];
          if(isset($logout)){
            //if we want to logout, clear current active login sessions
            kill_session();
          }else if($authed_patient['is_pw_set']){
            //if contingency triggered, update login token we will store in database
            if($token_validation == $token[1]){
              $utoken = hash('sha512',$_COOKIE['pid']);
            }
            //populate info on doctor's usergroups
            $stmt3 = $db->prepare('SELECT patient_membership_table.usergroup_id,usergroup.group_name,usergroup.descr FROM patient_membership_table INNER JOIN usergroup ON patient_membership_table.patient_id = usergroup.id WHERE patient_membership_table.patient_id = ? ORDER BY patient_membership_table.usergroup_id ASC;'); //get membership info
            $stmt3->execute([$res[0]->id]);
            $authed_patient["usergroups"] = $stmt3->fetchAll(PDO::FETCH_CLASS);
            if(preg_match('/contact\/login\/index.php/',$_SERVER['SCRIPT_NAME'])){
              header('Location: '. $rel_pos .'/contact'); //Logged in, redirect to patient home page on success
            }else if(!preg_match('/'.$rel_pos.'\/contact/',$_SERVER['SCRIPT_NAME'])){
              //header('Location: '. $rel_pos .'/contact'); //Redirect to 
            }else{
              //update cookie expiry
              setcookie("pid",$_COOKIE["pid"],time()+3600,"/");
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
    }
  } catch (Exception $e){
    pdo_kill($db);
    kill_session();
  }
}

// returns a global variable $authed_patient with parameters id[patient_id], privilege[user_privilege], info [contents of patient table entry], and usergroups[array of [usergroup_id,group_name]]
?>
