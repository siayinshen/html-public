<?php
  $rel_pos="hcc";
  $min_priv=-1;
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/config.php";
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/patient_auth.php";
  var_dump($authed_patient);
?>
<!DOCTYPE html>
<html lang="en-US">
<head>
<?php
  $page_vars = Array(
    "title" =>"Patient Login",
  );
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/pageinit.php";
?>
<style>
  .form-wrapper{
    display: flex;
    height: 100vh;
    align-items: center;
    justify-content:center;
  }
  .login-form{
    border: 1px solid black;
    border-radius: 0.5em;
    background: #eee;
    padding: 0 1rem;
    width: 50%;
    min-width: min-content;
  }
  .login-form-pw-wrapper{
    position: relative
  }
  .login-form-input{
    width: 50%;
    min-width: 10rem;
  }
  .login-form-pw{
    width: calc(50% - 4rem);
    min-width: 5rem;
    padding-right: 4.5rem;
  }
  .login-form-toggle{
    position: absolute;
    right: 1rem;
    top: 60%;
    transform: translateY(-50%);
  }
</style>
</head>
<body>
<?php
?>
<div class="form-wrapper">
<form class="login-form" onSubmit="validate()" id="loginForm">

<?php
if(intval($authed_patient["id"])>0){
$apnum = base64_decode($res[0]->government_id);
  if($authed_patient["is_pw_set"]){
    echo <<<HRD
<p class="em1">Please set a password to proceed.</p>
<p>
  <input class="login-form-input" id="login-username" type="text" placeholder="Enter your NHS number..." value="$apnum"></input>
</p>
<p>
  <span class="login-form-pw-wrapper">
  <input class="login-form-input login-form-pw" id="login-password" type="password" placeholder="Enter your password..."></input>
  <span class="noselect login-form-toggle" id="pw-toggle" onclick="togglePW(0)">SHOW</span>
  </span>
</p>
<p>
  <input type="submit" value="Login">
  <input type="reset" value="Reset">
</p>
HRD;
  }else{
    echo <<<HRD
<p class="em1">Please enter your credentials to proceed.</p>
<p>
  <input class="login-form-input" id="login-username" type="text" placeholder="Enter your NHS number..." value="$apnum"></input>
</p>
<p>
  <span class="login-form-pw-wrapper">
  <input class="login-form-input login-form-pw" id="login-password" type="password" placeholder="Please secure your access with a password..."></input>
  <span class="noselect login-form-toggle" id="pw-toggle" onclick="togglePW(0)">SHOW</span>
  </span>
</p>
<p>
  <span class="login-form-pw-wrapper">
  <input class="login-form-input login-form-pw" id="confirm-password" type="password" placeholder="Confirm your password..."></input>
  <span class="noselect login-form-toggle" id="pw-toggle" onclick="togglePW(1)">SHOW</span>
  </span>
</p>
<p>
  <input type="submit" value="Set Password">
  <input type="reset" value="Reset">
</p>
HRD;
  }
}else{
  echo <<<HRD
<p>
  <p class="em1">Please enter your NHS number to begin.</p>
  <input class="login-form-input" id="login-username" type="text" placeholder="Enter your NHS number..."></input>
</p>
<p>
  <input type="submit" value="Login">
  <input type="reset" value="Reset">
</p>
HRD;
}
?>

</form>
</div>
<script>
<?php include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/api/js/sha3.js";
if(intval($authed_patient["id"])>0){
  if($authed_patient["is_pw_set"]){
    echo <<<HRD
function validate(){
  let date = new Date();
  date.setTime(date.getTime() + 3600000)
  let pw = sha3(sha3(document.getElementById('login-password').value))
  let pid = document.cookie.split(";").find(x=>x.split("pid=")[1]).split("pid=")[1]
  if(typeof(pid)==="undefined"){
    pid= sha3(pid)
  }else{
    pid = sha3(sha3(pid))
  }
  document.cookie = "token=" + document.getElementById('login-username').value + "^" + sha3(pw+pid) + "; expires=" + date.toUTCString() + "; path=/"
  location.reload()
}
HRD;  
  }else{
    echo <<<HRD
function validate(){
  let date = new Date();
  date.setTime(date.getTime() + 3600000)
  let pw = sha3(sha3(document.getElementById('login-password').value))
  let pw2 = sha3(sha3(document.getElementById('confirm-password').value))
  if(pw===pw2){
    let pid = document.cookie.split(";").find(x=>x.split("pid=")[1]).split("pid=")[1]
    if(typeof(pid)==="undefined"){
      pid= sha3(pid)
    }else{
      pid = sha3(sha3(pid))
    }
    document.cookie = "token=" + document.getElementById('login-username').value + "^" + sha3(pw+pid) + "; expires=" + date.toUTCString() + "; path=/"
    location.reload()
  }
}
HRD;
  }
}else{
  echo <<<HRD
function validate(){
  let date = new Date();
  date.setTime(date.getTime() + 3600000)
  document.cookie = "token=" + document.getElementById('login-username').value + "^" + sha3("") + "; expires=" + date.toUTCString() + "; path=/"
  location.reload()
}
HRD;
}

?>

function togglePW(n){
  let pwField=document.querySelectorAll("[class$='login-form-pw']")[n]
  let pwToggle=document.querySelectorAll("[id='pw-toggle']")[n]
  if(pwField.type=="password"){
    pwField.type="text"
    pwToggle.innerHTML="HIDE"
  }else{
    pwField.type="password"
    pwToggle.innerHTML="SHOW"
  }
}
document.getElementById("loginForm").addEventListener('submit', function(){ validate();}, false);
</script>
</body>
