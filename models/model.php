<?php

/*
 checks for votes on the poll
 @param ElggEntity $poll
 @param guid
 @return true/false
 */
function polls_check_for_previous_vote($poll, $user_guid)
{
	$options = array(
		'guid'	=>	$poll->guid,
		'type'	=>	"object",
		'subtype' => "poll",
		'annotation_name' => "vote",
		'annotation_owner_guid' => $user_guid,
		'limit' => 1
	);
	$votes = elgg_get_annotations($options);
	if ($votes) {
		return true;
	} else {
		return false;
	}
}

function polls_get_choices($poll) {
	$options = array(
		'relationship' => 'poll_choice',
		'relationship_guid' => $poll->guid,
		'inverse_relationship' => TRUE,
		'order_by_metadata' => array('name'=>'display_order','direction'=>'ASC')
	);
	return elgg_get_entities_from_relationship($options);
}

function polls_get_choice_array($poll) {
	$choices = polls_get_choices($poll);
	$responses = array();
	if ($choices) {
		foreach($choices as $choice) {
			$responses[$choice->text] = $choice->text;
		}
	}
	return $responses;
}

function polls_add_choices($poll,$choices) {
	$i = 0;
	if ($choices) {
		foreach($choices as $choice) {
			$poll_choice = new ElggObject();
			$poll_choice->subtype = "poll_choice";
			$poll_choice->text = $choice;
			$poll_choice->display_order = $i*10;
			$poll_choice->access_id = $poll->access_id;
			$poll_choice->save();
			add_entity_relationship($poll_choice->guid, 'poll_choice', $poll->guid);
			$i += 1;
		}
	}
}

function polls_delete_choices($poll) {
	$choices = polls_get_choices($poll);
	if ($choices) {
		foreach($choices as $choice) {
			$choice->delete();
		}
	}
}

function polls_replace_choices($poll,$new_choices) {
	polls_delete_choices($poll);
	polls_add_choices($poll, $new_choices);
}

function polls_activated_for_group($group) {
	$group_polls = elgg_get_plugin_setting('group_polls', 'polls');
	if ($group && ($group_polls != 'no')) {
		if ( ($group->polls_enable == 'yes')
		|| ((!$group->polls_enable && ((!$group_polls) || ($group_polls == 'yes_default'))))) {
			return true;
		}
	}
	return false;
}

function polls_can_add_to_group($group,$user=null) {
	$polls_group_access = elgg_get_plugin_setting('group_access', 'polls');
	if (!$polls_group_access || $polls_group_access == 'admins') {
		return $group->canEdit();
	} else {
		if (!$user) {
			$user = elgg_get_logged_in_user_guid();
		}
		return $group->canEdit() || $group->isMember($user);
	}
}

function polls_get_page_edit($page_type,$guid = 0) {
	gatekeeper();

	// Get the current page's owner
	$page_owner = elgg_get_page_owner_entity();
	if ($page_owner === false || is_null($page_owner)) {
		$page_owner = elgg_get_logged_in_user_entity();
		elgg_set_page_owner_guid($page_owner->guid);
	}
	
	$form_vars = array('id'=>'polls-edit-form');

	// Get the post, if it exists
	if ($page_type == 'edit') {
		$poll = get_entity($guid);
		if (elgg_instanceof($poll,'object','poll')) {
			$container_guid = $poll->container_guid;
			elgg_set_page_owner_guid($container_guid);
			$title = sprintf(elgg_echo('polls:editpost'),$poll->title);
			
			$body_vars = array(
				'fd' => polls_prepare_edit_body_vars($poll),
				'entity' => $poll
			);
	
			if ($poll->canEdit()) {
				$content .= elgg_view_form("polls/edit", $form_vars, $body_vars);
			} else {
				$content = elgg_echo('polls:permission_error');
			}
			$container = get_entity($container_guid);
			if (elgg_instanceof($container,'group')) {
				elgg_push_breadcrumb(elgg_echo('item:object:poll'),'polls/group/'.$container->guid);
			} else {
				elgg_push_breadcrumb(elgg_echo('item:object:poll'),'polls/all');
			}
			elgg_push_breadcrumb(elgg_echo('polls:edit'));
		} else {
			$title = elgg_echo('polls:error_title');
			$content = elgg_echo('polls:no_such_poll');
		}
	} else {
		if ($guid) {
			elgg_set_page_owner_guid($guid);
			$container = get_entity($guid);
			elgg_push_breadcrumb(elgg_echo('polls:group_polls'),'polls/group/'.$container->guid);
		} else {
			elgg_push_breadcrumb(elgg_echo('item:object:poll'),'polls/all');
		}
		$title = elgg_echo('polls:addpost');
		$body_vars = array('fd'=>polls_prepare_edit_body_vars(),'container_guid'=>$guid);
		$content = elgg_view_form("polls/edit",$form_vars,$body_vars);
		elgg_push_breadcrumb(elgg_echo('polls:add'));
	}
	
	$params = array(
		'title' => $title,
		'content' => $content,
		'filter' => '',				
	);

	$body = elgg_view_layout('content', $params);

	// Display page
	return elgg_view_page($title,$body);
}

/**
 * Pull together variables for the edit form
 *
 * @param ElggObject       $poll
 * @return array
 * 
 * TODO - put choices in sticky form as well
 */
