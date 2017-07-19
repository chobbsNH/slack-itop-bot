<?php

# Grab some of the values from the slash command, create vars for post back to Slack
$command = $_POST['command'];
$text = $_POST['text'];
$token = $_POST['token'];
$user = $_POST['user_name'];
$itopdomain="itop.yourdomain.com";
$slacktoken='replaceme'; #replace this with the token from your slash command configuration page

if ($token != $slacktoken) { 
  $msg = "The token for the slash command doesn't match. Check your script.";
  die($msg);
}

$itopuser='slackticket';
$itoppassword='slackitoppassword';
$itopurl="https://$itopdomain/webservices/rest.php?version=1.3";

function getItopObject ($operation, $comment, $key, $class, $output_fields) {
	global $itopuser, $itoppassword, $itopurl;
	$post_data = array('operation' => "$operation",
                'comment' => "$comment",
                'key' => "$key",
                'class' => "$class",
                'output_fields' => "$output_fields",
        );
        $json_data = json_encode($post_data);

        $data = array('auth_user'=>$itopuser, 'auth_pwd'=>$itoppassword, 'json_data'=>$json_data);

        $options = array(
            'http' => array(
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data)
            )
        );

        $context  = stream_context_create($options);
        $result = file_get_contents($itopurl, false, $context);
        $obj = json_decode($result,true);
        $t=$obj["objects"];
        if ($obj["message"] === 'Found: 0') {
                die ("$class with ID $key not found, sorry!");
        }
	return $t;
}

function stimulateItop ($comment, $class, $key, $stimulus, $output_fields, &$fields) {
	global $itopuser, $itoppassword, $itopurl;
	$operation = 'core/apply_stimulus';
	
	$post_data = array('operation' => 'core/apply_stimulus',
                'comment' => $comment,
		'class' => $class,
		'key' => $key,
		'stimulus' => $stimulus,
                //'output_fields' => '*',
                'output_fields' => $output_fields,
		'fields' => $fields,
	);
	$json_data = json_encode($post_data);
	
	$data = array('auth_user'=>$itopuser, 'auth_pwd'=>$itoppassword, 'json_data'=>$json_data);
	
	$options = array(
	    'http' => array(
	        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
	        'method'  => 'POST',
	        'content' => http_build_query($data)
	    )
	);
	
	$context  = stream_context_create($options);
	$result = file_get_contents($itopurl, false, $context);
        $obj = json_decode($result,true);
        $t=$obj["objects"];
        if ($obj["message"] === 'Found: 0') {
                die ("Could not update object, sorry!");
        }
	return $t;
	
}

$t=getItopObject('core/get','Request  slack user info',"SELECT User JOIN Person ON User.contactid=Person.id WHERE Person.slackid='$user'",'User','*');
$userreq=current($t);
$admin=$userreq["fields"]["login"];
$agentid=$userreq["key"];
$agentname=$userreq["fields"]["first_name"]." ".$userreq["fields"]["last_name"];
$teamid="182";


