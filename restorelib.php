<?php
    //Based on php script for restoring forum mods
	//This php script contains all the stuff to backup/restore
    //checklist mods
    //
    //This is the "graphical" structure of the checklist mod:
    //
    //                            checklist
    //                            (CL,pk->id)
    //                                 |
    //                                 |       
    //                                 |
    //                          checklist_item
    //                        (UL,pk->id, fk->checklist)
    //                                 |
    //                                 |
    //                                 |
    //                          checklist_check
    //                     (UL,pk->id,fk->item) 
    //
    // Meaning: pk->primary key field of the table
    //          fk->foreign key to link with parent
    //          CL->course level info
    //          UL->user level info
    //
    //-----------------------------------------------------------

    function checklist_restore_mods($mod,$restore) {
        
        global $CFG,$db;

        $status = true;

        //Get record from backup_ids
        $data = backup_getid($restore->backup_unique_code,$mod->modtype,$mod->id);

        if ($data) {
            //Now get completed xmlized object
            $info = $data->info;
            //if necessary, write to restorelog and adjust date/time fields
            if ($restore->course_startdateoffset) {
                restore_log_date_changes('checklist', $restore, $info['MOD']['#'], array('TIMECREATED', 'TIMEMODIFIED'));
            }
            //traverse_xmlize($info);                                                                     //Debug
            //print_object ($GLOBALS['traverse_array']);                                                  //Debug
            //$GLOBALS['traverse_array']="";                                                              //Debug

            //Now, build the CHECKLIST record structure
            $checklist = new stdClass;
            $checklist->course = $restore->course_id;
            $checklist->name = backup_todb($info['MOD']['#']['NAME']['0']['#']);
            $checklist->intro = backup_todb($info['MOD']['#']['INTRO']['0']['#']);
            $checklist->introformat = backup_todb($info['MOD']['#']['INTROFORMAT']['0']['#']);
            $checklist->timecreated = backup_todb($info['MOD']['#']['TIMECREATED']['0']['#']);
            $checklist->timecreated += $restore->course_startdateoffset;
            $checklist->timemodified = backup_todb($info['MOD']['#']['TIMEMODIFIED']['0']['#']);
            $checklist->timemodified += $restore->course_startdateoffset;
            $checklist->useritemsallowed = backup_todb($info['MOD']['#']['USERITEMSALLOWED']['0']['#']);
            $checklist->teacheredit = backup_todb($info['MOD']['#']['TEACHEREDIT']['0']['#']);
            $checklist->theme = backup_todb($info['MOD']['#']['THEME']['0']['#']);

            $newid = insert_record('checklist', $checklist);


            //Do some output
            if (!defined('RESTORE_SILENTLY')) {
                echo "<li>".get_string('modulename','checklist')." \"".format_string(stripslashes($checklist->name),true)."\"</li>";
            }
            backup_flush(300);

            if ($newid) {
                //We have the newid, update backup_ids
                backup_putid($restore->backup_unique_code,$mod->modtype,
                             $mod->id, $newid);

                $checklist->id = $newid;
                
                $restore_user = restore_userdata_selected($restore,'checklist',$mod->id);
                $status = checklist_items_restore($newid, $info, $restore, $restore_user);

            } else {
                $status = false;
            }
            
        } else {
            $status = false;
        }

        return $status;
    }
    
    function checklist_items_restore($checklist,$info,$restore,$restore_user) {
    
        global $CFG;

        $status = true;

        //Get the items array
        $items = array();
        
        if (!empty($info['MOD']['#']['ITEMS']['0']['#']['ITEM'])) {
            $items = $info['MOD']['#']['ITEMS']['0']['#']['ITEM'];
        }
        
        //Iterate over discussions
        for($i = 0; $i < sizeof($items); $i++) {
            $i_info = $items[$i];
            //traverse_xmlize($i_info);                                                                 //Debug
            //print_object ($GLOBALS['traverse_array']);                                                  //Debug
            //$GLOBALS['traverse_array']="";                                                              //Debug

            //We'll need this later!!
            $oldid = backup_todb($i_info['#']['ID']['0']['#']);

            //Now, build the checklist_item record structure
            $item = new stdClass;
            $item->checklist = $checklist;
            $item->userid = backup_todb($i_info['#']['USERID']['0']['#']);
            $item->displaytext = backup_todb($i_info['#']['DISPLAYTEXT']['0']['#']);
            $item->position = backup_todb($i_info['#']['POSITION']['0']['#']);
            $item->indent = backup_todb($i_info['#']['INDENT']['0']['#']);
            $item->itemoptional = backup_todb($i_info['#']['ITEMOPTIONAL']['0']['#']);
            
            if ($item->userid > 0) {
                // Ignore user-created items if not restoring userdata
                if (!$restore_user) {
                    continue;
                }

                $item->userid = backup_getid($restore->backup_unique_code,'user',$item->userid);
                if (!$item->userid) {
                    $status = false;
                    break;
                }
                $item->userid = $item->userid->new_id;
            }

            //The structure is equal to the db, so insert the checklist_item
            $newid = insert_record ('checklist_item',$item);

            //Do some output
            if (($i+1) % 50 == 0) {
                if (!defined('RESTORE_SILENTLY')) {
                    echo ".";
                    if (($i+1) % 1000 == 0) {
                        echo "<br />";
                    }
                }
                backup_flush(300);
            }

            if ($newid) {
                //We have the newid, update backup_ids
                backup_putid($restore->backup_unique_code,'checklist_item',$oldid,
                             $newid);
                //Restore checks
                if ($restore_user) {
                    $status = checklist_checks_restore ($newid,$i_info,$restore);
                }
                
            } else {
                $status = false;
                break;
            }
        }

        return $status;
    }

    function checklist_checks_restore($item,$info,$restore) {

        global $CFG;

        $status = true;

        //Get the checks
        $checks = $info['#']['CHECKS']['0']['#']['CHECK'];

        //Iterate over checks
        for($i = 0; $i < sizeof($checks); $i++) {
            $c_info = $checks[$i];
            //traverse_xmlize($c_info);                                                                 //Debug
            //print_object ($GLOBALS['traverse_array']);                                                  //Debug
            //$GLOBALS['traverse_array']="";                                                              //Debug

            //We'll need this later!!
            $oldid = backup_todb($c_info['#']['ID']['0']['#']);

            //Now, build the CHECKLIST_CHECK record structure
            $check = new stdClass;
            $check->item = $item;
            $check->userid = backup_todb($c_info['#']['USERID']['0']['#']);
            $check->usertimestamp = backup_todb($c_info['#']['USERTIMESTAMP']['0']['#']);
            $check->usertimestamp += $restore->course_startdateoffset;
            $check->teachermark = backup_todb($c_info['#']['TEACHERMARK']['0']['#']);
            $check->teachertimestamp = backup_todb($c_info['#']['TEACHERTIMESTAMP']['0']['#']);
            $check->teachertimestamp += $restore->course_startdateoffset;

            $check->userid = backup_getid($restore->backup_unique_code,'user',$check->userid);
            if (!$check->userid) {
                $status = false;
                break;
            }
            $check->userid = $check->userid->new_id;

            //The structure is equal to the db, so insert the checklist_check
            $newid = insert_record ('checklist_check',$check);


            //Do some output
            if (($i+1) % 50 == 0) {
                if (!defined('RESTORE_SILENTLY')) {
                    echo ".";
                    if (($i+1) % 1000 == 0) {
                        echo "<br />";
                    }
                }
                backup_flush(300);
            }

            if ($newid) {
                //We have the newid, update backup_ids
                backup_putid($restore->backup_unique_code,'checklist_check',$oldid,
                             $newid);

            } else {
                $status = false;
                break;
            }
        }

        return $status;
    }

?>