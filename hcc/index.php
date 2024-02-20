<?php
  $rel_pos="/hcc";
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/config.php";
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/auth.php";
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
</head>
<body>
<?php
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/header.php";
?>
<?php
  include $_SERVER['DOCUMENT_ROOT'] . $rel_pos . "/api/scripts/usergroup.php";
  $db=pdo_init();
  $usergroups_html=[];
  foreach($authed_user["usergroups"] as $ug){
    array_push($usergroups_html,base64_decode($ug->group_name));
  }
  $usergroups_html=implode(" and ",$usergroups_html);
  echo(<<<HRD
    <div class="page-content">
      <p>You have access under {$usergroups_html}.</p>
    </div>
HRD);
  //var_dump(createUsergroup($db,Array("descr"=>"","group_name"=>"abc")));
  pdo_kill($db);
?>
<div>
</div>
</body>
</html>