if ($command === '/itop') {
	if (preg_match('/^new /', $text)) {
		$command='/newticket';
		$text=preg_replace('/^new /', '', $text);
		goto newticket;
	}

	if (preg_match('/update (\d*)/',$text,$matches)) {
		$ticket=$matches[1];
                if (!(preg_match('/update \d* (.*)/', $text, $matches))) {
                        die("That's great that you want to update this ticket, but you gotta tell me what to put in the update!\n\t/itop update $ticket Details of your update.");
                }
                $update=$matches[1];
		
		$post_data = array('operation' => 'core/update',
	                'comment' => 'Add entry to public log from Slack',
			'class' => 'UserRequest',
			'key' => $ticket,
	                'output_fields' => 'friendlyname, title, status, caller_id_friendlyname',
			'fields' => array(
				'public_log' => array(
					'add_item' => array(
						'date' => date("Y-m-d H:i:s"),
						'user_login' => $admin,
						'message' => "From $admin via slack:\n\n$update",
					),
				),
			)
		);
		$json_data = json_encode($post_data);
		
		$data = array('auth_user'=>$itopuser, 'auth_pwd'=>$itoppassword, 'json_data'=>$json_data);
		
		$options = array(
		    'http' => array(
		        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
		        'method'  => 'POST',
		        'content' => http_build_query($data)
		    )
		);
		
		$context  = stream_context_create($options);
		$result = file_get_contents($itopurl, false, $context);
	        $obj = json_decode($result,true);
	        $t=$obj["objects"];
	        if ($obj["message"] === 'Found: 0') {
	                die ("Ticket $ticket not found, sorry!");
	        }
	        $userreq=current($t);
		echo "<https://$itopdomain/pages/UI.php?operation=details&class=UserRequest&id=$ticket&c[org_id]=1&c[menu]=UserAccountsMenu#tabbedContent_0=0|".$userreq["fields"]["friendlyname"]."> has been updated.";
		die();
	}

	if (preg_match('/resolve (\d*)/',$text,$matches)) {
		// should update this to first make sure the ticket isn't already assigned. If it is use ev_reassign stimulus.
		$ticket=$matches[1];
		if (!(preg_match('/resolve \d* (.*)/', $text, $matches))) {
			die("That's great that you want to resolve this, but you gotta tell me how you did it!\n\t/itop resolve $ticket Details of what you did to resolve it.");
		}
		$resolution=$matches[1];

		//get service if from existing ticket, assign a default if it is missing
	        $userreq=current(getItopObject('core/get','Request ticket info from slack',$ticket,'UserRequest','service_id'));
	        $serviceid=$userreq["fields"]["service_id"];
	
		if ($serviceid <= 0) {
			$serviceid = 4;
		}

		$fields=array('solution' => $resolution,'service_id' => $serviceid);
		$userreq=current(stimulateItop ("$agentname via Slack", 'UserRequest', $ticket, 'ev_resolve', 'friendlyname, title, status, caller_id_friendlyname', $fields));
		$friendlyname=$userreq["fields"]["friendlyname"];
		$caller=$userreq["fields"]["caller_id_friendlyname"];
		die("Ticket <https://$itopdomain/pages/UI.php?operation=details&class=UserRequest&id=$ticket&c[org_id]=1&c[menu]=UserAccountsMenu|$friendlyname> is marked resolved. Way to go! I bet $caller is thrilled with you right now!");
	}

	if (preg_match('/(take|give) (\d*)/',$text,$matches)) {
		// should update this to first make sure the ticket isn't already assigned. If it is use ev_reassign stimulus.
		$comm = $matches[1];
		$ticket=$matches[2];
		$touser='';
		if (preg_match('/give \d* (admin[a-zA-Z]*)/',$text,$matches)) { # this ensures that users matching our tech's username scheme are being used; our techs' slack usernames match their itop username
			$touser = $matches[1];
			$t=getItopObject('core/get','Request user info from slack',"SELECT User WHERE login='$touser'",'User','friendlyname');
			$userreq=current($t);
			$agentid=$userreq["key"];
		}
                $userreq=current(getItopObject('core/get','Request ticket info from slack',$ticket,'UserRequest','*'));
                $ticketid=$userreq["key"];
                $status=$userreq["fields"]["status"];

		if ($status === 'assigned' || $status === 'escalated_ttr') {
			$stimulus='ev_reassign';
		} elseif ($status === 'resolved') {
			$stimulus='ev_reopen';
		} else {
			$stimulus='ev_assign';
		}

		$fields=array('team_id' => $teamid, 'agent_id' => $agentid);
		$userreq=current(stimulateItop ("$agentname via Slack", 'UserRequest', $ticket, $stimulus, 'friendlyname, title, status, caller_id_friendlyname', $fields));
		$friendlyname=$userreq["fields"]["friendlyname"];
		$caller=$userreq["fields"]["caller_id_friendlyname"];
		if (($touser === '')) {
			die("OK, you own ticket <https://$itopdomain/pages/UI.php?operation=details&class=UserRequest&id=$ticket&c[org_id]=1&c[menu]=UserAccountsMenu|$friendlyname>. Go make $caller a happy camper!");
		} else {
			die("Ticket <https://$itopdomain/pages/UI.php?operation=details&class=UserRequest&id=$ticket&c[org_id]=1&c[menu]=UserAccountsMenu|$friendlyname> has been re-assigned to $touser.");
		}
	}

	if (preg_match('/^listnew/', $text)) {
		if (preg_match('/^listnew$/', $text)) {
			$oql="SELECT UserRequest WHERE status LIKE 'New'";
		} else {
			preg_match('/^listnew (.*)/', $text, $matches);
			$site=$matches[1];
			$oql="SELECT UserRequest WHERE status LIKE 'New' AND c_location_name LIKE '%$site%'";
		}
			
                $tickets=getItopObject('core/get','Request ticket info from slack',$oql,'UserRequest','ref,title,c_location_name');
		foreach ($tickets as $t) {
			$key=$t["key"];
			$ref=$t["fields"]["ref"];
			$title=$t["fields"]["title"];
			$site=$t["fields"]["c_location_name"];
			echo "<https://$itopdomain/pages/UI.php?operation=details&class=UserRequest&id=$key&c[org_id]=1|$ref>: $site - *$title* \n";
		}
		die();
	}
	if (preg_match('/^list/', $text)) {
		if (preg_match('/^list$/', $text)) {
			$oql="SELECT UserRequest JOIN Person ON UserRequest.agent_id=Person.id JOIN User ON User.contactid=Person.id WHERE User.login LIKE '$admin' AND UserRequest.status NOT IN (\"closed\", \"resolved\")";
		} else {
			preg_match('/^list (.*)/', $text, $matches);
			$site=$matches[1];
			$oql="SELECT UserRequest JOIN Person ON UserRequest.agent_id=Person.id JOIN User ON User.contactid=Person.id WHERE User.login LIKE '$admin' AND UserRequest.status NOT IN (\"closed\", \"resolved\") AND UserRequest.c_location_name LIKE '%$site%'";
		}
			
                $tickets=getItopObject('core/get','Request ticket info from slack',$oql,'UserRequest','ref,title,c_location_name');
		foreach ($tickets as $t) {
			$key=$t["key"];
			$ref=$t["fields"]["ref"];
			$title=$t["fields"]["title"];
			$site=$t["fields"]["c_location_name"];
			echo "<https://$itopdomain/pages/UI.php?operation=details&class=UserRequest&id=$key&c[org_id]=1|$ref>: $site - *$title* \n";
			//echo var_dump($t);
		}
		die();
	}


	if (preg_match('/info (\d*)/', $text, $matches)) {
		$ticket=$matches[1];
		if (preg_match('/info \d* (loud)/', $text, $matches)) {
			$loud = 'in_channel';
		} else {
			$loud = 'ephemeral';
		}
		if (preg_match('/info \d* (full)/', $text, $matches)) {
			$full=$matches[1];
		} else {
			$full='';
		}
		$userreq=current(getItopObject("core/get","Request ticket info from slack",$ticket,"UserRequest","*"));
		$ticketid=$userreq["key"];
		$friendlyname=$userreq["fields"]["friendlyname"];
		$caller=$userreq["fields"]["caller_id_friendlyname"];
		$agent=$userreq["fields"]["agent_id_friendlyname"];
		$title=$userreq["fields"]["title"];
		$description=$userreq["fields"]["description"];
		$status=$userreq["fields"]["status"];
		$submitted=$userreq["fields"]["start_date"];
	
		$fields = array();
		array_push($fields, array('title'=>'Caller', 'value'=>$caller, 'short'=>'true',));
		array_push($fields, array('title'=>'Agent', 'value'=>$agent, 'short'=>'true',));
		array_push($fields, array('title'=>'Status', 'value'=>$status, 'short'=>'true',));
		array_push($fields, array('title'=>'Submitted', 'value'=>$submitted, 'short'=>'true',));

		if ($full === 'full') {
			// Add additional fields here...
	
			array_push($fields, array('title'=>'Last Updated', 'value'=>$userreq["fields"]["last_update"], 'short'=>'true',));
			array_push($fields, array('title'=>'Assignment Date', 'value'=>$userreq["fields"]["assignment_date"], 'short'=>'true',));
			array_push($fields, array('title'=>'Resolution Date', 'value'=>$userreq["fields"]["resolution_date"], 'short'=>'true',));
			array_push($fields, array('title'=>'Service', 'value'=>$userreq["fields"]["service_name"], 'short'=>'true',));
			array_push($fields, array('title'=>'Service SubCat', 'value'=>$userreq["fields"]["servicesubcategory_name"], 'short'=>'true',));
			array_push($fields, array('title'=>'Priority', 'value'=>$userreq["fields"]["priority"], 'short'=>'true',));
			array_push($fields, array('title'=>'Origin', 'value'=>$userreq["fields"]["origin"], 'short'=>'true',));
		}
	
		array_push($fields, array( 'title'=>'Description', 'value'=>$description, ));
	
		if ($full === 'full') {
			array_push($fields, array('title'=>'Solution', 'value'=>$userreq["fields"]["solution"], ));
			$log=$userreq["fields"]["public_log"]["entries"];
			foreach ($log as $l) {
				array_push($fields, array( 'title'=>$l["date"].": ".$l["user_login"], 'value'=>$l["message"]));
			}
		}
	
		$response_data = array(
			'response_type' => $loud,
			'text' => "Here's the info on ticket $friendlyname!",
			'attachments' => array(
				array(
				'title' => "<https://$itopdomain/pages/UI.php?operation=details&class=UserRequest&id=$ticket&c[org_id]=1|$friendlyname>: $title",
				'fallback' => 'nothing',
				'fields' => $fields
			))
		);
		header('Content-type: application/json');
		die (json_encode($response_data));
	}

	
	$response="Use /itop to look up a ticket:\n\t/itop *info* 3456\nBroadcast a ticket to the channel you are in:\n\t/itop *info* 3456 loud\nCreate a new ticket with:\n\t/itop *new* I need this stuff done.\nCreate a ticket with a title:\n\t/itop *new* title:\"This is the title\" This is the detail of the ticket\nList all of your open tickets:\n\t/itop *list*\nLIst all of your open tickets at a particular site:\n\t/itop *list* Emanu\n\n<https://docs.google.com/a/nhusd.k12.ca.us/document/d/15e3P28X9nFovIOZBaRWTWIYGucNjxpnkzTnPCtbhmi4/edit?usp=sharing|More Info Here>";
	die ($response);

}
newticket:
if ($command === '/newticket') {
	if ($text === 'help' || $text==='') {
		$reply="Simply type:\n\t\t/newticket My new ticket info goes here\nYou can also specify a title with the following syntax\n\t\t/newticket title:\"My Title Goes Here\" the body of the ticket goes here";
		die ($reply);
	}
	$titlepattern='/^title:"(.*)"/';
	if (preg_match($titlepattern,$text,$matches)) {
		$title=$matches[1];
	}
	
	$body=preg_replace($titlepattern, '', $text);
	
	if (!(isset($title))) {
		$title=$body;
	}
	
	$post_data = array('operation' => 'core/create',
		'comment' => "$agentname via slack",
		'class' => 'UserRequest',
		'output_fields' => '*',
		'fields' => array(
			'org_id' => '1',
			'origin' => 'slack',
			'caller_id' => array(
				'name' => 'Ticket',
				'first_name' => 'Slack'
			),
			'title' => $title,
			'description' => $body,
		)
	);
	
	$json_data = json_encode($post_data);
	
	$data = array('auth_user'=>$itopuser, 'auth_pwd'=>$itoppassword, 'json_data'=>$json_data);
	
	$options = array(
	    'http' => array(
	        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
	        'method'  => 'POST',
	        'content' => http_build_query($data)
	    )
	);
	
	
	$context  = stream_context_create($options);
	$result = file_get_contents($itopurl, false, $context);
	
	$obj = json_decode($result,true);
	$ticket=$obj["objects"];
	$userreq=current($ticket);
	$ticketid=$userreq["key"];
	$friendlyname=$userreq["fields"]["friendlyname"];
	$caller=$userreq["fields"]["caller_id_friendlyname"];
	$agent=$userreq["fields"]["agent_id_friendlyname"];
	$title=$userreq["fields"]["title"];
	$description=$userreq["fields"]["description"];
	$status=$userreq["fields"]["status"];
	$submitted=$userreq["fields"]["start_date"];

	$fields=array('team_id' => $teamid, 'agent_id' => $agentid);
	$userreq2=current(stimulateItop ("$agentname via Slack", 'UserRequest', $ticketid, 'ev_assign', 'friendlyname, title, status, caller_id_friendlyname', $fields));

	$response_data = array(
		'text' => "Here's the info on new ticket <https://$itopdomain/pages/UI.php?operation=details&class=UserRequest&id=$ticketid&c[org_id]=1&c[menu]=UserAccountsMenu|$friendlyname> you just created! Click through to edit fields like requestor, etc. that are not set via Slack.",
		'attachments' => array(
			array(
			'title' => "$friendlyname: $title",
			'fallback' => 'nothing',
			'fields' => array(
				array(
					'title'=>'Caller',
					'value'=>$caller,
					'short'=>'true',
				),
				array(
					'title'=>'Agent',
					'value'=>'You, Rockstar!',
					'short'=>'true',
				),
				array(
					'title'=>'Status',
					'value'=>$status,
					'short'=>'true',
				),
				array(
					'title'=>'Submitted',
					'value'=>$submitted,
					'short'=>'true',
				),
				array(
					'title'=>'Description',
					'value'=>$description,
				),
			)
		))
	);
	header('Content-type: application/json');
	die (json_encode($response_data));
} else {
	die ("command was xx${command}xx");
}
die($reply);

?>
