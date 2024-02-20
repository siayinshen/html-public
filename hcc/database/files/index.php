<?php
$rel_pos="/hcc";
include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/config.php";
include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/auth.php";
if(!isset($authed_user) || $authed_user["privilege"]<1){
  http_response_code(451);
  die();//page should be inaccessible if directly accessed or if user is below min priv
}
$file = [];
//find file target and fail if cannot find
if(!preg_match("/[a-z0-9]{128}(?=$|\?|#)/",$_SERVER['REQUEST_URI'],$file)){
  http_response_code(404);
  die();
}
//assume unique content
$stmt = $db->prepare('SELECT * FROM appt_docs WHERE content=?;');
$stmt->execute([$file[0]]);
$res = $stmt->fetchAll(PDO::FETCH_CLASS);
if(count($res)>0){
//does file exist?
  if(test_doctor_owns_appointment($authed_user,$res[0]->appointment_id)){
  //do we have permission to access
    $location = "data/" . $file[0]; //change if you are renaming the data folder
    $fmime = finfo_open(FILEINFO_MIME_TYPE);
    $m = finfo_file($fmime,$location);
    header("Content-Type: $m");
    //fix filename
    header("Content-Disposition: inline;filename=\"".base64_decode($res[0]->filename)."\"");
    header('Cache-Control: public, max-age=315360000, immutable');
    header('Expires: Wed, 1 May 2999 12:00:00 GMT');
    $f = fopen($location,"r");
    echo fread($f, filesize($location));
    fclose($f);
  }else{
    http_response_code(451);
    die();
  }
}else{
  http_response_code(404);
  die();
}
?>
