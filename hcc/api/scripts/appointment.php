<?php
  if(!isset($authed_user) || $authed_user["privilege"]<1){
    http_response_code(451);
    die();//page should be inaccessible if directly accessed or if user is below min priv
  }
  //todo
  function createAppointment($db,$patient_id,$params){
    global $authed_user;
    if($authed_user["privilege"] < 2){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $to_set = Array("due_date","location","appt_name","usergroup","appt_purpose","appt_outcome","appt_interval"); //permitted values to set
    $keys = ["doctor_id","patient_id"]; // when generating the sql query, what fields to update? Enter any presets here
    $values = [intval($authed_user["id"]),intval($patient_id)]; //to pass into the prepared statement, will match up to $keys
    $outcome = Array("status"=>"success","message"=>[],"id"=>null);
    //look for whitelisted values 
    foreach($to_set as $what){
      if(isset($params[$what])){
        array_push($keys,$what);
        switch($what){
          case "due_date":
            array_push($values,date("Y-m-d H:i:s", strtotime($params[$what])) );//cast to sql date
            if( strtotime($params[$what]) < strtotime('now') ){
              array_push($outcome["message"],"Retroactively assigning appointment.");
            }
            break;
          case "location":
            if(gettype($params[$what])=="string"){// if a string is supplied, assume we want to create an appointment location unless it is numeric
              if(is_numeric($params[$what])){
                array_push($values,intval($params[$what]));
              }else{
                if(!isset($params["usergroup"])){//
                  array_push($outcome["message"],"Cannot create location without usergroup so setting to null.");
                  array_push($values,null);
                }else{
                  if(strlen(base64_encode($params["location"]))>=512){
                    array_push($outcome["message"],"Location name too long so was truncated.");
                  }
                  try{
                    $otc=createAppointmentLocation($db,Array("usergroup_id"=>intval($params["usergroup"]),
                                                             "location_name"=>$params["location"],
                                                             "location_name"=>""));
                    array_push($values,$otc["id"]);
                    $outcome["message"] = array_merge($outcome["message"],$otc["message"]);
                  }catch(Exception $e){
                    array_push($outcome["message"],$e->getMessage());
                    array_push($values,null);
                  }
                }
              }
            }else if(gettype($params[$what])=="array"){// assume array was given in format [usergroup,location name, location info] and pass it on to create appt location
              try{
                $params[$what]["usergroup_id"]=$params["usergroup"]; //force consistency
                $otc=createAppointmentLocation($db,$params[$what]);
                array_push($values,$otc["id"]);
                $outcome["message"] = array_merge($outcome["message"],$otc["message"]);
              }catch(Exception $e){
                array_push($outcome["message"],$e->getMessage());
                array_push($values,null);
              }
            }else{//assume is numeric
              array_push($values,intval($params[$what]));
            }
            break;
          case "appt_interval":
            array_push($values,intval($params[$what]));
            break;
          case "usergroup":
            array_push($values,intval($params[$what]));
            break;
          default:
            array_push($values,base64_encode($params[$what]));
            break;
        }      
      }
    }
    //check for a minimum of data available before creating appointment
    if(isset($patient_id) && in_array("due_date",$keys) && in_array("location",$keys) && $outcome["status"]=="success"){
      try{
        $db = pdo_init();
        $update = $db->prepare('INSERT INTO appointments(' . implode(", ", $keys) . ') VALUES('.substr(str_repeat("?,", count($keys)),0,-1).') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id);');
        $update->execute($values);
        $outcome["status"]="success";
        $outcome["id"]=intval($db->lastInsertId());
        array_push($outcome["message"],"Created appointment ".$outcome["id"]." successfully.");
      }catch(Exception $e){
        $outcome["status"]=="error";
        array_push($outcome["message"],$e->getMessage());
        return $outcome;
      }
    }
    else{
      array_push($outcome["message"],"Insufficient or invalid data provided.");
      return $outcome;
    }
    //if appointment was created, update next appointment on patient table
    //but only if it is genuinely the next appointment
    if(isset($outcome["id"])){
      try{
        $stmt3 = $db->prepare('SELECT next_appointment FROM patient WHERE id=?;');
        $stmt3->execute([intval($patient_id)]);
        $res = $stmt3->fetchAll(PDO::FETCH_CLASS);
        if(strtotime($res[0]->next_appointment) < strtotime($params["due_date"])){
          $stmt4 = $db->prepare('UPDATE patient SET next_appointment=? WHERE id=?;');
          $stmt4->execute([intval($outcome["id"]),intval($patient_id)]);
        }
        array_push($outcome["message"], "Linked appointment to patient card successfully.");
      }catch(Exception $e){
        $outcome["status"]=="error";
        array_push($outcome["message"],$e->getMessage());
      }
    }
    return $outcome;
  }
//Get a patient by a specific id
  function getAppointmentById($db,$appointment_id){
    global $authed_user;
    if($authed_user["privilege"] < 1){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $appointment_id=intval($appointment_id);
    $outcome = Array("status"=>"error","message"=>["Appointment #".$appointment_id." could not be retrieved."],"sql"=>[]);
    if(($authed_user["privilege"] < 3 && !test_doctor_owns_appointment($authed_user,$appointment_id) ) || $appointment_id <= 0){ //min priv to view apppointments outside of your usergroup or that you didn't create 3. Min appt id is 1
      return $outcome;
    }
    try{
      $stmt = $db->prepare('
        SELECT
          appointments.appt_name, appointments.appt_purpose,appointments.appt_outcome,
          appointments.booked_date, appointments.due_date,
          appointments.cancelled_date,appointments.attended_date,
          appointments.reminder_count,appointments.last_reminder,
          appointments.doctor_id,appointments.patient_id,
          appointments.location, appt_locations.location_name, appt_locations.location_info,
          doctor.username,doctor.config, appt_locations.usergroup_id, usergroup.group_name
        FROM (((appointments
          LEFT JOIN doctor 
          ON appointments.doctor_id = doctor.id)
          LEFT JOIN appt_locations
          ON appointments.location=appt_locations.id)
          LEFT JOIN usergroup
          ON appt_locations.usergroup_id=usergroup.id)
        WHERE appointments.id=?;');
      $stmt->execute([intval($appointment_id)]);
      if($stmt->rowCount()>0){
        $outcome = Array("status"=>"success","message"=>["Found data successfully."]);
        $outcome["sql"] = $stmt->fetchAll(PDO::FETCH_CLASS);
      }
    }catch(Exception $e){
      $outcome["message"] = [$e->getMessage()];
    }
    return $outcome;
  }
  
//edit a patient's appointment
  function editAppointment($db,$appointment_id,$params){
    global $authed_user;
    if($authed_user["privilege"] < 2){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $patient_id = 0;
    $outcome = Array("status"=>"error","message"=>["Invalid userid"]);
    $to_set = Array("doctor_id","due_date","cancelled_date","attended_date","location","usergroup","appt_name","appt_purpose","appt_outcome","appt_interval"); //permitted values to set
    $keys = []; // when generating the sql query, what fields to update? Enter any presets here
    $values = []; //to pass into the prepared statement, will match up to $keys
    $outcome = Array("status"=>"error","message"=>[]);
    if(($authed_user["privilege"] < 3 && !test_doctor_owns_appointment($authed_user,$appointment_id) ) || $appointment_id <= 0){
      //min priv to view apppointments outside of your usergroup or that you didn't create is 3.
      // Min appt id is 1
      return Array("status"=>"error","message"=>["Unable to access appointment."]);
    }
    //test for existence and retrieve the existing pmh_config
    $stmt = $db->prepare('SELECT appt_outcome,patient_id FROM appointments WHERE id=?;');
    $stmt->execute([intval($appointment_id)]);
    $res = $stmt->fetchAll(PDO::FETCH_CLASS);
    if(count($res) > 0){
      $patient_id = intval($res[0]->patient_id);
      $appt_outcome=json_decode($res[0]->appt_outcome,true); 
      //return assoc array so we don't delete stuff if edit request is underspecified
    }else{
      return Array("status"=>"error","message"=>["Could not find appointment."]);
    }
    //look for whitelisted values 
    foreach($to_set as $what){
      if(isset($params[$what]) && $params[$what] != "" && $params[$what] != []){
        array_push($keys,$what . "=?");
        switch($what){
          case "doctor_id":
            array_push($values,intval($params[$what]));
            break;
          case "due_date":
            array_push($values,date("Y-m-d H:i:s", strtotime($params[$what])));//cast to sql date
            break;
          case "cancelled_date":
            // force mark as cancelled and wipe attended date if cancelled date set
            if(!array_key_exists("appt_outcome",$params)){
              array_push($keys,"appt_outcome=?");
              array_push($values,0);
              array_push($keys,"attended_date=?");
              array_push($values,NULL);
            }else{//we should not have encountered appt outcome yet due to loop ordering
              $params["appt_outcome"]=0;
            }
            array_push($values,date("Y-m-d H:i:s", strtotime($params[$what])));//cast to sql date
            break;
          case "attended_date":
            // force mark as attended and wipe cancelled date if attended date set
            if(!array_key_exists("appt_outcome",$params)){
              array_push($keys,"appt_outcome=?");
              array_push($values,3);
              array_push($keys,"cancelled_date=?");
              array_push($values,NULL);
            }else{
              $params["appt_outcome"]=3;
            }
            array_push($values,date("Y-m-d H:i:s", strtotime($params[$what])));//cast to sql date
            break;
          case "location":
            if(gettype($params[$what])=="string"){// if a string is supplied, assume we want to create an appointment location unless it is numeric
              if(is_numeric($params[$what])){
                array_push($values,intval($params[$what]));
              }else{
                if(!isset($params["usergroup"])){//
                  array_push($outcome["message"],"Cannot create location without usergroup so setting to null.");
                  array_push($values,null);
                }else{
                  if(strlen(base64_encode($params["location"]))>=512){
                    array_push($outcome["message"],"Location name too long so was truncated.");
                  }
                  try{
                    $otc=createAppointmentLocation($db,Array("usergroup_id"=>intval($params["usergroup"]),
                                                             "location_name"=>$params["location"]));
                    array_push($values,intval($otc["id"]));
                    $outcome["message"] = array_merge($outcome["message"],$otc["message"]);
                  }catch(Exception $e){
                    array_push($outcome["message"],$e->getMessage());
                    array_push($values,null);
                  }
                }
              }
            }else if(gettype($params[$what])=="array"){// assume array was given in format [usergroup,location name, location info] and pass it on to create appt location
              try{
                $params[$what]["usergroup_id"]=$params["usergroup"]; //copy usergroup id into object
                $otc=createAppointmentLocation($db,$params[$what]);
                array_push($values,$otc["id"]);
                $outcome["message"] = array_merge($outcome["message"],$otc["message"]);
              }catch(Exception $e){
                array_push($outcome["message"],$e->getMessage());
                array_push($values,null);
              }
            }else{//assume is numeric
              array_push($values,intval($params[$what]));
            }
            break;
          case "usergroup":
            array_push($values,intval($params[$what]));
            break;
          case "appt_interval":
            array_push($values,intval($params[$what]));
            break;
          case "appt_outcome":
            //regardless of what it is now we should not need to change this
            array_push($values,intval($params[$what]));
            //assume it should have not been already set prior to this due to loop order
            if(!array_key_exists("cancelled_date",$params) &&
               !array_key_exists("attended_date",$params) &&
               !in_array("appt_outcome",$keys)){
              //but if it hasn't been set because it hasn't been provided
              //we should set the relevant entries
              switch(intval($params[$what])){
                case 0:
                  $temp = array_search('cancelled_date',$keys);
                  if($temp === false){
                    array_push($keys,"cancelled_date=?");
                    array_push($values,date("Y-m-d H:i:s"));
                  }else{
                    $values[$temp]=date("Y-m-d H:i:s");
                  }
                  array_push($keys,"attended_date=?");
                  array_push($values,null);
                  break;
                case 3:
                case 4:
                  $temp = array_search('attended_date',$keys);
                  if($temp === false){
                    array_push($keys,"attended_date=?");
                    array_push($values,date("Y-m-d H:i:s"));
                  }else{
                    $values[$temp]=date("Y-m-d H:i:s");
                  }
                  array_push($keys,"cancelled_date=?");
                  array_push($values,null);
                  break;
                default:
                  array_push($keys,"attended_date=?");
                  array_push($values,null);
                  array_push($keys,"cancelled_date=?");
                  array_push($values,null);
                  break;
              }
            }
            break;
          default:
            array_push($values,base64_encode($params[$what]));
            break;
        }      
      }
    }
//    var_dump($params);
//    var_dump($keys);
//    var_dump($values);
    //check for a minimum of data available before ediitng appointment
    if(count($keys)>0 && intval($appointment_id)>0){
      //append appt id to values for sql purposes
      array_push($values,intval($appointment_id));
      try{
        $db = pdo_init();
        $update = $db->prepare('UPDATE appointments SET ' . implode(", ", $keys) . ' WHERE id=?;');
        $update->execute($values);
        $outcome["status"]="success";
        $outcome["id"]=intval($db->lastInsertId());
        array_push($outcome["message"],"Edited appointment #".$appointment_id." successfully.");
      }catch(Exception $e){
        array_push($outcome["message"],$e->getMessage());
        return $outcome;
      }
      try{
        $stmt3 = $db->prepare('SELECT id FROM appointments WHERE appt_outcome=1 AND attended_date IS NULL AND patient_id=? ORDER BY due_date ASC LIMIT 1;');
        $stmt3->execute([intval($patient_id)]);
        $res = $stmt3->fetchAll(PDO::FETCH_CLASS);
        $stmt4 = $db->prepare('UPDATE patient SET next_appointment=? WHERE id=?;');
        if(count($res)>0){
          $stmt4->execute([$res[0]->id,intval($patient_id)]);
          array_push($outcome["message"], "Updated most recent appointment for patient #".intval($patient_id).".");
        }else{
          $stmt4->execute([NULL,intval($patient_id)]);
        }
      }catch(Exception $e){
        $outcome["status"]=="error";
        array_push($outcome["message"],$e->getMessage());
      }
      //update if patient has attended an appointment
      if(in_array("attended_date=?",$keys) || in_array("cancelled_date=?",$keys)){
        //should have been set by now
        try{
          $stmt3 = $db->prepare('SELECT id FROM appointments WHERE appt_outcome=3 OR appt_outcome=4 AND patient_id=? ORDER BY attended_date DESC LIMIT 1;');
          $stmt3->execute([intval($patient_id)]);
          $res = $stmt3->fetchAll(PDO::FETCH_CLASS);
          $stmt4 = $db->prepare('UPDATE patient SET last_attended_appointment=? WHERE id=?;');
          if(count($res)>0){
            $stmt4->execute([$res[0]->id,intval($patient_id)]);
            array_push($outcome["message"], "Recorded that patient #". intval($patient_id) ." has attended this appointment.");
          }else{
            $stmt4->execute([NULL,intval($patient_id)]);
          }
        }catch(Exception $e){
          $outcome["status"]=="error";
          array_push($outcome["message"],$e->getMessage());
        }
      }
    }
    else{
      array_push($outcome["message"],"Insufficient or invalid data provided.");
      return $outcome;
    }
    return $outcome;
  }
  
//create appointment file
  function createAppointmentFile($db,$appointment_id,$params){
    global $authed_user;
    global $file_dir_location;
    if($authed_user["privilege"] < 2){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    if($appointment_id <= 0 || ($authed_user["privilege"] <= 4 && !test_doctor_owns_appointment($authed_user,$appointment_id))){
      array_push($outcome["message"], "Insufficient permissions");
      return $outcome;
    }
    $to_set = Array("filename","mime_type","content"); //permitted values to set
    $keys = ["appointment_id"]; // when generating the sql query, what fields to update? Enter any presets here
    $values = [$appointment_id]; //to pass into the prepared statement, will match up to $keys
    $outcome = Array("status"=>"error","message"=>[],"id"=>null);
    //look for whitelisted values 
    foreach($to_set as $what){
      if(isset($params[$what])){
        array_push($keys,$what);
        switch($what){
          case "filename":
            if(count($params[$what])>0){
              array_push($values,base64_encode($params[$what]));
            }else{
              array_push($outcome["message"], "Blank filename provided.");
              return $outcome;
            }
            break;
          case "content":
            //generate arbitrary hash to write file to
            $fname = hash("sha512",$params["filename"].$appointment_id.time());
            //try moving file to directory
            if(!move_uploaded_file($params[$what],$file_dir_location.$fname)){
              $outcome["status"]=="error";
              array_push($outcome["message"],"Input/output error, check directory in config file and check permissions.");
              return $outcome;
            }
            //push file location to database
            array_push($values,$fname);
            break;
          default:
            array_push($values,base64_encode($params[$what]));
            break;
        }      
      }
    }
    //check for a minimum of data available before creating appointment
    //first entry is appt_id, we test it is nonzero
    if( intval($values[0])>0 && in_array("filename",$keys) && in_array("mime_type",$keys) && in_array("content",$keys)){
      try{
        $db = pdo_init();
        $update = $db->prepare('INSERT INTO appt_docs(' . implode(", ", $keys) . ') VALUES('.substr(str_repeat("?,", count($keys)),0,-1).');');
        $update->execute($values);
        $outcome["status"]="success";
        $outcome["id"]=intval($db->lastInsertId());
        array_push($outcome["message"],"Added file #".$outcome["id"]." to appointment #".intval($appointment_id)." successfully.");
      }catch(Exception $e){
        $outcome["status"]=="error";
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

//Lists all appointment files for a given patient
  function listAppointmentFilesByPatient($db,$patient_id){
    global $authed_user;
    if($authed_user["privilege"] < 1){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $patient_id = intval($patient_id);
    $outcome = Array("status"=>"error","message"=>[]);
    if($patient_id <= 0){ //id must gt 0
      return Array("status"=>"error","message"=>["Invalid appointment id"]);
    }else{
      try{
        // Does the user have the right to view the files?
        if($authed_user["privilege"] < 4 && !is_doctor_responsible($db,$authed_user,$patient_id)){
          array_push($outcome["message"],"Insufficient permissions.");
          return $outcome;
        };
      }catch(Exception $e){
        array_push($outcome["message"],$e->getMessage());
        return $outcome;
      }
      // List files
      try{
        $stmt = $db->prepare('
        SELECT appt_docs.*
          FROM (appt_docs 
            INNER JOIN appointments 
            ON appt_docs.appointment_id=appointments.id)
          WHERE appointments.patient_id=?
          AND mime_type LIKE "dGV4dC9wbGFpbg%"
        ORDER BY date_edited ASC;');
        $stmt->execute([intval($patient_id)]);
        $res = $stmt->fetchAll(PDO::FETCH_CLASS);
        $outcome = Array("status"=>"success","message"=> ["Found data successfully."],"sql"=>[$res]);
      }catch(Exception $e){
        array_push($outcome["sql"],[]);
        $outcome = Array("status"=>"error","message"=>[$e->getMessage()]);
      }
      try{
        $stmt = $db->prepare('
        SELECT appt_docs.*
          FROM (appt_docs 
            INNER JOIN appointments 
            ON appt_docs.appointment_id=appointments.id)
          WHERE appointments.patient_id=?
          AND mime_type LIKE "aW1hZ2Uv%"
        ORDER BY date_edited ASC;');
        $stmt->execute([intval($patient_id)]);
        $res = $stmt->fetchAll(PDO::FETCH_CLASS);
        array_push($outcome["sql"],$res);
      }catch(Exception $e){
        array_push($outcome["sql"],[]);
        array_push($outcome["message"],$e->getMessage());
      }
      try{
        $stmt = $db->prepare('
        SELECT appt_docs.*
          FROM (appt_docs 
            INNER JOIN appointments 
            ON appt_docs.appointment_id=appointments.id)
          WHERE appointments.patient_id=?
          AND mime_type LIKE "dmlkZW8v%"
        ORDER BY date_edited ASC;');
        $stmt->execute([intval($patient_id)]);
        $res = $stmt->fetchAll(PDO::FETCH_CLASS);
        array_push($outcome["sql"],$res);
      }catch(Exception $e){
        array_push($outcome["sql"],[]);
        array_push($outcome["message"],$e->getMessage());
      }
      try{
        $stmt = $db->prepare('
        SELECT appt_docs.*
          FROM (appt_docs
            INNER JOIN appointments 
            ON appt_docs.appointment_id=appointments.id)
          WHERE appointments.patient_id=?
          AND appt_docs.mime_type NOT LIKE "dGV4dC9wbGFpbg%"
          AND appt_docs.mime_type NOT LIKE "aW1hZ2Uv%"
          AND appt_docs.mime_type NOT LIKE "dmlkZW8v%"
        ORDER BY date_edited ASC;');
        $stmt->execute([intval($patient_id)]);
        $res = $stmt->fetchAll(PDO::FETCH_CLASS);
        array_push($outcome["sql"],$res);
      }catch(Exception $e){
        array_push($outcome["sql"],[]);
        array_push($outcome["message"],$e->getMessage());
      }
    }
    return $outcome;
  }

//Lists appointment files for a given appointment id
  function listAppointmentFilesForId($db,$appointment_id){
    global $authed_user;
    if($authed_user["privilege"] < 1){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $appointment_id = intval($appointment_id);
    $outcome = Array("status"=>"error","message"=>[]);
    if($appointment_id <= 0){ //id must gt 0
      return Array("status"=>"error","message"=>["Invalid appointment id"]);
    }else{
      try{
        // Does the user have the right to view the files?
        if($authed_user["privilege"] < 4 && !test_doctor_owns_appointment($authed_user,$appointment_id)){
          array_push($outcome["message"],"Insufficient permissions.");
          return $outcome;
        };
      }catch(Exception $e){
        array_push($outcome["message"],$e->getMessage());
        return $outcome;
      }
      // List files
      try{
        $stmt = $db->prepare('
        SELECT * 
          FROM appt_docs 
          WHERE appointment_id=?
            AND mime_type LIKE "dGV4dC9wbGFpbg%"
        ORDER BY date_edited ASC;');
        $stmt->execute([intval($appointment_id)]);
        $res = $stmt->fetchAll(PDO::FETCH_CLASS);
        $outcome = Array("status"=>"success","message"=> ["Found data successfully."],"sql"=>[$res]);
      }catch(Exception $e){
        array_push($outcome["sql"],[]);
        $outcome = Array("status"=>"error","message"=>[$e->getMessage()]);
      }
      try{
        $stmt = $db->prepare('
        SELECT * 
          FROM appt_docs 
          WHERE appointment_id=?
            AND mime_type LIKE "aW1hZ2Uv%"
        ORDER BY date_edited ASC;');
        $stmt->execute([intval($appointment_id)]);
        $res = $stmt->fetchAll(PDO::FETCH_CLASS);
        array_push($outcome["sql"],$res);
      }catch(Exception $e){
        array_push($outcome["sql"],[]);
        array_push($outcome["message"],$e->getMessage());
      }
      try{
        $stmt = $db->prepare('
        SELECT * 
          FROM appt_docs 
          WHERE appointment_id=?
            AND mime_type LIKE "dmlkZW8v%"
        ORDER BY date_edited ASC;');
        $stmt->execute([intval($appointment_id)]);
        $res = $stmt->fetchAll(PDO::FETCH_CLASS);
        array_push($outcome["sql"],$res);
      }catch(Exception $e){
        array_push($outcome["sql"],[]);
        array_push($outcome["message"],$e->getMessage());
      }      
      try{
        $stmt = $db->prepare('
        SELECT *
          FROM appt_docs 
          WHERE appointment_id=?
          AND mime_type NOT LIKE "dGV4dC9wbGFpbg%"
          AND mime_type NOT LIKE "aW1hZ2Uv%"
          AND mime_type NOT LIKE "dmlkZW8v%"
        ORDER BY date_edited ASC;');
        $stmt->execute([intval($appointment_id)]);
        $res = $stmt->fetchAll(PDO::FETCH_CLASS);
        array_push($outcome["sql"],$res);
      }catch(Exception $e){
        array_push($outcome["sql"],[]);
        array_push($outcome["message"],$e->getMessage());
      }
    }
    return $outcome;
  }

//todo
function editAppointmentFile($db,$appt_file_id,$params){
    global $authed_user;
    global $file_dir_location;
    if($authed_user["privilege"] < 2){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $outcome = Array("status"=>"error","message"=>["Invalid userid"]);
    $to_set = Array("appointment_id","filename"); //permitted values to set
    $keys = ["date_edited"]; // when generating the sql query, what fields to update? Enter any presets here
    $values = [date("Y-m-d H:i:s")]; //to pass into the prepared statement, will match up to $keys
    $outcome = Array("status"=>"error","message"=>[]);

    //test for existence and retrieve the existing appt id
    $stmt = $db->prepare('SELECT appointment_id,content FROM appt_docs WHERE id=?;');
    $stmt->execute([intval($appt_file_id)]);
    $res = $stmt->fetchAll(PDO::FETCH_CLASS);
    if(count($res) > 0){
      $appointment_id=intval($res[0]->appointment_id); 
      if(($authed_user["privilege"] < 3 && !test_doctor_owns_appointment($authed_user,$appointment_id) ) || $appointment_id <= 0){
        //min priv to edit apppointments outside of your usergroup or that you didn't create is 3.
        // Min appt id is 1
        return Array("status"=>"error","message"=>["Unable to access appointment."]);
      }
    }else{
      return Array("status"=>"error","message"=>["Could not find appointment file."]);
    }
    //look for whitelisted values 
    foreach($to_set as $what){
      if(isset($params[$what])){
        array_push($keys,$what . " = ? ");
        switch($what){
          case "appointment_id":
            if($appointment_id >= 0 && ($authed_user["privilege"] >= 4 || test_doctor_owns_appointment($authed_user,$appointment_id))){
              array_push($values, intval($params[$what]));//cast to sql date
            }else{
              array_push($outcome["message"], "Insufficient permissions");
              return $outcome;
            }
            break;
          case "filename":
            if(count($params[$what])>0){
              array_push($values,base64_encode($params[$what]));
            }else{
              array_push($outcome["message"], "Blank filename provided.");
              return $outcome;
            }
            break;
          default:
            array_push($values,base64_encode($params[$what]));
            break;
        }      
      }
    }
    
    //check for a minimum of data available before ediitng appointment file
    if(in_array("appointment_id",$keys) && in_array("filename",$keys) && isset($params["content"])){
      //retrieve file location
      $fname = $res[0]->content;
      //try overwriting file
      if(!move_uploaded_file($params["content"],$file_dir_location.$fname)){
        $outcome["status"]=="error";
        array_push($outcome["message"],"Input/output error, check directory in config file and check permissions.");
        return $outcome;
      }
      //push file location to database
      array_push($values,$fname);
      try{
        $db = pdo_init();
        $update = $db->prepare('UPDATE appt_docs SET ' . implode(", ", $keys) . ' WHERE id=?;');
        $update->execute($values);
        $outcome["status"]="success";
//var_dump('UPDATE appt_docs SET ' . implode(", ", $keys) . ' WHERE id=?;');
        array_push($outcome["message"],"Edited file #".$appt_file_id." successfully.");
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

//Deletes a given appointment file
  function deleteAppointmentFile($db,$appt_file_id){
    global $authed_user;
    if($authed_user["privilege"] < 2){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $appt_file_id = intval($appt_file_id);
    $outcome = Array("status"=>"error","message"=>[]);
    if($appt_file_id <= 0){ //id must gt 0
      return Array("status"=>"error","message"=>["Invalid appointment file id"]);
    }else{
      try{
        // Does the given id exist, and if get appointment id to validate user
        $stmt = $db->prepare('SELECT appointment_id,content FROM appt_docs WHERE id=?;');
        $stmt->execute([$appt_file_id]);
        $res = $stmt->fetchAll(PDO::FETCH_CLASS);
        if(count($res)<= 0 || strlen($res[0]->content)!=128){
          array_push($outcome["message"],"File ". $appt_file_id ." not found");
          return $outcome;
        }
        if($authed_user["privilege"] < 4 && !test_doctor_owns_appointment($authed_user,intval($res[0]->appointment_id))){
          array_push($outcome["message"],"Insufficient permissions.");
          return $outcome;
        };
      }catch(Exception $e){
        array_push($outcome["message"],$e->getMessage());
        return $outcome;
      }
      // Delete file if all goes well
      try{
        if(unlink($file_dir_location.$res[0]->content)){
          $query = $db->prepare('DELETE FROM appt_docs WHERE id=?;');
          $query->execute([intval($appt_file_id)]);
          $outcome = Array("status"=>"success","message"=> ["File " . $appt_file_id . " deleted successfully."]);
        }else{
          $outcome = Array("status"=>"error","message"=>["Input/output error, check config file"]);
          return $outcome;
        }
      }catch(Exception $e){
        $outcome = Array("status"=>"error","message"=>[$e->getMessage()]);
      }
    }
    return $outcome;
  }

//Delete appointment by id
  function deleteAppointment($db,$appointment_id){
    global $authed_user;
    global $file_dir_location;
    if($authed_user["privilege"] < 2){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $appointment_id = intval($appointment_id);
    $outcome = Array("status"=>"error","message"=>[]);
    if($appointment_id <= 0){ //id must gt 0
      $outcome = Array("status"=>"error","message"=>["Invalid appointment id"]);
    }else{
      try{
        // Does the given id exist, and if so what is the usergroup id?
        $stmt = $db->prepare('SELECT usergroup_id FROM appointments WHERE id=?;');
        $stmt->execute([$appointment_id]);
        $res = $stmt->fetchAll(PDO::FETCH_CLASS);
        if(count($res)<= 0){
          array_push($outcome["message"],"Appointment ". $appointment_id ." not found");
          return $outcome;
        }
      }catch(Exception $e){
        array_push($outcome["message"],$e->getMessage());
        return $outcome;
      }
      // Does the user have the right to do this?
      if(!test_user_in_group($authed_user,$res[0]->usergroup_id) && $authed_user["privilege"] < 4){
        array_push($outcome["message"],"Insufficient permissions.");
      }else{
        try{
          $query = $db->prepare('DELETE FROM appointments WHERE id = ?;');
          $query->execute([intval($appointment_id)]);
          $outcome = Array("status"=>"success","message"=> ["Appointment " . $appointment_id . " deleted successfully."]);
          //Update corollary fields in patient table
          try{
            $stmt3 = $db->prepare('SELECT due_date FROM appointments WHERE appt_outcome=1 AND attended_date IS NULL AND patient_id=? ORDER BY due_date ASC LIMIT 1;');
            $stmt3->execute([intval($patient_id)]);
            $res = $stmt3->fetchAll(PDO::FETCH_CLASS);
            $stmt4 = $db->prepare('UPDATE patient SET next_appointment=? WHERE id=?;');
            $stmt4->execute([$res[0]->due_date,intval($patient_id)]);
            array_push($outcome["message"], "Updated most recent appointment on patient card.");
          }catch(Exception $e){
            $outcome["status"]=="error";
            array_push($outcome["message"],$e->getMessage());
          }
        }catch(Exception $e){
          $outcome = Array("status"=>"error","message"=>[$e->getMessage()]);
        }
      }
    }
    return $outcome;
  }

// List all appointments that a patient has
  function listAppointmentForPatient($db,$patient_id){
    global $authed_user;
    if($authed_user["privilege"] < 1){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $outcome = Array("status"=>"error","message"=>["No valid appointment found."],"sql"=>[]);
    if(is_doctor_responsible($db,$authed_user,$patient_id)){
      try{
        $stmt = $db->prepare('
          SELECT appointments.*,messages.sent,appt_locations.location_name,appt_locations.location_info FROM 
            ((appointments
            LEFT JOIN appt_locations
            ON appointments.location=appt_locations.id)
            LEFT JOIN messages
            ON appointments.last_reminder=messages.id)
            WHERE appointments.patient_id=?
            ORDER BY appointments.due_date DESC;');
        $stmt->execute([$patient_id]);
        $outcome = Array("status"=>"success","message"=>["Found data successfully."]);
        $outcome["sql"] = $stmt->fetchAll(PDO::FETCH_CLASS);
      }catch(Exception $e){
        $outcome["message"] = $e->getMessage();
      }
    }
    return $outcome;
  }

//set appointment outcome
  function setAppointmentOutcome($db,$appointment_id,$appt_outcome,$attended=True){
    global $authed_user;
    if($authed_user["privilege"] < 2){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $appointment_id = intval($appointment_id);
    $outcome = Array("status"=>"error","message"=>[]);
    if($appointment_id <= 0){ //userid must gt 0
      $outcome = Array("status"=>"error","message"=>["Invalid appointment id"]);
    }else{
      try{
        // Does the given id exist, and if so what is the usergroup id?
        $stmt = $db->prepare('SELECT patient_id,usergroup_id FROM appointments WHERE id=?;');
        $stmt->execute([$appointment_id]);
        $res = $stmt2->fetchAll(PDO::FETCH_CLASS);
        if(count($res)<= 0){
          array_push($outcome["message"],"Appointment ". $appointment_id ." not found");
          return $outcome;
        }
      }catch(Exception $e){
        array_push($outcome["message"],$e->getMessage());
        return $outcome;
      }
      // Does the user have the right to do this?
      if(!test_user_in_group($authed_user,$res[0]->usergroup_id) && $authed_user["privilege"] < 4){
        array_push($outcome["message"],"Insufficient permissions.");
      }else{
        try{
          $query = $db->prepare('UPDATE appointments SET appt_outcome=? WHERE id = ?;');
          $query->execute([base64_encode($appt_outcome),intval($appointment_id)]);
          $outcome = Array("status"=>"success","message"=> ["Outcome for appointment " . $appointment_id . " updated successfully."]);
        }catch(Exception $e){
          $outcome = Array("status"=>"error","message"=>[$e->getMessage()]);
        }
        if($attended){
          try{
            $query2 = $db->prepare('UPDATE patient SET last_attended_appointment=? WHERE id = ?;');
            $query2->execute([intval($appointment_id),intval($res[0]->patient_id)]);
            $outcome = Array("status"=>"success","message"=> ["Updated patient card successfully."]);
          }catch(Exception $e){
            $outcome = Array("status"=>"error","message"=>[$e->getMessage()]);
          }
        }
      }
    }
    return $outcome;
  }
//creates appointment template - buggy location setting if array provided and already in
  function createAppointmentTemplate($db,$params){
    global $authed_user;
    if($authed_user["privilege"] < 2){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $to_set = Array("usergroup_id","location","appt_name","appt_purpose","typical_interval"); //permitted values to set
    $keys = []; // when generating the sql query, what fields to update? Enter any presets here
    $values = []; //to pass into the prepared statement, will match up to $keys
    $outcome = Array("status"=>"error","message"=>[],"id"=>null);
    //look for whitelisted values 
    foreach($to_set as $what){
      if(isset($params[$what])){
        array_push($keys,$what);
        switch($what){
          case "usergroup_id":
            if(intval($params[$what])>0 && test_user_in_group($authed_user,intval($params[$what]))){
              array_push($values,intval($params[$what]));
            }else{
              array_push($outcome["message"],"Invalid usergroup.");
            }
            break;
          case "typical_interval":
            array_push($values,intval($params[$what]));
            break;
          case "location":
            if(gettype($params[$what])=="string"){// if a string is supplied, assume we want to create an appointment location unless it is numeric
              if(is_numeric($params[$what])){
                array_push($values,intval($params[$what]));
              }else{
                if(!isset($params["usergroup_id"])){//
                  array_push($outcome["message"],"Cannot create location without usergroup so setting to null.");
                  array_push($values,null);
                }else{
                  if(strlen(base64_encode($params["location"]))>=512){
                    array_push($outcome["message"],"Location name too long so was truncated.");
                  }
                  try{
                    $otc=createAppointmentLocation($db,Array("usergroup_id"=>intval($params["usergroup_id"]),
                                                             "location_name"=>$params["location"],
                                                             "location_info"=>""));
                    array_push($values,$otc["id"]);
                    $outcome["message"] = array_merge($outcome["message"],$otc["message"]);
                  }catch(Exception $e){
                    array_push($outcome["message"],$e->getMessage());
                    array_push($values,null);
                  }
                }
              }
            }else if(gettype($params[$what])=="array"){// assume array was given in format [usergroup_id,location name, location info] and pass it on to create appt location
              try{
                $params[$what]["usergroup_id"]=$params["usergroup_id"]; //force consistency
                $otc=createAppointmentLocation($db,$params[$what]);
                array_push($values,$otc["id"]);
                $outcome["message"] = array_merge($outcome["message"],$otc["message"]);
              }catch(Exception $e){
                array_push($outcome["message"],$e->getMessage());
                array_push($values,null);
              }
            }else{//assume is numeric
              array_push($values,intval($params[$what]));
            }
            break;
          default:
            array_push($values,base64_encode($params[$what]));
            break;
        }      
      }
    };
    //check for a minimum of data available before creating template
    if(in_array("usergroup_id",$keys) && in_array("location",$keys) && in_array("appt_name",$keys) ){
      try{
        $db = pdo_init();
        $update = $db->prepare('INSERT INTO appt_templates(' . implode(", ", $keys) . ') VALUES('.substr(str_repeat("?,", count($keys)),0,-1).') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id);');
        $update->execute($values);
        $outcome["status"]="success";
        $outcome["id"]=intval($db->lastInsertId());
        array_push($outcome["message"],"Created appointment template ".$outcome["id"]." successfully.");
      }catch(Exception $e){
        array_push($outcome["message"],$e->getMessage());
        return $outcome;
      }
    }else{
      array_push($outcome["message"],"Insufficient or invalid data provided.");
      return $outcome;
    }
    return $outcome;
  }

//todo
  function editAppointmentTemplate($db,$id,$params){
    global $authed_user;
    if($authed_user["privilege"] < 2){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $outcome = Array("status"=>"error","message"=>[]);
    return $outcome;
  }

//List all appointment templates for a given usergroup list
  function listMyAppointmentTemplates($db,$usergroup_ids=null){
    global $authed_user;
    if($authed_user["privilege"] < 2){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    if(is_null($usergroup_ids)){
      $usergroup_ids=[];
      foreach($authed_user["usergroups"] as $ug){
        array_push($usergroup_ids,intval($ug->usergroup_id));
      }
    }
    if(gettype($usergroup_ids)=="integer"){
      if(intval($usergroup_ids) <= 0){
        $outcome["message"] = "Invalid usergroup";
        return $outcome;
      }
      $usergroup_ids=[$usergroup_ids];
    }
    $outcome = Array("status"=>"error","message"=>["Invalid userid"],"sql"=>[]);
    try{
      $stmt = $db->prepare('
        SELECT appt_templates.id,appt_templates.location,appt_templates.appt_name,appt_templates.appt_purpose,appt_templates.typical_interval,
               usergroup.id AS usergroup_id, usergroup.group_name,usergroup.descr
          FROM ((appt_templates
            INNER JOIN doctor_membership_table 
            ON appt_templates.usergroup_id = doctor_membership_table.usergroup_id)
            INNER JOIN usergroup
            ON appt_templates.usergroup_id = usergroup.id)
        WHERE '.implode(" OR ",array_fill(0, count($usergroup_ids), 'doctor_membership_table.usergroup_id=?')).';');
      $stmt->execute($usergroup_ids);
      $outcome = Array("status"=>"success","message"=>["Found data successfully."]);
      $outcome["sql"] = $stmt->fetchAll(PDO::FETCH_CLASS);
    }catch(Exception $e){
      array_push($outcome["message"],$e->getMessage());
    }
    return $outcome;
  }

//Delete appointment template by id
  function deleteAppointmentTemplate($db,$template_id){
    global $authed_user;
    if($authed_user["privilege"] < 2){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $template_id = intval($template_id);
    $outcome = Array("status"=>"error","message"=>[]);
    if($template_id <= 0){ //userid must gt 0
      $outcome = Array("status"=>"error","message"=>["Invalid appointment template id"]);
    }else{
      try{
        // Does the given template id exist, and if so what is the corresponding usergroup id?
        $stmt = $db->prepare('SELECT usergroup_id FROM appt_templates WHERE id=?;');
        $stmt->execute([$template_id]);
        $res = $stmt->fetchAll(PDO::FETCH_CLASS);
        if(count($res)<= 0){
          array_push($outcome["message"],"Template ". $template_id ." not found");
          return $outcome;
        }
      }catch(Exception $e){
        array_push($outcome["message"],$e->getMessage());
      }
      // Does the user have the right to do this?
      if(!test_user_in_group($authed_user,$res[0]->usergroup_id) && $authed_user["privilege"] < 4){
        array_push($outcome["message"],"Insufficient permissions.");
      }else{
        try{
          $query = $db->prepare('DELETE FROM appt_templates WHERE id = ?;');
          $query->execute([intval($template_id)]);
          $outcome = Array("status"=>"success","message"=> ["Template " .$template_id . " deleted successfully."]);
        }catch(Exception $e){
          $outcome = Array("status"=>"error","message"=>[$e->getMessage()]);
        }
      }
    }
    return $outcome;
  }
//create appointment location
  function createAppointmentLocation($db,$params){
    global $authed_user;
    if($authed_user["privilege"] < 2){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $outcome = Array("status"=>"error","message"=>[],"id"=>null);
    $to_set = Array("usergroup_id","location_name","location_info"); //permitted values to set
    $keys = []; // when generating the sql query, what fields to update? Enter any presets here
    $values = []; //to pass into the prepared statement, will match up to $keys
    //look for whitelisted values 
    foreach($to_set as $what){
      if(isset($params[$what])){
        array_push($keys,$what);
        switch($what){
          case "usergroup_id":
            if(intval($params[$what])>0 && test_user_in_group($authed_user,intval($params[$what]))){
              array_push($values,intval($params[$what]));
            }else{
              array_push($outcome["message"],"Invalid usergroup.");
              return $outcome;
            }
            break;
          default:
            array_push($values,base64_encode($params[$what]) );
            break;
        }      
      }
    }
    //check for a minimum of data available before creating location
    if(in_array("usergroup_id",$keys) && in_array("location_name",$keys) && count($outcome["message"]) <= 0){
      try{
        $db = pdo_init();
        $update = $db->prepare('INSERT INTO appt_locations(' . implode(", ", $keys) . ') VALUES('.substr(str_repeat("?,", count($keys)),0,-1).') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id);');
        $update->execute($values);
        $outcome["status"]="success";
        $outcome["id"]=intval($db->lastInsertId());
        array_push($outcome["message"],"Created appointment location ".$outcome["id"]." successfully.");
      }catch(Exception $e){
        array_push($outcome["message"],$e->getMessage());
        return $outcome;
      }
    }
    else{
      array_push($outcome["message"],"Insufficient or invalid data provided.");
    }
    return $outcome;
  }
//edit appointment locations
  function editAppointmentLocation($db,$location_id,$params){
    global $authed_user;
    if($authed_user["privilege"] < 2){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $outcome = Array("status"=>"error","message"=>[]);
    if($location_id <= 0){ //id must gt 0
      $outcome = Array("status"=>"error","message"=>["Invalid appointment location id"]);
    }else{
      try{
        // Does the given id exist, and if so what is the usergroup id?
        $stmt = $db->prepare('SELECT usergroup_id FROM appt_locations WHERE id=?;');
        $stmt->execute([$location_id]);
        $res = $stmt2->fetchAll(PDO::FETCH_CLASS);
        if(count($res)<= 0){
          array_push($outcome["message"],"Location ". $template_id ." not found");
        }
        // Does the user have the right to do this?
        if(!test_user_in_group($authed_user,$res[0]->usergroup_id)){
          array_push($outcome["message"],"Insufficient permissions.");
        }
      }catch(Exception $e){
        array_push($outcome["message"],$e->getMessage());
      }
    }
    $to_set = Array("usergroup","location_name","location_info"); //permitted values to set
    $keys = []; // when generating the sql query, what fields to update? Enter any presets here
    $values = []; //to pass into the prepared statement, will match up to $keys
    $outcome = Array("status"=>"error","message"=>[]);
    //look for whitelisted values 
    foreach($to_set as $what){
      if(isset($params[$what])){
        array_push($keys,$what ." = ?");
        switch($what){
          case "usergroup_id":
            if(intval($params[$what])>0 && test_user_in_group($authed_user,intval($params[$what]))){
              array_push($values,intval($params[$what]));
            }else{
              array_push($outcome["message"],"Invalid usergroup.");
              return $outcome;
            }
            break;
          default:
            array_push($values,base64_encode($params[$what]) );
            break;
        }      
      }
    }
    array_push($values,intval($location_id) );
    //check for a minimum of data available before creating location
    if(count($keys) > 0 && count($outcome["message"]) <= 0 ){
      try{
        $db = pdo_init();
        $update = $db->prepare('UPDATE appt_locations SET ' . implode(", ", $keys) . ' WHERE id=?;');
        $update->execute($values);
        $outcome["status"]="success";
        $outcome["id"]=intval($db->lastInsertId());
        array_push($outcome["message"],"Updated appointment location ".$outcome["id"]." successfully.");
      }catch(Exception $e){
        array_push($outcome["message"],$e->getMessage());
        return $outcome;
      }
    }
    else{
      array_push($outcome["message"],"Insufficient or invalid data provided.");
    }
    return outcome;
  }
// List appointment locations for a usergroup list - should be an array, if not we cast to array
  function listUsergroupAppointmentLocations($db,$usergroup_ids=null){
    global $authed_user;
    if($authed_user["privilege"] < 1){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $outcome = Array("status"=>"error","message"=>[],"sql"=>[]);
    if($authed_user["privilege"] < 4 && !test_user_in_group($authed_user,$usergroup_id)){
      $outcome["message"] = "Cannot view this usergroup";
     return $outcome;
    }
    if(is_null($usergroup_ids)){
      $usergroup_ids=[];
      foreach($authed_user["usergroups"] as $ug){
        array_push($usergroup_ids,intval($ug->usergroup_id));
      }
    }
    if(gettype($usergroup_ids)!="array"){//force cast
      if(intval($usergroup_ids) <= 0){
        $outcome["message"] = "Invalid usergroup";
        return $outcome;
      }
      $usergroup_ids=[intval($usergroup_ids)];
    }
    try{
      $db = pdo_init();
      $stmt = $db->prepare('SELECT * FROM appt_locations WHERE '.implode(" OR ",array_fill(0, count($usergroup_ids), 'usergroup_id=?')).';');
      $stmt->execute($usergroup_ids);
      $outcome["status"]="success";
      array_push($outcome["message"],"Listed data successfully for usergroup(s) ".implode(", ",$usergroup_ids).".");
      $outcome["sql"]=$stmt->fetchAll(PDO::FETCH_CLASS);
    }catch(Exception $e){
      array_push($outcome["message"],$e->getMessage());
      die();
    }
    return $outcome;
  }

//Delete appointment location
  function deleteAppointmentLocation($db,$location_id){
    global $authed_user;
    if($authed_user["privilege"] < 2){ //set minimum priv to run function
      http_response_code(418);
      die();
    }
    $location_id = intval($location_id);
    $outcome = Array("status"=>"error","message"=>[]);
    if($location_id <= 0){ //userid must gt 0
      $outcome = Array("status"=>"error","message"=>["Invalid appointment location id"]);
    }else{
      try{
        // Does the given id exist, and if so what is the usergroup id?
        $stmt = $db->prepare('SELECT usergroup_id FROM appt_locations WHERE id=?;');
        $stmt->execute([$location_id]);
        $res = $stmt2->fetchAll(PDO::FETCH_CLASS);
        if(count($res)<= 0){
          array_push($outcome["message"],"Location ". $template_id ." not found");
          return $outcome;
        }
      }catch(Exception $e){
        array_push($outcome["message"],$e->getMessage());
        return $outcome;
      }
      // Does the user have the right to do this?
      if(!test_user_in_group($authed_user,$res[0]->usergroup_id)){
        array_push($outcome["message"],"Insufficient permissions.");
      }else{
        try{
          $query = $db->prepare('DELETE FROM appt_locations WHERE id = ?;');
          $query->execute([intval($location_id)]);
          $outcome = Array("status"=>"success","message"=> ["Location " . $location_id . " deleted successfully."]);
        }catch(Exception $e){
          $outcome = Array("status"=>"error","message"=>[$e->getMessage()]);
        }
      }
    }
    return $outcome;
  }
?>
