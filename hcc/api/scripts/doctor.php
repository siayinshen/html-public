<?php
//delete, remove, list and assign are tested
  //assumes that this is included after auth.php is called
  if(!isset($authed_user) || $authed_user["privilege"]<1){
    http_response_code(451);
    die();//page should be inaccessible if directly accessed or if user is below min priv
  }
  //ADD NEW DOCTOR 
  function addDoctor($db,$username,$privilege,$password_hash,$params){
    global $authed_user;
    if($authed_user["privilege"] < 3){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $outcome = Array("status"=>"error","message"=>[]); //set default message
    $to_set = Array("username","privilege","password_hash"); //permitted values to set
    $keys = []; // when generating the sql query, what fields to update? Enter any presets here
    $values = []; //to pass into the prepared statement, will match up to $keys
    $outcome = Array("status"=>"error","message"=>[]);
    $config = Array();
    //look for whitelisted values 
    foreach($to_set as $what){
      if(isset($params[$what])){
        switch($what){
          case "username":
            array_push($keys,$what);
            if(strlen(base64_encode($params[$what])) < 512){
              array_push($values,base64_encode($params[$what]));
            }else{
              array_push($outcome["message"],"Username too long.");
              return $outcome;
            }
            break;
          case "privilege":
            array_push($keys,$what);
            array_push($values,min(intval($params[$what]), intval($authed_user["privilege"]) ));
            break;
          case "password_hash":
            array_push($keys,$what);
            array_push($values,hash("sha512",$params[$what]));
            break;
          default:
            $config[$what] = $params[$what];
            break;
        }      
      }
    }
    array_push($keys,"config");//put config json object into querys
    array_push($values,base64_encode(json_encode($config)));
    //check for a minimum of data available before creating doctor
    if(in_array("username",$keys) && in_array("privilege",$keys) && in_array("password_hash",$keys) && count($outcome["message"]) <= 0){
      try{
        $db = pdo_init();
        $update = $db->prepare('INSERT INTO doctor(' . implode(", ", $keys) . 'VALUES('.rtrim(str_repeat("?,", count($keys))).');');
        $update->execute($values);
        $outcome["status"]="success";
        $outcome["id"]=intval($db->lastInsertId());
        array_push($outcome["message"],"Created doctor ".$outcome["id"]." successfully.");
      }catch(Exception $e){
        array_push($outcome["message"],$e->getMessage());
        return $outcome;
      }
    }
    else{
      array_push($outcome["message"],"Insufficient or invalid data provided.");
    }
    //try to handle inserting a contact now if supplied, assuming that doctor was created
    if(isset($params["preferred_contact"]) && isset($outcome["id"])){
      if(gettype($params["preferred_contact"]) == "array"){
        try{ //Create doctor contact card
          $contactTuple = [$outcome["id"],
                           intval($params["preferred_contact"]["contact_type"]),
                           base64_encode($params["preferred_contact"]["contact_detail"])
                          ];
          $stmt = $db->prepare('INSERT INTO doctor_contacts(doctor_id,contact_type,contact_detail) VALUES(?,?,?);');
          $stmt->execute($contactTuple);
        }catch(Exception $e){
          array_push($outcome["message"],$e->getMessage());
          return $outcome;
        }        
        try{  //Insert pointer into database
          $stmt3 = $db->prepare('UPDATE doctor SET preferred_contact=? WHERE doctor_id=?;');
          $stmt3->execute([intval($db->lastInsertId()),$outcome["id"]]);
          array_push($outcome["message"], "Set doctor contact successfully.");
        }catch(Exception $e){
          array_push($outcome["message"],$e->getMessage());
          return $outcome; //we shouldn't be proceeding
        }   
      }else{
        array_push($outcome["message"],"Contact must be in array format.");
      }
    }
    //try to handle assigning doctor to a usergroup, assuming that doctor was created
    if(isset($params["usergroup"]) && isset($outcome["id"])){
      if(gettype($params["usergroup"]) == "integer"){
        $params["usergroup"] = [$params["usergroup"]];
      }
      if(gettype($params["usergroup"]) == "array"){
        foreach($params["usergroup"] as $ug){
          if($authed_user["privilege"] >= 4 || test_user_in_group($authed_user,$ug)){
            try{
              $stmt = $db->prepare('INSERT INTO doctor_membership_table(doctor_id,usergroup_id) VALUES(?,?);');
              $stmt->execute([$outcome["id"],$ug]);
              array_push($outcome["message"], "Added doctor ".$outcome["id"]." to usergroup ".$ug." successfully.");
            }catch(Exception $e){
              array_push($outcome["message"],$e->getMessage());
            }
          }
        }
      }else{
        array_push($outcome["message"],"Please provide usergroup in integer or array format.");
      }
    }
    return $outcome;
  }
  
// DELETE DOCTOR
  function deleteDoctor($db,$userid){
    global $authed_user;
    if($authed_user["privilege"] < 4){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    if(intval($userid) <= 0){ //userid must gt 0
      $outcome = Array("status"=>"error","message"=>["Invalid userid"]);
    }else{
      try{
        $query = $db->prepare('DELETE FROM doctor WHERE id = ?;');
        $query->execute([intval($userid)]);
        $outcome = Array("status"=>"success","message"=> ["Doctor " . $userid . " deleted successfully"]);
      }catch(Exception $e){
        $outcome = Array("status"=>"error","message"=>[$e->getMessage()]);
      }
    }
    return $outcome;
  }
//Change current user's password
  function changeMyPassword($db,$old_pw_hash,$new_pw_hash){
    global $authed_user;
    $outcome = Array("status"=>"error","message"=>["Nothing was changed"]);
    try{
      if(strlen($new_pw_hash)==128){
        $stmt = $db->prepare('UPDATE doctor SET password_hash = ? WHERE id=? AND password_hash=?;');
        $stmt->execute([$new_pw_hash,intval($authed_user['id']),$old_pw_hash]);
        if(intval($stmt->rowCount())>0){
          $outcome = Array("status"=>"success","message"=>["Password changed successfully."]);
        }else{
          $outcome["message"]=["Unable to update password, nothing was changed."];
        }
      }else{
        $outcome["message"]=["Unable to update password, invalid hash supplied."];
      }
    }catch(Exception $e){
      array_push($outcome["message"],$e->getMessage());
    }
    return $outcome;
  }

// edit DOCTOR
  function editDoctor($db,$userid,$params){
    global $authed_user;
    if($authed_user["privilege"] < 3 && $userid != $authed_user["info"]->id){ //users may edit themselves, or not
      http_response_code(418);
      die();
    }
    if($userid <= 0){
      return Array("status"=>"error","message"=>["Invalid userid"]);
    }
    // Build prepared query for insertion of direct references
    $to_change = Array("privilege","password_hash","preferred_contact","config"); //permitted values to change
    $keys = []; // when generating the sql query, what fields to update?
    $values = []; //to pass into the prepared statement
    $outcome = Array("status"=>"error","message"=>[]);
    $config=Array();
    //test for existence and retrieve the existing doctor config
    $stmt3 = $db->prepare('SELECT config FROM doctor WHERE id=?;');
    $stmt3->execute([intval($userid)]);
    $res = $stmt3->fetchAll(PDO::FETCH_CLASS);
    if(count($res) > 0){
      $config=json_decode(base64_decode($res[0]->config));
    }else{
      array_push($outcome["message"],"Doctor not found.");
      return $outcome;
    }
    //look for whitelisted values 
    foreach($to_change as $what){
      if(isset($params[$what])){
        array_push($keys,$what . " = ? ");
        switch($what){
          //users may not set a privilege higher than their own
          case "privilege":
            array_push($keys,$what . " = ? ");
            array_push($values,min(intval($params[$what]), intval($authed_user["privilege"]) ));
            break;
          case "password_hash":
            array_push($keys,$what . " = ? ");
            array_push($values,hash("sha512",$params[$what]));
            break;
          //try my best to insert a data entry into table and retrieve pointer into said table
          //if pointer supplied, ensure that it is valid for doctor
          case "preferred_contact":
            array_push($keys,$what . " = ? ");
            if(gettype($params[$what]) == "array"){
                try{
                $contactTuple = [$userid,
                                 intval($params[$what]["contact_type"]),
                                 base64_encode($params[$what]["contact_detail"])
                                ];
                //insert ignore in case of duplicate
                $stmt = $db->prepare('INSERT IGNORE INTO doctor_contacts(doctor_id,contact_type,contact_detail) VALUES(?,?,?);');
                $stmt->execute($contactTuple);
                array_push($values,intval($db->lastInsertId()));
              }catch(Exception $e){
                array_push($values,NULL);
                array_push($outcome["message"],$e->getMessage());
              }
            }else if(gettype($params[$what]) == "integer"){
              try{
                $stmt3 = $db->prepare('SELECT id FROM doctor_contacts WHERE id=? AND doctor_id=?;');
                $stmt3->execute([$params[$what],$userid]);
                $res = $stmt3->fetchAll(PDO::FETCH_CLASS);
                if(count($res) > 0){
                  array_push($values,$params[$what]);
                }else{
                  array_push($values,NULL); //mask to null if contact card not for this doctor
                }
              }catch(Exception $e){
                array_push($values,NULL);
                array_push($outcome["message"],$e->getMessage());
              }
            }else{
              array_push($values,NULL);
              array_push($outcome["message"],"Contact must be in array or pointer format.");
            }
            break;
          default:
            $config[$what] = $params[$what];
            break;
        }      
      }
    }
    array_push($keys,"config");//put config json object into query
    array_push($values,base64_encode(json_encode($config)));
    array_push($values, $userid);
    if(count($keys>0)&& count($outcome["message"])<=1){
      try{
        $db = pdo_init();
        $update = $db->prepare('UPDATE doctor SET ' . implode(", ", $keys) . ' WHERE id = ?;');
        $update->execute($values);
        $outcome["status"]="success";
        array_push($outcome["message"],"Edited doctor ".$userid." successfully.");
      }catch(Exception $e){
        array_push($outcome["message"],$e->getMessage());
        die();
      }
    }
    else{
      array_push($outcome["message"],"Insufficient or invalid data provided.");
    }
    return $outcome;
  }
  //list all doctors in a usergroup
  function listDoctorsInUsergroup($db,$usergroup){
    global $authed_user;
    if($authed_user["privilege"] < 1){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $usergroup = intval($usergroup);
    if($usergroup <= 0){
      return Array("status"=>"error","message"=>["Invalid usergroup"],"sql"=>[]);
    }
    $outcome = Array("status"=>"error","message"=>[],"sql"=>[]);
    try{
      $db = pdo_init();
      $stmt = $db->prepare('
        SELECT doctor.id,doctor.username,doctor.privilege,doctor_contacts.contact_detail,doctor.config
        FROM (doctor_membership_table
        INNER JOIN doctor
          ON doctor_membership_table.doctor_id=doctor.id)
        LEFT JOIN doctor_contacts
          ON doctor.preferred_contact=doctor_contacts.id
        WHERE usergroup_id=?
        ;');
      $stmt->execute([$usergroup]);
      $outcome["status"]="success";
      array_push($outcome["message"],"Listed data successfully for usergroup ".$usergroup.".");
      $outcome["sql"]=$stmt->fetchAll(PDO::FETCH_CLASS);
    }catch(Exception $e){
      array_push($outcome["message"],$e->getMessage());
      die();
    }
    return $outcome;
  }

  //Assign doctor to usergroup. Set overwrite = true if you want to delete preexisting memberships
  function setDoctorUsergroup($db,$userid,$usergroups,$overwrite=False){
    global $authed_user;
    if($authed_user["privilege"] < 3){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $userid = intval($userid);
    if($userid <= 0){
      return Array("status"=>"error","message"=>["Invalid userid"]);
    }
    $outcome = Array("status"=>"error","message"=>[]);
    if($overwrite && $authed_user["privilege"] >= 4){
      try{
        $db = pdo_init();
        $stmt = $db->prepare('
          DELETE FROM doctor_membership_table WHERE doctor_id=?;');
        $stmt->execute([$userid]);
        $outcome["status"]="success";
        array_push($outcome["message"],"Cleared membership data successfully");
      }catch(Exception $e){
        array_push($outcome["message"],$e->getMessage());
        return $outcome;
      }
    }else{
      array_push($outcome["message"],"Insufficient permissions to overwrite membership.");
      return $outcome; //must have perm level 4 to overwrite usergroup membership
    }
    if(gettype($usergroups) == "integer"){
      $usergroups = [$usergroups];
    }
    if(gettype($usergroups) == "array"){
      foreach($usergroups as $usergroup){
        if($authed_user["privilege"] >= 4 || test_user_in_group($authed_user,$usergroup)){
          try{
            $db = pdo_init();
            $stmt = $db->prepare('
              INSERT INTO doctor_membership_table(doctor_id,usergroup_id) VALUES (?,?) ON DUPLICATE KEY UPDATE doctor_id=?;');
            $stmt->execute([$userid,intval($usergroup),$userid]);
          }catch(Exception $e){
            array_push($outcome["message"],$e->getMessage());
            return $outcome;
          }
        }else{
          array_push($outcome["message"],"No permission to add doctor to group ".$usergroup.".");
        }
      }
      if(count($outcome["message"]) == 0){
        $outcome["status"]="success";
        array_push($outcome["message"],"Updated all membership data successfully for doctor ".$userid.".");
      }else{
        array_push($outcome["message"],"Updated some membership data for doctor ".$userid.".");
      }
    }else{
      array_push($outcome["message"],"Please provide a list of usergroups to enroll this user in.");
      return $outcome;   
    }
    return $outcome;
  }
//Get a doctors contact list
  function getMyContactList($db){
    global $authed_user;
    $doctor_id=intval($authed_user["id"]);
    $outcome = Array("status"=>"error","message"=>["No valid data found"],"sql"=>[]);
    if($doctor_id <= 0){ //set minimum priv to view all patients
      return $outcome;
    }
    try{
      $stmt = $db->prepare('
        SELECT
          doctor_contacts.id,doctor_contacts.contact_detail,contact_modality.type
        FROM (doctor_contacts
          INNER JOIN contact_modality 
          ON doctor_contacts.contact_type = contact_modality.id)
        WHERE doctor_contacts.doctor_id=?;');
      $stmt->execute([$doctor_id]);
      $outcome = Array("status"=>"success","message"=>["Found data successfully."]);
      $outcome["sql"] = $stmt->fetchAll(PDO::FETCH_CLASS);
    }catch(Exception $e){
      $outcome["message"] = [$e->getMessage()];
    }
    return $outcome;
  }
?>
