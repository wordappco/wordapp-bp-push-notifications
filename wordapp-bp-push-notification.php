<?php
/*
Plugin Name: WordApp BP Push Notification
Plugin URI: http://app-developers.biz
Description: WordApp Buddypress notification are display for mobile application by push notification method.
Version: 2.0.9
Author: Dave A Sargent
Author URI: http://app-developers.biz
*/


function bp_push_notification_api_init() {
	load_plugin_textdomain('aheadzen', false, basename( dirname( __FILE__ ) ) . '/languages');
}
add_action('init', 'bp_push_notification_api_init');

add_action( 'bp_init', 'bp_init_WordApp_push', 100);
function bp_init_WordApp_push()
{
	new BP_WordApp_Push_Notification();	
}

add_action('bp_activity_sent_mention_email',  array('BP_WordApp_Push_Notification', 'set_push_token_and_notification_mention' ), 10, 5 );
add_action('bp_activity_comment_posted',  array('BP_WordApp_Push_Notification', 'set_push_token_and_notification_activity_comments' ), 10, 3 );
add_action('bp_notification_after_save',array('BP_WordApp_Push_Notification','bp_notifications_push_notification_after_save'),10);
add_action('bp_activity_add',array('BP_WordApp_Push_Notification','bp_activity_add_push_notification'),10);
		
class BP_WordApp_Push_Notification {

    public function __construct() {
//		header("Access-Control-Allow-Origin: *");
		global $wpdb,$table_prefix;
			
		//add_action( 'bp_activity_add',  array( $this, 'set_push_token_and_notification' ), 10, 1 );
		//add_action('bp_activity_sent_mention_email',  array( $this, 'set_push_token_and_notification_mention' ), 10, 5 );
		//add_action('bp_activity_comment_posted',  array( $this, 'set_push_token_and_notification_activity_comments' ), 10, 3 );
		//add_action('bp_notification_after_save',array( $this,'bp_notifications_push_notification_after_save'),10);
		//add_action('bp_activity_add',array( $this,'bp_activity_add_push_notification'));
		//add_action('admin_menu',array( $this,'push_notificaiton_menu_page'));
		$allowed_actions = array('register_device','get_device_token','send_user_notification');
		if(in_array($_REQUEST['action'], $allowed_actions) && $_REQUEST['plugin']=='push' )
		{
			header("Access-Control-Allow-Origin: *");
			header('Content-Type: application/json; charset=UTF-8', true);
				$res = $this->bp_push_nf_send_notification();
				echo $res;
				exit;
		}
}
	/*Notification for activity comment & reply*/
	function set_push_token_and_notification_activity_comments($comment_id, $r, $activity)
	{		
  		global $wpdb,$bp;		
		$activity_id = $r['activity_id'];
		$user = new BP_Core_User($activity->user_id);
		$commentuser = new BP_Core_User($r['user_id']);
		$deviceToken = self::get_user_device_token($activity->user_id);
		$content = $commentuser->fullname . ' commented on your update.';
		$args = array();
		$gotourl = site_url('/').'members/'.$user->profile_data['user_login'].'/activity/'.$activity->id.'/';			
		$args['device_tokens'] = array($deviceToken);
		$args['content'] = wp_strip_all_tags($content);
		$args['gotourl'] = $gotourl;  //$activity->primary_link;
		$args['username'] = $user->profile_data['user_login'];
		//$args['username'] = 'test';
		self::sendPushNotification($args);		
	}

	/*Notification for mentions*/
	function set_push_token_and_notification_mention($activity, $subject, $message, $content, $receiver_user_id)
	{
		//if($receiver_user_id==$activity->user_id)return true;
		$deviceToken = self::get_user_device_token($receiver_user_id);
		if($deviceToken){
			$args = array();
			//<<FROM>> mentioned you on <<Parent owner>>'s update
			$user = new BP_Core_User($receiver_user_id);
			$activity_user = new BP_Core_User($activity->user_id);
			$subject = $user->fullname." mentioned you on ".$activity_user->fullname."'s update";
			$gotourl = site_url('/').'members/'.$user->profile_data['user_login'].'/activity/mentions/';
			$args['device_tokens'] = array($deviceToken);
			$args['content'] = $subject;
			$args['gotourl'] = $activity->primary_link;
			$args['username'] = $user->profile_data['user_login'];
			//$args['username'] = 'test';
			self::sendPushNotification($args);
		}
	}

