<?php
  $rel_pos="/hcc";
  $min_priv=0;
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/config.php";
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/auth.php";
?>
<!DOCTYPE html>
<html lang="en-US">
<head>
<?php
  $page_vars = Array(
    "title" =>"Login",
  );
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/pageinit.php";
?>
<style>
  .form-wrapper{
    display: flex;
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
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/header.php";
?>
<div class="form-wrapper">
<form class="login-form" onSubmit="validate()" id="loginForm">
<p class="em1">Login to database:</p>
<p>
  <input class="login-form-input" id="login-username" type="text" placeholder="Username..."></input>
</p>
<p>
  <span class="login-form-pw-wrapper">
  <input class="login-form-input login-form-pw" id="login-password" type="password" placeholder="Password..."></input>
  <span class="noselect login-form-toggle" id="pw-toggle" onclick="togglePW()">SHOW</span>
  </span>
</p>
<p>
  <input type="submit" value="Login">
  <input type="reset" value="Reset">
</p>
</form>
</div>
<script>
<?php include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/api/js/sha3.js"; ?>
function validate(){
  let date = new Date();
  date.setTime(date.getTime() + 3600000)
  let pw = sha3(sha3(document.getElementById('login-password').value))
  let uid = document.cookie.split(";").find(x=>x.split("uid=")[1]).split("uid=")[1]
  if(typeof(uid)==="undefined"){
    uid= sha3("")
  }else{
    uid = sha3(sha3(uid))
  }
  document.cookie = "token=" + document.getElementById('login-username').value + "^" + sha3(pw+uid) + "; expires=" + date.toUTCString() + "; path=/"
  location.reload()
}

function togglePW(){
  let pwField=document.getElementById('login-password')
  let pwToggle=document.getElementById('pw-toggle')
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
