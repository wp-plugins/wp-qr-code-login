<?php 
/*
Plugin Name: No More Passwords*
Plugin URI: http://www.jackreichert.com/plugins/qr-login/
Description: Lets WordPress users login to admin using a QR code
Version: 0.1
Author: Jack Reichert
Author URI: http://www.jackreichert.com
License: GPL2
				
*/

// Creates Hash places as meta tag in header (for js to find) inserts into db.
function wp_qr_code_login_head() {
	// Enqueue script that creates and places QR-code on login page
	wp_enqueue_script( 'qrLogin_js', plugins_url('/qrLogin.js', __FILE__), array( 'jquery' ) );
	wp_localize_script( 'qrLogin_js', 'qrLoginAjax', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );

	global $wpdb;
	$hash = md5(uniqid(rand(), true)); ?>
	<meta name="qrHash" content="<?php echo $hash; ?>">

<?php
	$table_name = $wpdb->prefix . "qrLogin";
	$rows_affected = $wpdb->insert( $table_name, array( 'timestamp' => current_time('mysql'), 'uname' => 'guest', 'hash' => $hash ) );

}
// adds init to login header
add_action('login_head', 'wp_qr_code_login_head');

// before headers are sent checks to see if hash has been received. Logs in if all checks out.
function wp_qr_code_init_head (){
	if (isset($_GET['qrHash']) && $_GET['qrHash'] != 'used'){
		global $wpdb;
		$hash = mysql_real_escape_string($_GET['qrHash']);
		$qrUserLogin = $wpdb->get_results("SELECT * FROM ".$wpdb->prefix."qrLogin WHERE hash = '".$hash."'");
		$user_login = $qrUserLogin[0]->uname;

		if ($user_login != NULL && $user_login != 'guest'){

	        $user = get_userdatabylogin($user_login);
	        $user_id = $user->ID;

	        wp_set_current_user($user_id, $user_login);
	        wp_set_auth_cookie($user_id);
	        do_action('wp_login', $user_login);
			
			$mylink = $wpdb->get_results("UPDATE ".$wpdb->prefix."qrLogin SET hash = 'used' WHERE uname = '".$user_login."'");
			echo '<script type="text/javascript">window.location = "'.get_bloginfo("url").'/wp-admin/";</script>'; 
		
		} 
	}
}
// adds before headers are sent
add_action('init', 'wp_qr_code_init_head');


// The viewer will not be logged in
add_action( 'wp_ajax_nopriv_ajax-qrLogin', 'ajax_check_logs_in' );
function ajax_check_logs_in() {

	// Gets current time
	$time = time();
	while((time() - $time) < 30) {
		
		// get the submitted qrHash
		$qrHash = mysql_real_escape_string($_POST['qrHash']);
		global $wpdb;
		$qrUserLogin = $wpdb->get_results("SELECT * FROM ".$wpdb->prefix."qrLogin WHERE hash = '".$qrHash."'");
	 
	    if($qrUserLogin[0]->uname != 'guest') {
	    	header( "Content-Type: application/json" );
			echo json_encode($qrUserLogin[0]);
	        break;
	    }
	 
	    usleep(25000);
	}	
 
    // IMPORTANT: don't forget to "exit"
    exit;
}


// manage db version
global $qrLogin_db_version;
$qrLogin_db_version = "0.1";

// Sets up db
function jal_install() {
   global $wpdb;
   global $qrLogin_db_version;

   $table_name = $wpdb->prefix . "qrLogin";
      
   $sql = "CREATE TABLE " . $table_name . " (
	  id mediumint(9) NOT NULL AUTO_INCREMENT,
	  timestamp datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
	  hash text NOT NULL,
	  uname tinytext NOT NULL,
	  UNIQUE KEY id (id)
    );";

   require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
   dbDelta($sql);
 
   add_option("qrLogin_db_version", $qrLogin_db_version);
}

// Installs db on plugin activation
register_activation_hook(__FILE__,'jal_install');

// Admin page. Saves user to db.
function qrLogin_plugin_menu() {
	add_options_page('QR Login Plugin Options', 'QR Login', 'manage_options', 'qr-login', 'qrLogin_plugin_options');
}
function qrLogin_plugin_options() {
	if (!current_user_can('manage_options'))  {
		wp_die( __('You do not have sufficient permissions to access this page.') );
	}
	$current_user = wp_get_current_user();
	$hash = mysql_real_escape_string($_GET['qrHash']);
	echo '<p>Hello '.$current_user->user_login.'</p>';
	echo '<div class="wrap">';
	echo '<p>You have successfully logged in.</p>';
	echo '</div>';
	
	global $wpdb;
	if (isset($_GET['qrHash'])){
		$mylink = $wpdb->get_results("UPDATE ".$wpdb->prefix."qrLogin SET uname = '".$current_user->user_login."' WHERE hash = '".$hash."'");
	}
}
add_action('admin_menu', 'qrLogin_plugin_menu');

// Cleans db from extra entries hourly
function qr_cron_activate() {
	wp_schedule_event(time(), 'hourly', 'qr_hourly_clean');
}
function qr_clean_hourly(){
	global $wpdb;
	$mylink = $wpdb->get_results("DELETE FROM ".$wpdb->prefix."qrLogin WHERE uname = 'guest'");
}
register_activation_hook(__FILE__, 'qr_cron_activate');
add_action('qr_hourly_clean', 'qr_clean_hourly');

// Clears cron on deactivation
function qr_cron_deactivate() {
	wp_clear_scheduled_hook('qr_hourly_clean');
	qr_clean_hourly();
}
register_deactivation_hook(__FILE__, 'qr_cron_deactivate');