	function bp_activity_add_push_notification($arg){
		$users = array();
		global $wpdb,$table_prefix;
		if($arg['component']=='groups'){
			/*Notification on group update*/
			$item_id = $arg['item_id'];
			$action = $arg['action'];
			$content = $arg['content'];
			$type = $arg['type'];
			$user_id = $arg['user_id'];
			$recorded_time = $arg['recorded_time'];
			//$users = $wpdb->get_col("select distinct(user_id) from ".$table_prefix."bp_groups_members where group_id=\"$item_id\"");
			$groupres = $wpdb->get_results("select creator_id,name,slug from ".$table_prefix."bp_groups where id=\"$item_id\"");
			$gotourl = bp_get_root_domain().'/groups/'.$groupres[0]->slug.'/';
			$user_id = $groupres[0]->creator_id;
			$device_token_arr = array();
			$device_token_arr[] = self::get_user_device_token($user_id);
			
			$args = array();
			$args['device_tokens'] = $device_token_arr;
			$args['content'] = wp_strip_all_tags($action);
			$user = new BP_Core_User($user_id);
			$args['gotourl'] = $gotourl;
			$args['username'] = $user->profile_data['user_login'];
			//$args['username'] = 'test';
			self::sendPushNotification($args);			
		}
	}

	function bp_notifications_push_notification_after_save($notification){
		
		$id = $notification->id;
		$item_id = $notification->item_id;
		$secondary_item_id = $notification->secondary_item_id;
		$user_id = $notification->user_id;
		$component_name = $notification->component_name;
		$component_action = $notification->component_action;
		$date_notified = $notification->date_notified;
		
		global $wpdb,$bp;
		if($component_name=='messages' && $component_action=='new_message'){
			$sql = "SELECT * FROM {$bp->messages->table_name_messages} WHERE id IN (". $wpdb->escape($item_id).")";
			$res = $wpdb->get_results($sql);
			$sender_id = $res[0]->sender_id;
			$message = $res[0]->message;
			$thread_id = $res[0]->thread_id;
			$sender_user = new BP_Core_User($sender_id);
			$sender_fullname = $sender_user->fullname;
			
			if($sender_id==$user_id)return true;
			
			//<<FROM>> said: <MESSAGE TEXT>
			if(strlen($message)>20){$message = substr($message,0,20).' ...';}
			$description = $sender_fullname.' said: '.$message;
			$device_token_arr = array();
			$device_token_arr[] = self::get_user_device_token($user_id);
			/*$notification = bp_notifications_get_notifications_for_user($user_id,'object');
			$notification_count = 0;		
			$description = $gotourl = '';
			if($notification && $notification[$notification_count]){
				$description = $notification[$notification_count]->content;
				$gotourl = $notification[$notification_count]->href;
			}*/			
			$args = array();
			$args['device_tokens'] = $device_token_arr;
			$args['content'] = $description;
			$user = new BP_Core_User($user_id);
			$gotourl = site_url('/').'members/'.$user->profile_data['user_nicename'].'/messages/view/'.$thread_id.'/';
			$args['gotourl'] = $gotourl;
			$args['username'] = $user->profile_data['user_login'];
			//$args['username'] = 'test';
			self::sendPushNotification($args);			
		}elseif($component_name=='follow' && $component_action=='new_follow'){
			//if($item_id == $user_id)return true;
			
			$device_token_arr = array();
			$device_token_arr[] = self::get_user_device_token($user_id);
			$follower_user = new BP_Core_User($item_id);
			$description = $follower_user->fullname.' started following you.';
			
			$args = array();
			$args['device_tokens'] = $device_token_arr;
			$args['content'] = $description;
			$user = new BP_Core_User($user_id);
			$gotourl = site_url('/').'members/'.$user->profile_data['user_nicename'].'/following/';
			$args['gotourl'] = $gotourl;
			$args['username'] = $user->profile_data['user_login'];
			//$args['username'] = 'test';
			self::sendPushNotification($args);	
		}elseif($component_name=='votes' && strstr($component_action,'vote')){
			$voterclass = new VoterBpNotifications();
			$device_token_arr = array();
			$device_token_arr[] = self::get_user_device_token($user_id);
			
			$description = $voterclass->aheadzen_voter_notification_title_format($component_action, $item_id, $secondary_item_id);
			$user = new BP_Core_User($user_id);
			$gotourl = site_url('/').'members/'.$user->profile_data['user_nicename'].'/following/';
			
			$component_action_arr = explode('-+',$component_action);
			$component_action_type = $component_action_arr[0];
			
			$user = new BP_Core_User($user_id);
			$gotourl = '';
			if($component_action_type=='activity'){
				if($secondary_item_id){$item_id = $secondary_item_id;}
				$activity = new BP_Activity_Activity($item_id);
				if($activity->component=='profile' && $activity->type=='new_avatar'){
					$description = $user->fullname.' likes your profile photo.';					
				}
				$gotourl = site_url('/').'members/'.$user->profile_data['user_nicename'].'/activity/'.$item_id.'/';
			}elseif($component_action_type=='profile'){
				$gotourl = site_url('/').'members/'.$user->profile_data['user_nicename'].'/';
			}else{
				preg_match_all('/(href)=("[^"]*")/i',$description,$description_result);
				if($description_result){
					if($description_result[count($description_result)-1]){
						$desc_href_arr = $description_result[count($description_result)-1];
					}elseif($description_result[count($description_result)-2]){
						$desc_href_arr = $description_result[count($description_result)-2];
					}
					$gotourl = str_replace('"','',$desc_href_arr[count($desc_href_arr)-1]);
				}
			}			
			
			$args = array();
			$args['device_tokens'] = $device_token_arr;
			$args['content'] = wp_strip_all_tags($description);
			$args['gotourl'] = $gotourl;
			$args['username'] = $user->profile_data['user_login'];
			//$args['username'] = 'test';
			self::sendPushNotification($args);	
		}elseif($component_name=='futures' && $component_action=='future_prediction'){
			$device_token_arr = array();
			$device_token_arr[] = self::get_user_device_token($user_id);
			global $wpdb, $table_prefix;
			$tbl_future_schedule = $table_prefix.'future_schedule';
			$data = $wpdb->get_var("select data from $tbl_future_schedule where fsid=\"$item_id\"");
			if($data){
				$dataArr = unserialize($data);
				$description = $dataArr['title'];				
			}
			
			$args = array();
			$args['device_tokens'] = $device_token_arr;
			$args['content'] = $description;
			$user = new BP_Core_User($user_id);
			$gotourl = site_url('/').'birth-chart/';
			$args['gotourl'] = $gotourl;
			$args['username'] = $user->profile_data['user_login'];
			//$args['username'] = 'test';
			
			self::sendPushNotification($args);
			//print_r($args);exit;
		}	
	}

