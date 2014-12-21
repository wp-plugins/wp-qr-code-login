<?php 
/*
Plugin Name: No More Passwords*
Plugin URI: http://www.jackreichert.com/plugins/no-more-passwords/
Description: Lets WordPress users login to the Dashboard using a QR code
Version: 1.2
Author: Jack Reichert
Author URI: http://www.jackreichert.com
License: GPL2
				
*/

// Creates Hash places as meta tag in header (for js to find) inserts into db.
add_action('login_head', 'wp_qr_code_login_head');
function wp_qr_code_login_head() {
	// Enqueue script that creates and places QR-code on login page
	wp_enqueue_script( 'qrLogin_js', plugins_url('/qrLogin.js', __FILE__), array( 'jquery' ) );
	wp_localize_script( 'qrLogin_js', 'qrLoginAjaxRequest', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ), 'qrLoginNonce' => wp_create_nonce( 'qrLogin-nonce' ) ) );

	global $wpdb;
	$hash = md5(uniqid(rand(), true)); ?>
	<meta name="qrHash" content="<?php echo $hash; ?>" wpurl="<?php bloginfo('url'); ?>">

<?php
	usleep(500);
	$table_name = $wpdb->prefix . "qrLogin";
	$rows_affected = $wpdb->insert( $table_name, array( 'timestamp' => current_time('mysql',1), 'uname' => 'guest', 'hash' => $hash, 'uip' => $_SERVER['REMOTE_ADDR']) );

}


// checks to see if hash has been received. Logs in if all checks out.
add_action('login_head', 'wp_qr_code_login_check_user');
function wp_qr_code_login_check_user (){
	if (isset($_GET['qrHash']) && $_GET['qrHash'] != 'used') {
		global $wpdb;
		$hash = preg_replace("/[^0-9a-zA-Z ]/", "",  $_GET['qrHash']);
		$qrUserLogin = $wpdb->get_results($wpdb->prepare("SELECT * FROM ".$wpdb->prefix."qrLogin WHERE hash = %s",$hash));
		$user_login = $qrUserLogin[0]->uname;
		
		if ($user_login != NULL && $user_login != 'guest'){

	        $user = get_user_by('login',$user_login);
	        $user_id = $user->ID;

	        wp_set_current_user($user_id, $user_login);
	        wp_set_auth_cookie($user_id);
	        do_action('wp_login', $user_login);
			
			$table_name = $wpdb->prefix . "qrLogin";
			$rows_affected = $wpdb->update( $table_name, array('hash' => 'used'), array('uname' => $user_login) );

			echo '<script type="text/javascript">window.location = "'.get_bloginfo("url").'/wp-admin/";</script>'; 
		
		} 
	}
}


// The viewer will not be logged in
add_action( 'wp_ajax_nopriv_ajax-qrLogin', 'ajax_check_logs_in' );
function ajax_check_logs_in() {
	$nonce = preg_replace("/[^0-9a-zA-Z ]/", "", $_POST['QRnonce']);

	if ( ! wp_verify_nonce( $nonce, 'qrLogin-nonce' ) ) { 
        die ( 'Busted!'); 
    }
	
	// Gets current time
	$time = time();
	while((time() - $time) < 30) {
		
		// get the submitted qrHash
		$qrHash = preg_replace("/[^0-9a-zA-Z ]/", "", $_POST['qrHash']);
		global $wpdb;
		$qrUserLogin = $wpdb->get_results($wpdb->prepare("SELECT * FROM ".$wpdb->prefix."qrLogin WHERE hash = %s",$qrHash));

	    if(isset($qrUserLogin[0]) && $qrUserLogin[0]->uname != 'guest') {
            header('Access-Control-Allow-Origin: *'); 
	    	header( "Content-Type: application/json" );
			echo json_encode($qrUserLogin[0]->hash);
	        break;
	    } elseif(is_null($qrUserLogin[0])) {
            header('Access-Control-Allow-Origin: *'); 
            header( "Content-Type: application/json" );
			echo json_encode("hash gone");
	        break;
        }
	 
	    usleep(500);
	}	
 
    // IMPORTANT: don't forget to "exit"
    exit();
}