function polls_prepare_edit_body_vars($poll = NULL) {

	// input names => defaults
	$values = array(
		'question' => NULL,
		'tags' => NULL,
		'access_id' => ACCESS_DEFAULT,
		'guid' => NULL,
	);

	if ($poll) {
		foreach (array_keys($values) as $field) {
			if (isset($poll->$field)) {
				$values[$field] = $poll->$field;
			}
		}
	}

	if (elgg_is_sticky_form('polls')) {
		$sticky_values = elgg_get_sticky_values('polls');
		foreach ($sticky_values as $key => $value) {
			$values[$key] = $value;
		}
	}

	elgg_clear_sticky_form('polls');

	return $values;
}

function polls_get_page_list($page_type, $container_guid = NULL) {
	global $autofeed;
	$autofeed = TRUE;
	$user = elgg_get_logged_in_user_entity();
	$params = array();
	$options = array(
		'type'=>'object', 
		'subtype'=>'poll', 
		'full_view' => FALSE, 
		'limit'=>15,
	);
	if ($page_type == 'group') {
		$group = get_entity($container_guid);
		if (!elgg_instanceof($group,'group') || !polls_activated_for_group($group)) {
			forward();
		}
		$crumbs_title = $group->name;
		$params['title'] = elgg_echo('polls:group_polls:listing:title', array(htmlspecialchars($crumbs_title)));
		elgg_push_breadcrumb($crumbs_title,$group->getURL());
		elgg_push_breadcrumb(elgg_echo('item:object:poll'));
		elgg_push_context('groups');
		elgg_set_page_owner_guid($container_guid);
		group_gatekeeper();
		$options['container_guid'] = $container_guid;
		$user_guid = elgg_get_logged_in_user_guid();
		if (elgg_get_page_owner_entity()->canWriteToContainer($user_guid)){
			elgg_register_menu_item('title', array(
				'name' => 'add',
				'href' => "polls/add/".$container_guid,
				'text' => elgg_echo('polls:add'),
				'class' => 'elgg-button elgg-button-action',
			));
		}
		
	} else {
		switch ($page_type) {
			case 'owner':
				$params['filter_context'] = 'mine';
				$options['owner_guid'] = $container_guid;
				if ($user->guid = $container_guid) {
					$params['title'] = elgg_echo('polls:your');
				} else {
					$params['title'] = elgg_echo('polls:not_me',array(htmlspecialchars($user->name)));
				}
				elgg_push_breadcrumb(elgg_echo('item:object:poll'));
				break;
			case 'friends':
				$friends = get_user_friends($container_guid, ELGG_ENTITIES_ANY_VALUE, 0);
				$options['container_guids'] = array();
				foreach ($friends as $friend) {
					$options['container_guids'][] = $friend->getGUID();
				}
				$params['filter_context'] = 'friends';
				$params['title'] = elgg_echo('polls:friends');
				$crumbs_title = $user->name;
				elgg_push_breadcrumb($crumbs_title, "polls/owner/{$user->username}");
				elgg_push_breadcrumb(elgg_echo('friends'));
				break;
			case 'all':
				$params['filter_context'] = 'all';
				$params['title'] = elgg_echo('item:object:poll');
				elgg_push_breadcrumb(elgg_echo('item:object:poll'));
				break;
		}
		
		if (elgg_is_logged_in()) {		
			elgg_register_menu_item('title', array(
				'name' => 'add',
				'href' => "polls/add",
				'text' => elgg_echo('polls:add'),
				'class' => 'elgg-button elgg-button-action',
			));
		}
	}
	
	if (($page_type == 'friends') && (count($options['container_guids']) == 0)) {
		// this person has no friends
		$params['content'] = '';
	} else {
		$params['content'] = elgg_list_entities($options);
	}
	if (!$params['content']) {
		$params['content'] = elgg_echo('polls:none');
	}

	$body = elgg_view_layout("content", $params);

	return elgg_view_page($params['title'],$body);
}

function polls_get_page_view($guid) {
	elgg_load_js('elgg.polls');
	$poll = get_entity($guid);
	if (elgg_instanceof($poll,'object','poll')) {		
		// Set the page owner
		$page_owner = $poll->getContainerEntity();
		elgg_set_page_owner_guid($page_owner->guid);
		$title =  $poll->title;
		$content = elgg_view_entity($poll, array('full_view' => TRUE));
		//check to see if comments are on
		if ($poll->comments_on != 'Off') {
			$content .= elgg_view_comments($poll);
		}
		if (elgg_instanceof($page_owner,'user')) {
			elgg_push_breadcrumb(elgg_echo('item:object:poll'), "polls/all");
		} else {
			elgg_push_breadcrumb($page_owner->name, "polls/group/{$page_owner->guid}");
		}
		elgg_push_breadcrumb($poll->title);
	} else {			
		// Display the 'post not found' page instead
		$title = elgg_echo("polls:notfound");	
		$content = elgg_view("polls/notfound");	
		elgg_push_breadcrumb(elgg_echo('item:object:poll'), "polls/all");
		elgg_push_breadcrumb($title);
	}
	
	$params = array('title' =>$title,'content' => $content,'filter'=>'');
	$body = elgg_view_layout('content', $params);
		
	// Display page
	return elgg_view_page($title,$body);
}

function polls_get_response_count($valueToCount, $fromArray) {
	$count = 0;
	
	if(is_array($fromArray))
	{
		foreach($fromArray as $item)
		{
			if($item->value == $valueToCount)
			{
				$count += 1;
			}
		}	
	}
	
	return $count;
}