	function bp_push_nf_send_notification(){
		$oReturn = new stdClass();
		$oReturn->success = '';
		$oReturn->error = '';
		if($_POST['action']=='register_device'){
			/*Register Device Token*/
			$securityValidation = 0;
			if($_POST['auth_token'] && get_userid_from_token($_POST['auth_token'])){
				$_POST['user_id'] = get_userid_from_token($_POST['auth_token']);
				$securityValidation = 1;
			}elseif($_POST['user_id'] && $this->check_valid_user($_POST['user_id'],$_POST['pw'])){
				$securityValidation = 1;								
			}
			
			if($securityValidation){
				$deviceToken = array(
					'device_token'	=>	$_POST['device_token'],
					'platform'	=>	$_POST['platform'] //$platform 0=> iOS or 1=> Android.
					);
				update_user_meta($_POST['user_id'],'wordapp_push_device_token',$deviceToken);
				$oReturn->success = __('Device Token Added Successfully.','aheadzen');		
			}else{
				$oReturn->error = __('Security Error.','aheadzen');
			}				
//			echo json_encode($oReturn);
//			header('Content-Type: application/json; charset=UTF-8', true);
//			exit;
		}else if($_GET['action']=='get_device_token'){
			/*Get Device Token*/
			if($_GET['user_id']){
				$device_token = self::get_user_device_token($_GET['user_id']);
				if($device_token){
					$oReturn->device_token = $device_token['device_token'];
					$oReturn->platform = $device_token['platform'];
				}else{
					$oReturn->error = __('Device Token dose not Exists.','aheadzen');
				}
			}else{
				$oReturn->error = __('Wrong User Id.','aheadzen');
			}
//			echo json_encode($oReturn);
//			header('Content-Type: application/json; charset=UTF-8', true);
//			exit;
		}else if($_REQUEST['action']=='send_user_notification'){
			/*Send custom notification to a particular user*/
			if($_REQUEST['user_ids']){
				$user_ids = $_REQUEST['user_ids'];
				$content = $_REQUEST['content'];
				$user_ids_arr = explode(',',$user_ids);
				$device_token_arr = array();
				if($user_ids_arr){
					for($u=0;$u<count($user_ids_arr);$u++){
						$user_device_token = self::get_user_device_token($user_ids_arr[$u]);
						if($user_device_token){
							$device_token_arr[] = $user_device_token;
						}
					}
					$args = array();
					$args['device_tokens'] = $device_token_arr;
					$args['content'] = wp_strip_all_tags($content);
					self::sendPushNotification($args);
				}
				if($counter){
					$oReturn->success = __('Notificaiton Sent Successfully.','aheadzen');
				}else{
					$oReturn->error = __('Not a single selected user registered device.','aheadzen');
				}
			}
	//		echo json_encode($oReturn);
	//		header('Content-Type: application/json; charset=UTF-8', true);
	//		exit;
		}
		return json_encode($oReturn);
	}

