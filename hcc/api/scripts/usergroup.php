<?php
//Validated 14/3/23
  //assumes that this is included after auth.php is called
  if(!isset($authed_user) || $authed_user["privilege"]<3){
    http_response_code(451);
    die();//page should be inaccessible if directly accessed or if user is below min priv
  }
  //ADD NEW usergroup
  function createUsergroup($db,$params){
    global $authed_user;
    if($authed_user["privilege"] < 4){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $outcome = Array("status"=>"error","message"=>[]);
    if(isset($params["group_name"]) && strlen(base64_encode($params["group_name"]))<1024){
      $params["group_name"] = base64_encode($params["group_name"]);
    }else{
      array_push($outcome["message"],"Group name too long or not set.");
    }
    if(isset($params["descr"])){
      $params["descr"] = base64_encode($params["descr"]);
    }else{
      $params["descr"] = "";
    }
    if(count($outcome["message"])>0){ //must have no previous issues
      return $outcome;
    }else{
      try{
        $query = $db->prepare('INSERT INTO usergroup(group_name,descr) VALUES(?,?);');
        $query->execute([$params["group_name"],$params["descr"]]);
        $outcome = Array("status"=>"success","message"=> ["Created successfully."],"id"=>intval($db->lastInsertId()));
      }catch(Exception $e){
        $outcome = Array("status"=>"error","message"=>[$e->getMessage()]);
      }
    }
    return $outcome;
  }

//edit usergroup
  function editUsergroup($db,$usergroup_id,$params){
    global $authed_user;
    if($authed_user["privilege"] < 3){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    if($authed_user["privilege"] == 3 && !test_user_in_group($authed_user,$usergroup_id)){
      //can only edit usergroups you are a member of
      array_push($outcome["message"],"Insufficient privileges.");
    }
    $outcome = Array("status"=>"error","message"=>[]);
    if(!isset($params["group_name"]) || strlen(base64_encode($params["group_name"]))>1024){
      array_push($outcome["message"],"Group name too long or not set.");
    }
    if($usergroup_id <= 0){ //userid must gt 0
      array_push($outcome["message"],"Invalid usergroup id");
    }
    if(count($outcome["message"])>0){ //must have no previous issues
      return $outcome;
    }else{
      try{
        if(isset($params["descr"])){
          $query = $db->prepare('UPDATE usergroup SET group_name=?, descr=? WHERE id = ?;');
          $query->execute([base64_encode($params["group_name"]),base64_encode($params["descr"]),intval($usergroup_id)]);
        }else{
          $query = $db->prepare('UPDATE usergroup SET group_name=? WHERE id = ?;');
          $query->execute([base64_encode($params["group_name"]),intval($usergroup_id)]);
        }
        $outcome = Array("status"=>"success","message"=> ["Usergroup " . $usergroup_id . " updated successfully."]);
      }catch(Exception $e){
        $outcome = Array("status"=>"error","message"=>[$e->getMessage()]);
      }
    }
    return $outcome;
  }

//delete usergroup
  function deleteUsergroup($db,$usergroup_id){
    global $authed_user;
    if($authed_user["privilege"] < 4){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $outcome = Array("status"=>"error","message"=>[]);
    $usergroup_id = intval($usergroup_id);
    if($usergroup_id <= 0){ //userid must gt 0
      $outcome = Array("status"=>"error","message"=>["Invalid usergroup id"]);
    }else{
      try{
        $query = $db->prepare('DELETE FROM usergroup WHERE id = ?;');
        $query->execute([$usergroup_id]);
        $outcome = Array("status"=>"success","message"=> ["Usergroup " . $usergroup_id . " deleted successfully."]);
      }catch(Exception $e){
        $outcome = Array("status"=>"error","message"=>[$e->getMessage()]);
      }
    }
    return $outcome;
  }
?>