// Admin page. Saves user to db.
add_action('admin_menu', 'qrLogin_plugin_menu');
function qrLogin_plugin_menu() {
	add_options_page('No More Passwords Plugin Options', 'No More Passwords', 'manage_options', 'qr-login', 'qrLogin_plugin_options');
}
function qrLogin_plugin_options() {
	if (!current_user_can('manage_options'))  {
		wp_die( __('You do not have sufficient permissions to access this page.') );
	} ?>
	<style>
		@media only screen and (max-width: 550px), (max-device-width: 550px) {
			#adminmenuwrap, #wpadminbar, #adminmenuback { display: none; }	
			#wpcontent { margin: 0; }
			h1 { font-size: 3.5em; line-height: 1.5em; width: 80%; margin: 1em auto; text-align: center; }
			input { display:block; width: 80%; height: 5em; font-size: 5em; margin: 0.5em auto;  }
		}
	</style>
<?php
	if (isset($_GET['QRnonceAdmin']) && isset($_GET['qrHash'])){
		if ( ! wp_verify_nonce( preg_replace("/[^0-9a-zA-Z ]/", "", $_GET['QRnonceAdmin']), 'QRnonceAdmin' ) ) { 
			die ( 'Busted!'); 
		}
		
		$current_user = wp_get_current_user();
		$hash = preg_replace("/[^0-9a-zA-Z ]/", "", $_GET['qrHash']); ?>
		<h1>Howdy, <?php echo $current_user->display_name; ?>.<br>
			You have successfully logged in.</h1> 
<?php		
		global $wpdb;
		$table_name = $wpdb->prefix . "qrLogin";
		$rows_affected = $wpdb->update( $table_name, array('uname' => $current_user->user_login), array('hash' => $hash) );	

	} elseif (isset($_GET['qrHash'])){ 
		if (isset($_GET['reject'])) { 
		?>
		<h1>The requester will not be logged in.</h1>
		<?php	
		} else {
			$qrHash = preg_replace("/[^0-9a-zA-Z ]/", "", $_GET['qrHash']);
			global $wpdb;
			$qrUserLogin = $wpdb->get_results($wpdb->prepare("SELECT * FROM ".$wpdb->prefix."qrLogin WHERE hash = %s", $qrHash));
			?>
			<h1>A login request has been made from<br>
			<?php echo $qrUserLogin[0]->uIP; ?><br>
			are you sure that you want to log in?</h1>
			<form action="" method="get">
				<input type="hidden" name="page" value="qr-login">
				<input type="hidden" name="qrHash" value="<?php echo $qrHash; ?>">
				<input type="hidden" name="QRnonceAdmin" value="<?php echo wp_create_nonce('QRnonceAdmin'); ?>">
				<input type="submit" value="YES">
			</form>
			
			<form action="" method="get">
				<input type="hidden" name="page" value="qr-login">
				<input type="hidden" name="qrHash" value="<?php echo $qrHash; ?>">
				<input type="hidden" name="reject" value="busted">
				<input type="submit" value="NO">
			</form>
			<?php	
		}
	} else {
		if (current_user_can('manage_options')){ ?>
			<h2>Logs</h2>
		<?php	
			global $wpdb;
			$logs = $wpdb->get_results("SELECT * FROM ".$wpdb->prefix."qrLogin WHERE hash = 'used'");
			foreach($logs as $log){
				echo $log->uname.' logged in on '.$log->timestamp.'<br>';
			}
		}
	}
}


// define cron schedlue for cleaning old qrcode hashses
add_filter('cron_schedules', 'newSchedules');
function newSchedules($schedules){ // Creates new 
	$schedules['threeMin'] = array('interval'=> 180, 'display'=>  __('Once Every 3 Minutes'));    
  
return $schedules;
}

// Cleans db from extra entries every three minutes
add_action('qr_three_clean', 'qr_housecleaning');
function qr_housecleaning(){
	global $wpdb;
	$mylink = $wpdb->get_results("DELETE FROM ".$wpdb->prefix."qrLogin WHERE uname = 'guest' AND TIMESTAMPDIFF(MINUTE, timestamp, UTC_TIMESTAMP()) > 5");
}


// Sets up db
function qrLoginDB_install() {
	$qrLogin_db_version = "0.2";
    global $wpdb;
    $table_name = $wpdb->prefix . "qrLogin";      
    $sql = "CREATE TABLE " . $table_name . " (
      id mediumint(9) NOT NULL AUTO_INCREMENT,
      timestamp datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
      hash text NOT NULL,
      uname tinytext NOT NULL,
      uIP VARCHAR(55) DEFAULT '' NOT NULL,
      UNIQUE KEY id (id)
    );";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    update_option("qrLogin_db_version", $qrLogin_db_version);

}

function qr_cron_activate() {
	wp_schedule_event(time(), 'threeMin', 'qr_three_clean');
}

// adds cron and db on activation
register_activation_hook(__FILE__, 'qr_activate');
function qr_activate() {
    qr_cron_activate();
    qrLoginDB_install();
}

// Clears cron and extra hashes on deactivation, keeps user login log
register_deactivation_hook(__FILE__, 'qr_cron_deactivate');
function qr_cron_deactivate() {
	wp_clear_scheduled_hook('qr_three_clean');
	global $wpdb;
	$mylink = $wpdb->get_results("DELETE FROM ".$wpdb->prefix."qrLogin WHERE uname = 'guest'");
}