	function sendPushNotification($args){
		
		
		if($args['device_tokens']){
			
			$final_arr = array_chunk($args['device_tokens'],500);
			for($i=0;$i<count($final_arr);$i++){
				foreach($final_arr[$i] as $final_arrObj){
					if($final_arrObj && $final_arrObj['device_token']){
						$tokens = $final_arrObj['device_token'];
						$platform = $final_arrObj['platform'];
						if($final_arrObj['username']){ $args['username'] = $final_arrObj['username']; }
					}else{
						$tokens = $final_arrObj;
						$platform = 0;
					}
					$message = $args['content'];
					$apitype = get_option('bppush_apitype');
					
					


    $title = sanitize_text_field($message);
	$wadate = date('Y-m-d H:i:s');
	$content = '';
	
  $page =  array(
        'post_title'        => $title,
        'post_content'      => $content,
        'post_status'       => 'publish',
        'post_type' => 'wa_pns_messages',
        'post_date'  => $wadate, 
		'post_date_gmt'  => $wadate, 
        'post_author'       => '1'
		);

        // Page doesn't exist, so lets add it
        $post_id = wp_insert_post( $page );


	  add_post_meta($post_id, '_wapn_message', 'no');
        
    /*     
	$args = array( 'post_type' => 'wa_pns' );
	 $post_ids = get_posts(array(
        $args, //Your arguments
        'post_type'        => 'wa_pns',
        'posts_per_page'=> -1,
        'fields'        => 'ids', // Only get post IDs
    ));
    */
    $device_token_ids = array();
    foreach($args['device_tokens'] as $device_token){
	    
	  $device_id =   get_page_by_title( $device_token, '', 'wa_pns' );
	  
	  $device_token_ids[] =    $device_id->ID;
    }
    
	 $jsonTitles = json_encode($device_token_ids, false);
     
     add_post_meta($post_id, '_wapn_message_users', $jsonTitles);

	 add_post_meta($post_id, '_wapn_message_users_count', count($final_arr));
	
   // process form data

					
					
					/*if($apitype=='pushbots_notification'){
						bp_WordApp_notify_pushbots($tokens,$message,$platform,$args);
					}else{
						bp_WordApp_notify($tokens,$message);
					}*/				
				}
			}
		}
	}

	/*Collect user device token from user id*/
	function get_user_device_token($user_id)
	{
		if(!$user_id)return '';
		
		return get_user_meta( $user_id, 'wordapp_push_device_token', true);
	}
	
	function check_valid_user($userid,$pw){
		$user = get_userdata($userid);
		if($user && wp_check_password($pw,$user->user_pass,$user->ID)){
			return true;
		}
		return false;
	}

}

if(!function_exists('get_userid_from_token')){
	function get_userid_from_token($auth_token){
		$user_id = 0;
		$auth_token = urldecode($auth_token);
		if(class_exists('az_json_login')){
			$json_login = new az_json_login();
			$user_id = $json_login->get_userid_from_publickey($auth_token);		
		}
		return $user_id;
	}
}

if(!function_exists('get_userdata_from_publickey')){
	function get_userdata_from_publickey($auth_token){
		$user_id = 0;
		$auth_token = urldecode($auth_token);
		if(class_exists('az_json_login')){
			$json_login = new az_json_login();
			$user_data = $json_login->get_userdata_from_publickey($auth_token);
		}
		return $user_data;
	}
}