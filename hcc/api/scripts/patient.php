<?php
  if(!isset($authed_user) || $authed_user["privilege"]<1){
    http_response_code(451);
    die();//page should be inaccessible if directly accessed or if user is below min priv
  }
// Creates a patient
  function createPatient($db,$params){var_dump($params);
    global $authed_user;
    if($authed_user["privilege"] < 2){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    // Build prepared query for insertion of direct references
    $to_set = Array("hospital_id","government_id","name","dob","research_consent","diagnosis","medical_history","treatment_plan"); //permitted values to set in initial creation
    $pmh_config=Array();
    $keys = ["lead_consultant"]; // when generating the sql query, what fields to update?
    $values = [intval($authed_user["id"])]; //to pass into the prepared statement, will match up to $keys
    $outcome = Array("status"=>"error","message"=>[]);
    //look for whitelisted values 
    foreach($to_set as $what){
      if(isset($params[$what])){
        switch($what){
          case "hospital_id":
            if(strlen(base64_encode($params["hospital_id"]))<255){
              array_push($keys,$what);
              array_push($values,base64_encode($params["hospital_id"]));
            }else{
              array_push($outcome["message"],"Hospital number too long.");
            }
            break;
          case "government_id":
            if(strlen(base64_encode($params["government_id"]))<255){
              array_push($keys,$what);
              array_push($values,base64_encode($params["government_id"]));
            }else{
              array_push($outcome["message"],"NHS number too long.");
            }
            break;
          case "name":
            if(strlen(base64_encode($params["name"]))<512){
              array_push($keys,$what);
              array_push($values,base64_encode($params["name"]));
            }else{
              array_push($outcome["message"],"Name too long.");
            }
            break;
          case "dob":
            array_push($keys,$what);
            array_push($values,date("Y-m-d",strtotime($params["dob"])));
            break;
          case "research_consent":
            array_push($keys,$what);
            array_push($values,intval($params[$what]));
            break;
          default:
            $pmh_config[$what] = $params[$what]; //add extra params to pmh json object
            break;
        }
      }
    }
    array_push($keys,"pmh_config");//put pmh json object into querys
    array_push($values,base64_encode(json_encode($pmh_config)));
    //check for a minimum of data available before creating patient
    if(in_array("hospital_id",$keys) && in_array("government_id",$keys) && in_array("name",$keys)){
      if(count($outcome["message"])>=1){
        return $outcome; //dont bother if there are issues
      }
      try{
        $update = $db->prepare('INSERT INTO patient(' . implode(", ", $keys) . ') VALUES('. rtrim(str_repeat("?,", count($keys)),",") .');');
        $update->execute($values);
        $outcome["status"]="success";
        $outcome["id"]=intval($db->lastInsertId());
        array_push($outcome["message"],"Created patient ".$outcome["id"]." successfully.");
      }catch(Exception $e){
        array_push($outcome["message"],$e->getMessage());
        return $outcome;
      }
    }
    else{
      array_push($outcome["message"],"Insufficient or invalid data provided.");
      return $outcome;
    }
    //try to handle inserting a contact now if supplied, assuming that patient was created
    if(isset($params["preferred_contact"]) && isset($outcome["id"])){
      if(gettype($params["preferred_contact"]) == "array"){
        try{ //Create patient contact card
          $contactTuple = [$outcome["id"],
                           intval($params["preferred_contact"]["contact_type"]),
                           base64_encode($params["preferred_contact"]["contact_detail"])
                          ];
          $stmt = $db->prepare('INSERT INTO patient_contacts(patient_id,contact_type,contact_detail) VALUES(?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id);');
          $stmt->execute($contactTuple);
        }catch(Exception $e){
          $outcome["status"]="error";
          array_push($outcome["message"],$e->getMessage());
        }
        if($outcome["status"]=="success"){
          try{  //Insert pointer into database
            $stmt3 = $db->prepare('UPDATE patient SET preferred_contact=? WHERE id=?;');
            $stmt3->execute([intval($db->lastInsertId()),$outcome["id"]]);
            array_push($outcome["message"], "Set patient contact successfully.");
          }catch(Exception $e){
            array_push($outcome["message"],$e->getMessage());
          }
        }
      }else{
        array_push($outcome["message"],"Contact must be in array format.");
      }
    }
    //try to handle assigning patient to a usergroup, assuming that patient was created
    if(isset($params["usergroup"]) && isset($outcome["id"])){
      if(gettype($params["usergroup"]) == "integer" || is_numeric($params["usergroup"])){
        $params["usergroup"] = [intval($params["usergroup"])];
      }// cast to array
      if(gettype($params["usergroup"]) == "array"){
        foreach($params["usergroup"] as $ug){
          if($authed_user['privilege'] >= 4 || test_user_in_group($authed_user,$ug)){
            try{
              $stmt = $db->prepare('INSERT IGNORE INTO patient_membership_table(patient_id,usergroup_id) VALUES(?,?);');
              $stmt->execute([$outcome["id"],$ug]);
              array_push($outcome["message"], "Added patient ".$outcome["id"]." to usergroup ".$ug." successfully.");
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

//finds a patient given provided data
  function findPatient($db,$params){
    global $authed_user;
    if($authed_user["privilege"] < 1){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $outcome = Array("status"=>"error","message"=>[],"sql"=>[]);
    // Build prepared query for insertion of direct references
    $to_set = Array("hospital_id","government_id","name","research_consent","dob"); //permitted values to set
    $keys = []; // when generating the sql query, what fields to update?
    $values = []; //to pass into the prepared statement, will match up to $keys
    $outcome = Array("status"=>"error","message"=>[]);
    //look for whitelisted values 
    foreach($to_set as $what){
      if(isset($params[$what]) && $params[$what] != ''){
        switch($what){
          case "hospital_id":
            if(strlen(base64_encode($params["hospital_id"]))<255){
              array_push($keys,"patient.".$what . " = ? ");
              array_push($values,base64_encode($params["hospital_id"]) );
            }
            break;
          case "government_id":
            if(strlen(base64_encode($params["government_id"]))<255){
              array_push($keys,"patient.".$what . " = ? ");
              array_push($values,base64_encode($params["government_id"]) );
            }
            break;
          case "name":
            array_push($keys,"(patient.name LIKE ? OR patient.name LIKE ? OR patient.name LIKE ? OR patient.name LIKE ? OR patient.name LIKE ? OR patient.name LIKE ?)");
            $values = array_merge($values,base64_offsets($params["name"]));
            break;
          case "dob":
            array_push($keys,"patient.".$what . " = ? ");
            array_push($values,date("Y-m-d",strtotime($params["dob"])));
            break;
          case "research_consent":
            array_push($keys,"patient.".$what . " = ? ");
            array_push($values,((bool) $params["research_consent"]));
            break;
          default:
            array_push($keys,"patient.".$what . " = ? ");
            array_push($values,base64_encode($params[$what]));
            break;
        }      
      }
    }
    //check for a minimum of data available before searching for patient
    if(count($keys)>=1){
      try{
        $stmt = $db->prepare('
          SELECT patient.id,usergroup.group_name,usergroup.descr,
            patient.hospital_id,patient.government_id,patient.name, patient.dob,
            patient_contacts.contact_type,patient_contacts.contact_detail,
            doctor.username,doctor.config,
            patient.research_consent,
            patient.last_attended_appointment, laa.attended_date AS last_appt_date,
            patient.next_appointment,na.due_date AS next_appt_date,
            patient.last_reminder_sent,
            patient.pmh_config
          FROM ((((((patient_membership_table
            RIGHT JOIN patient
            ON patient_membership_table.patient_id=patient.id)
            LEFT JOIN usergroup
            ON patient_membership_table.usergroup_id=usergroup.id)
            LEFT JOIN doctor 
            ON patient.lead_consultant = doctor.id)
            LEFT JOIN patient_contacts
            ON patient.preferred_contact = patient_contacts.id)
            LEFT JOIN appointments laa
            ON patient.last_attended_appointment = laa.id)
            LEFT JOIN appointments na
            ON patient.next_appointment = na.id)
          WHERE ' . implode(" AND ", $keys) . ';');
        $stmt->execute($values);
        $outcome = Array("status"=>"success","message"=>["Found data successfully."]);
        $outcome["sql"] = $stmt->fetchAll(PDO::FETCH_CLASS);
      }catch(Exception $e){
        array_push($outcome["message"],$e->getMessage());
        return $outcome;
      }
    }
    else{
      array_push($outcome["message"],"Insufficient or invalid data provided.");
      return $outcome;
    }    
    return $outcome;
  }
  
//Get a patient by a specific id
  function getPatientById($db,$patient_id){
    global $authed_user;
    if($authed_user["privilege"] < 1){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $patient_id=intval($patient_id);
    $outcome = Array("status"=>"error","message"=>["No valid data found"],"sql"=>[]);
    if(($authed_user["privilege"] < 4 && !is_doctor_responsible($db,$authed_user,$patient_id)) || $patient_id < 0){ //set minimum priv to view all patients
      return $outcome;
    }
    try{
      $stmt = $db->prepare('
        SELECT
          patient.id,patient.hospital_id,patient.government_id,patient.name, patient.dob,
          patient_contacts.contact_type,patient_contacts.contact_detail,
          doctor.username,doctor.config,
          patient.research_consent,
          patient.last_attended_appointment, laa.attended_date AS last_appt_date,
          patient.next_appointment,na.due_date AS next_appt_date,
          patient.last_reminder_sent, patient.pmh_config,
          patient_membership_table.usergroup_id,usergroup.group_name AS usergroup_name
        FROM ((((((patient
          LEFT JOIN doctor 
          ON patient.lead_consultant = doctor.id)
          LEFT JOIN patient_contacts
          ON patient.preferred_contact = patient_contacts.id)
          LEFT JOIN appointments laa
          ON patient.last_attended_appointment = laa.id)
          LEFT JOIN appointments na
          ON patient.next_appointment = na.id)
          LEFT JOIN patient_membership_table
          ON patient.id=patient_membership_table.patient_id)
          LEFT JOIN usergroup
          ON patient_membership_table.usergroup_id=usergroup.id)
        WHERE patient.id=?;');
      $stmt->execute([$patient_id]);
      if($stmt->rowCount()>0){
        $outcome = Array("status"=>"success","message"=>["Found data successfully."]);
        $outcome["sql"] = $stmt->fetchAll(PDO::FETCH_CLASS);
      }
    }catch(Exception $e){
      $outcome["message"] = [$e->getMessage()];
    }
    return $outcome;
  }

//Get a patient by a specific id
  function getPatientContactListById($db,$patient_id){
    global $authed_user;
    if($authed_user["privilege"] < 1){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $patient_id=intval($patient_id);
    $outcome = Array("status"=>"error","message"=>["No valid data found"],"sql"=>[]);
    if(($authed_user["privilege"] < 4 && !is_doctor_responsible($db,$authed_user,$patient_id)) || $patient_id < 0){ //set minimum priv to view all patients
      return $outcome;
    }
    try{
      $stmt = $db->prepare('
        SELECT
          patient_contacts.id,patient_contacts.contact_detail,contact_modality.type
        FROM (patient_contacts
          INNER JOIN contact_modality 
          ON patient_contacts.contact_type = contact_modality.id)
        WHERE patient_contacts.patient_id=?;');
      $stmt->execute([$patient_id]);
      $outcome = Array("status"=>"success","message"=>["Found data successfully."]);
      $outcome["sql"] = $stmt->fetchAll(PDO::FETCH_CLASS);
    }catch(Exception $e){
      $outcome["message"] = [$e->getMessage()];
    }
    return $outcome;
  }
  
//Deletes a patient
  function deletePatient($db,$patient_id){
    global $authed_user;
    if($authed_user["privilege"] < 2){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $patient_id = intval($patient_id);
    $outcome = Array("status"=>"error","message"=>[]);
    if(is_doctor_responsible($db,$authed_user,$patient_id) && $patient_id>0){
      try{
        $query = $db->prepare('DELETE FROM patient WHERE id = ?;');
        $query->execute([$patient_id]);
        $outcome = Array("status"=>"success","message"=> ["Patient " . $patient_id . " deleted successfully."]);
      }catch(Exception $e){
        $outcome = Array("status"=>"error","message"=>[$e->getMessage()]);
      }
    }else{
      array_push($outcome["message"],"Insufficient permissions given.");
    }
    return $outcome;
  }
  
//Edit a patient
  function editPatient($db,$id,$params){
    global $authed_user;
    if($authed_user["privilege"] < 2){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    // Build prepared query for insertion of direct references
    $to_set = Array("hospital_id","government_id","name","dob","lead_consultant","research_consent","diagnosis","medical_history","treatment_plan","unique_ref"); //permitted values to set in initial creation
    $pmh_config=Array();
    $keys = []; // when generating the sql query, what fields to update?
    $values = []; //to pass into the prepared statement, will match up to $keys
    $outcome = Array("status"=>"error","message"=>["Cannot do this operation."]);
    //Prevent inappropriate access
    if(($authed_user["privilege"] < 3 && !is_doctor_responsible($db,$authed_user,$patient_id)) || $patient_id < 0){ //set minimum priv to view all patients
      return $outcome;
    }
    //test for existence and retrieve the existing pmh_config
    $stmt3 = $db->prepare('SELECT pmh_config FROM patient WHERE id=?;');
    $stmt3->execute([intval($id)]);
    $res = $stmt3->fetchAll(PDO::FETCH_CLASS);
    if(count($res) > 0){
      $pmh_config=json_decode(base64_decode($res[0]->pmh_config),true); 
      //return assoc array so we don't delete stuff if edit request is underspecified
    }else{
      array_push($outcome["message"],"Patient not found.");
      return $outcome;
    }
    //look for whitelisted values 
    foreach($to_set as $what){
      if(isset($params[$what])){
        switch($what){
          case "hospital_id":
            if(strlen(base64_encode($params["hospital_id"]))<255){
              array_push($keys,$what . " = ? ");
              array_push($values,base64_encode($params["hospital_id"]));
            }else{
              array_push($outcome["message"],"Hospital number too long.");
            }
            break;
          case "government_id":
            if(strlen(base64_encode($params["government_id"]))<255){
              array_push($keys,$what . " = ? ");
              array_push($values,base64_encode($params["government_id"]));
            }else{
              array_push($outcome["message"],"NHS number too long.");
            }
            break;
          case "name":
            if(strlen(base64_encode($params["name"]))<512){
              array_push($keys,$what . " = ? ");
              array_push($values,base64_encode($params["name"]));
            }else{
              array_push($outcome["message"],"Name too long.");
            }
            break;
          case "lead_consultant":
            array_push($keys,$what . " = ? ");
            array_push($values,intval($params["lead_consultant"]));
            break;
          case "dob":
            array_push($keys,$what . " = ? ");
            array_push($values,date("Y-m-d",strtotime($params["dob"])));
            break;
          case "research_consent":
            array_push($keys,$what . " = ? ");
            array_push($values, intval((bool) $params["research_consent"]));
            break;
          case "unique_ref":
            array_push($keys,$what . " = ? ");
            array_push($values,hash("sha512",$params["unique_ref"]));
          default:
            $pmh_config[$what] = $params[$what]; //add extra params to pmh json object
            break;
        }
      }
    }
    //update pmh config only if needed
    if(isset($params['diagnosis']) || isset($params["medical_history"]) || isset($params["treatment_plan"])){
      array_push($keys,"pmh_config = ? ");//put pmh json object into querys
      array_push($values,base64_encode(json_encode($pmh_config)));
    }
    array_push($values,intval($id)); // push patient id into place
    //check for a minimum of data available before updating patient
    if(count($keys)>0){
      if(count($outcome["message"])>=1){
        return $outcome; //dont bother if there are issues
      }
      try{
        $update = $db->prepare('UPDATE patient SET ' . implode(", ", $keys) . ' WHERE id = ?;');
        $update->execute($values);
        $outcome["status"]="success";
        $outcome["id"]=intval($db->lastInsertId());
        array_push($outcome["message"],"Updated patient ".intval($id)." successfully.");
      }catch(Exception $e){
        array_push($outcome["message"],$e->getMessage());
        return $outcome;
      }
    }
    else{
      array_push($outcome["message"],"Insufficient or invalid data provided.");
      return $outcome;
    }
    //try to handle updating a contact now if supplied, assuming that patient was created
    if(isset($params["preferred_contact"]) && isset($outcome["id"])){
      if(gettype($params["preferred_contact"]) == "array"){
        try{ //Create patient contact card
          $contactTuple = [$id,
                           intval($params["preferred_contact"]["contact_type"]),
                           base64_encode($params["preferred_contact"]["contact_detail"])
                          ];
          $stmt = $db->prepare('INSERT INTO patient_contacts(patient_id,contact_type,contact_detail) VALUES(?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id);');
          $stmt->execute($contactTuple);
        }catch(Exception $e){
          $outcome["status"]="error";
          array_push($outcome["message"],$e->getMessage());
        }
        if($outcome["status"]=="success"){
          try{  //Insert pointer into database
            $stmt3 = $db->prepare('UPDATE patient SET preferred_contact=? WHERE patient_id=?;');
            $stmt3->execute([intval($db->lastInsertId()),$id]);
            array_push($outcome["message"], "Set patient contact successfully.");
          }catch(Exception $e){
            array_push($outcome["message"],$e->getMessage());
            return $outcome; //we shouldn't be proceeding
          }
        } 
      }else{
        array_push($outcome["message"],"Contact must be in array format.");
      }
    }
    //try to handle assigning patient to a usergroup, assuming that patient was created
    if(isset($params["usergroup"]) && isset($outcome["id"])){
      if(gettype($params["usergroup"]) == "integer" || is_numeric($params["usergroup"])){
        $params["usergroup"] = [intval($params["usergroup"])];
      }
      if(gettype($params["usergroup"]) == "array"){
        try{
          $stmt = $db->prepare('DELETE FROM patient_membership_table WHERE patient_id=?;');
          $stmt->execute([$patient_id]);
        }catch(Exception $e){
          array_push($outcome["message"],$e->getMessage());
        }
        foreach($params["usergroup"] as $ug){
          if($authed_user['privilege'] >= 4 || test_user_in_group($authed_user,$ug)){
            try{
              $stmt = $db->prepare('INSERT IGNORE INTO patient_membership_table(patient_id,usergroup_id) VALUES(?,?);'); //no id key for this table
              $stmt->execute([$id,$ug]);
              array_push($outcome["message"], "Added patient ".$id." to usergroup ".$ug." successfully.");
            }catch(Exception $e){
              array_push($outcome["message"],$e->getMessage());
              return $outcome; //we shouldn't be proceeding
            }
          }
        }
      }else{
        array_push($outcome["message"],"Please provide usergroup in integer or array format.");
      }
    }
    return $outcome;
  }
//Assign patient to one or more usergoups
  function setPatientUsergroup($db,$id,$usergroups,$overwrite=True){
    global $authed_user;
    if($authed_user["privilege"] < 2){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $outcome = Array("status"=>"error","message"=>[]);
    $patient_id = intval($id);
    if($patient_id <= 0){
      return Array("status"=>"error","message"=>["Invalid patient id."]);
    }
    $outcome = Array("status"=>"error","message"=>[]);
    if($overwrite && $authed_user["privilege"] >= 4){
      try{
        $db = pdo_init();
        $stmt = $db->prepare('
          DELETE FROM patient_membership_table WHERE patient_id=?;');
        $stmt->execute([$patient_id]);
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
              INSERT INTO doctor_membership_table(doctor_id,usergroup_id) VALUES (?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id);');
            $stmt->execute([$userid,intval($usergroup)]);
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

// List all patients that a doctor is responsible for
  function listMyPatients($db){
    global $authed_user;
    if($authed_user["privilege"] < 1){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $outcome = Array("status"=>"error","message"=>[],"sql"=>[]);
    try{
      $stmt = $db->prepare('
        SELECT patient.id,patient.government_id,patient.hospital_id,patient.name, patient.dob,
        patient_contacts.contact_type,patient_contacts.contact_detail,
        doctor.username,doctor.config,
        patient.research_consent,
        patient.last_attended_appointment, laa.attended_date AS last_appt_date,
        patient.next_appointment,na.due_date AS next_appt_date,
        patient.last_reminder_sent,
        patient.pmh_config
          FROM ((((patient
            LEFT JOIN patient_contacts
            ON patient.preferred_contact=patient_contacts.id)
            LEFT JOIN doctor
            ON patient.lead_consultant=doctor.id)
            LEFT JOIN appointments laa
            ON patient.last_attended_appointment = laa.id)
            LEFT JOIN appointments na
            ON patient.next_appointment = na.id)
        WHERE patient.lead_consultant=?
        ORDER BY laa.due_date ASC;');
      $stmt->execute([$authed_user["id"]]);
      $outcome = Array("status"=>"success","message"=>["Found data successfully."]);
      $outcome["sql"] = $stmt->fetchAll(PDO::FETCH_CLASS);
    }catch(Exception $e){
      array_push($outcome["message"],$e->getMessage());
    }
    return $outcome;
  }
  
// List all patients that a doctor has in their usergroups
  function listMyUsergroupPatients($db){
    global $authed_user;
    if($authed_user["privilege"] < 1){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $outcome = Array("status"=>"error","message"=>[],"sql"=>[]);
    try{
      $stmt = $db->prepare('
        SELECT patient.id,patient.government_id,patient.hospital_id,patient.name, patient.dob,
        patient_contacts.contact_type,patient_contacts.contact_detail,
        doctor.username,doctor.config,
        patient.research_consent,
        patient.last_attended_appointment, laa.attended_date AS last_appt_date,
        patient.next_appointment,na.due_date AS next_appt_date,
        patient.last_reminder_sent,
        patient.pmh_config
          FROM ((((((messages
            LEFT JOIN patient_membership_table 
            ON doctor_membership_table.usergroup_id=patient_membership_table.usergroup_id)
            INNER JOIN patient
            ON patient_membership_table.patient_id=patient.id)
            LEFT JOIN patient_contacts
            ON patient.preferred_contact=patient_contacts.id)
            LEFT JOIN doctor
            ON patient.lead_consultant=doctor.id)
            LEFT JOIN appointments laa
            ON patient.last_attended_appointment = laa.id)
            LEFT JOIN appointments na
            ON patient.next_appointment = na.id)
        WHERE doctor_membership_table.doctor_id=?
        ORDER BY laa.due_date ASC;');
      $stmt->execute([$authed_user["id"]]);
      $outcome = Array("status"=>"success","message"=>["Found data successfully."]);
      $outcome["sql"] = $stmt->fetchAll(PDO::FETCH_CLASS);
    }catch(Exception $e){
      array_push($outcome["message"],$e->getMessage());
    }
    return $outcome;
  }
//List messages by patient
  function listMessageByPatient($db,$patient_id){
    global $authed_user;
    if($authed_user["privilege"] < 1){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $outcome = Array("status"=>"error","message"=>[],"sql"=>[]);
    try{
      $stmt = $db->prepare('
        SELECT messages.id,messages.is_outbound,messages.sent,messages.content,
          doctor.id AS doctor_id,doctor.username AS doctor_name,
          patient.name AS patient_name,
          doctor_contacts.contact_type AS doctor_contact_type, doctor_contacts.contact_detail AS doctor_contact_detail,
          patient_contacts.contact_type AS patient_contact_type, patient_contacts.contact_detail AS patient_contact_detail
          FROM ((((messages
            LEFT JOIN doctor 
            ON messages.doctor_id=doctor.id)
            INNER JOIN patient
            ON messages.patient_id=patient.id)
            INNER JOIN patient_contacts
            ON messages.patient_contact_id=patient_contacts.id)
            LEFT JOIN doctor_contacts
            ON messages.doctor_contact_id=doctor_contacts.id)
        WHERE patient.id=?
        ORDER BY messages.sent ASC;');
      $stmt->execute([intval($patient_id)]);
      $outcome = Array("status"=>"success","message"=>["Found data successfully."]);
      $outcome["sql"] = $stmt->fetchAll(PDO::FETCH_CLASS);
    }catch(Exception $e){
      array_push($outcome["message"],$e->getMessage());
    }
    return $outcome;
  }
//todo
  function messagePatient($db,$patient_id,$params,$message){
    global $authed_user;
    if($authed_user["privilege"] < 2){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    // Build prepared query for insertion of direct references
    $to_set = Array("doctor_contact_id","patient_contact_id","is_outbound","reminder_for"); //permitted values to set in initial creation
    $pmh_config=Array();
    $keys = ["doctor_id","patient_id","content"]; // when generating the sql query, what fields to update?
    $values = [intval($authed_user["id"]),$patient_id,base64_encode($message)]; //to pass into the prepared statement, will match up to $keys
    $outcome = Array("status"=>"error","message"=>["Cannot do this operation."]);
    //Prevent inappropriate access
    if(($authed_user["privilege"] < 4 && !is_doctor_responsible($db,$authed_user,$patient_id)) || $patient_id < 0){ //set minimum priv to view all patients
      return $outcome;
    }
    //look for whitelisted values 
    foreach($to_set as $what){
      if(isset($params[$what])){
        array_push($keys,$what);
        switch($what){
          case "is_outbound":
            if(intval($params[$what])==1){
              array_push($values,1);
            }else{
              array_push($values,0);
            }
            break;
          default:
            array_push($values,intval($params[$what])); 
            break;
        }
      }
    }
    //check for a minimum of data available before creating message
    if(in_array("patient_contact_id",$keys) && in_array("patient_id",$keys) && strlen($message)>0){
      try{
        $stmt = $db->prepare('SELECT contact_type,contact_detail FROM patient_contacts WHERE id=? and patient_id=?;');
        $stmt->execute([$params["patient_contact_id"], $patient_id]);
        $outcome = Array("status"=>"error","message"=>["Something went wrong."]);
        $res = $stmt->fetchAll(PDO::FETCH_CLASS);
        if(count($res) > 0){//found the contact card
          $outcome = Array("status"=>"success","message"=>["Found contact card successfully."]);
          switch($res[0]->contact_type){//try sending message, assumed correct order in contact modalities table
            case 1:
              $outcome_temp = sendText(base64_decode($res[0]->contact_detail),$message);
              break;
            case 2:
              $outcome_temp = sendEmail(base64_decode($res[0]->contact_detail),$message);
              break;
            default:
              $outcome_temp = Array("status"=>"warn","message"=>["Unsupported method."]);
              break;
          }
          //update status and message with feedback from email
          $outcome["status"] = $outcome_temp["status"];
          $outcome["message"] = array_merge($outcome["message"],$outcome_temp["message"]);
          if($outcome["status"] == 'success'){
            try{//We succeeded in sending the message, let's commit to db
              $update = $db->prepare('INSERT INTO messages(' . implode(", ", $keys) . ') VALUES('. rtrim(str_repeat("?,", count($keys)),",") .');');
              $update->execute($values);
              $outcome["status"]="success";
              $outcome["id"]=intval($db->lastInsertId());
              array_push($outcome["message"],"Committed message successfully to db.");
            }catch(Exception $e){
              array_push($outcome["message"],$e->getMessage());
              return $outcome;// stop execution here
            }
            try{//Update patient db entry here
              $update = $db->prepare('UPDATE patient SET last_reminder_sent=? WHERE id=?;');
              $update->execute([date("Y-m-d H:i:s"),intval($patient_id)]);
            }catch(Exception $e){
              array_push($outcome["message"],$e->getMessage());
            }
            //update metrics here
            if(isset($params["reminder_for"])){
              try{
                $update = $db->prepare('UPDATE appointments SET reminder_count=reminder_count+1, last_reminder=? WHERE id=?;');
                $update->execute([intval($outcome["id"]),intval($params["reminder_for"])]);
              }catch(Exception $e){
                $outcome["status"]="error";
                array_push($outcome["message"],$e->getMessage());
              }
            }
          }
        }else{
          $outcome["message"]= ["Contact card not found"];
        }
      }catch(Exception $e){
        array_push($outcome["message"],$e->getMessage());
        return $outcome;
      }
    }
    else{
      array_push($outcome["message"],"Insufficient or invalid data provided.");
      return $outcome;
    }
    return $outcome;
  }
//todo
  function sendEmail($to,$message){
    global $authed_user;
    global $access;
    if($authed_user["privilege"] < 2){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $token = getGoogleOAuthToken("https://www.googleapis.com/auth/gmail.send",20);
    if($token["status"]=="success"){
       $message="";
       $ts_string=date("D, j M Y H:i:s P");
       $message= <<<HRD
From: John Doe <hcc-mail-daemon@test-project-383922.iam.gserviceaccount.com> 
To: Clifford Sia <clifford@sia.nz> 
Subject: Saying Hello 
Date: {$ts_string}
This is a message just to say hello. So, "Hello".
HRD;
    $message=base64_encode($message);
    $message = rtrim(strtr(base64_encode($message), '+/', '-_'), '=');
    //$ch = curl_init();
    //curl_setopt($ch, CURLOPT_URL,"https://gmail.googleapis.com/gmail/v1/users/me/messages/send");
    //curl_setopt($ch, CURLOPT_POST, 1);
    //curl_setopt($ch, CURLOPT_HTTPHEADER, [
    //  "Authorization: Bearer ".$token["message"][0],
    //  "Content-Type: message/rfc822"
    //]);
    //curl_setopt($ch, CURLOPT_POSTFIELDS, '{"raw": "'.$message.'"}' ); 
    //curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    //echo("curl -H 'Authorization: Bearer {$token["message"][0]}' -H 'Content-Type: message/rfc822' -d '{\"raw\":\"{$message}\"}' 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send'");
    //$server_output = curl_exec($ch);
    //curl_close($ch);
    $outcome["status"]="success";
    //$outcome["message"]= [$server_output];
    }else{ // abort if no token available
      return $outcome;
    }
    return $outcome;
  }
//todo
  function sendText($to,$message){
    global $authed_user;
    global $access;
    if($authed_user["privilege"] < 2){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $outcome = Array("status"=>"error","message"=>[]);
    $outcome = Array("status"=>"success","message"=>["Message sent"]);
    return $outcome;
  } 

?>
