<?php 
/*
Plugin Name: No Passwords
Plugin URI: http://nopasswords.website/
Description: Lets WordPress users login to their site using a QR code
Version: 1.3
Author: Jack Reichert
Author URI: http://www.jackreichert.com
License: GPL2
				
*/

$NoPasswords = New NoPasswords();

class NoPasswords {
    private $version;
    private $tbl_name;
    
    /**
     * Contruct - sets up plugin and dependencies
     *
     */
    public function __construct() {
        $this->version = "1.3";
        $this->tbl_name = "qrLogin";
        $this->load_dependencies();
        $this->load_actions();
    }
    
    /** 
     * Package dependencies
     *
     */
    private function load_dependencies() {
        require_once(dirname(__FILE__).'/libs/TimeOTP.php');
        require_once(dirname(__FILE__).'/libs/phpqrcode.php');
    }
    
    /**
     * WordPress actions and filters
     *
     */
    public function load_actions() {
        add_action('login_head', array( $this,'wp_qr_code_login_head'));
        add_action('login_head', array( $this,'wp_qr_code_login_check_user'));
        add_action( 'wp_ajax_nopriv_ajax-qrLogin', array( $this,'ajax_check_logs_in') );
        add_action('parse_request', array( $this, 'qrLoginOTP'));
        add_action('admin_menu', array( $this, 'qrLogin_plugin_menu'));
        add_action('qr_three_clean', array( $this, 'qr_housecleaning'));
        register_activation_hook(__FILE__, array( $this, 'qr_activate'));
        register_deactivation_hook(__FILE__, array( $this, 'qr_cron_deactivate'));
        add_filter('cron_schedules', array( $this, 'newSchedules') );
    }

    /**
     * Creates Hash places as meta tag in header (for js to find) inserts into db.
     *
     */ 
    public function wp_qr_code_login_head() {
        // only the login action should get the qr code
        if ( isset( $_GET['action'] ) && ( $_GET['action'] == 'lostpassword' || $_GET['action'] == 'register' ) ) {
            return;
        }
        
        // Enqueue script that creates and places QR-code on login page
        wp_enqueue_script( 'qrLogin_js', plugins_url('/js/qrLogin.js', __FILE__), array( 'jquery' ) );
        wp_localize_script( 'qrLogin_js', 'qrLoginAjaxRequest', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ), 'qrLoginNonce' => wp_create_nonce( 'qrLogin-nonce' ), 'qrHash' => $this->generateHash(), 'reloadNonce' => wp_create_nonce( 'reload-nonce' ) ) );

    }

    /**
     * generates hash for identifying which browser is being accessed
     *
     */
    private function generateHash() {
        // generate hash
        $hash = TimeOTP::generateSecret();
        usleep(500);
        
        // insert hash into db
        global $wpdb;
        $table_name = $wpdb->prefix . $this->tbl_name;
        $rows_affected = $wpdb->insert( $table_name, array( 'timestamp' => current_time('mysql',1), 'uname' => 'guest', 'hash' => $hash, 'uip' => $_SERVER['REMOTE_ADDR']) );

        return $hash;
    }

    /**
     * checks to see if hash has been received. Logs in if all checks out.
     *
     */
    public function wp_qr_code_login_check_user(){
        if ( isset( $_GET['reloadNonce'] ) ) {
            $reloadNonce = preg_replace("/[^0-9a-zA-Z ]/", "",  $_GET['reloadNonce']);
            if (isset($_GET['qrHash']) && $_GET['qrHash'] != 'used' && wp_verify_nonce( $reloadNonce, 'reload-nonce' )) {

                $hash = preg_replace("/[^0-9a-zA-Z ]/", "",  $_GET['qrHash']);
                $user_login = $this->get_user_by_qrHash( $hash );

                if ($user_login != NULL && $user_login != 'guest' && $user_login != ''){

                    $this->log_user_in_with_login( $user_login );

                    global $wpdb;
                    $table_name = $wpdb->prefix . $this->tbl_name;
                    $rows_affected = $wpdb->update( $table_name, array('hash' => 'used'), array('uname' => $user_login, 'hash' => $hash) );

                    $redirect_to = get_bloginfo("url").'/wp-admin/';
                    if ( isset( $_GET['redirect_to'] ) ) {
                        $redirect_to = $_GET['redirect_to'];
                    }
                    echo '<script type="text/javascript">window.location = "'.$redirect_to.'";</script>'; 

                } 
            }
        }
    }
    
    /**
     * finds user that used qr hash on phone
     *
     */
    private function get_user_by_qrHash( $hash ) {
        global $wpdb;
        $table_name = $wpdb->prefix . $this->tbl_name;
        $qrUserLogin = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE hash = %s", $hash));
        if ( is_array( $qrUserLogin ) && isset( $qrUserLogin[0]->uname ) ) {
            return $qrUserLogin[0]->uname;
        }
        
        return false;
    }
    
    /**
     * logs user in giventheir user_login
     *
     */
    private function log_user_in_with_login( $user_login ) {
        $user = get_user_by('login', $user_login);
        $user_id = $user->ID;

        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);
        do_action('wp_login', $user_login);
    }
    
    /**
     * Checks to see if the user has logged in form their device
     * the viewer will not be logged in so only nopriv
     *
     */
    public function ajax_check_logs_in() {
        $nonce = preg_replace("/[^0-9a-zA-Z ]/", "", $_POST['QRnonce']);

        if ( ! wp_verify_nonce( $nonce, 'qrLogin-nonce' ) ) { 
            die ( 'Busted!'); 
        }

        // Gets current time
        $time = time();
        while((time() - $time) < 30) {

            // get the submitted qrHash
            $qrHash = preg_replace("/[^0-9a-zA-Z ]/", "", $_POST['qrHash']);
            $qrUserLogin = $this->get_user_by_qrHash( $qrHash );

            if( $qrUserLogin && $qrUserLogin != 'guest') {
                header('Access-Control-Allow-Origin: *'); 
                header( "Content-Type: application/json" );
                echo json_encode($qrHash);
                break;
            } elseif(is_null($qrUserLogin[0])) {
                header('Access-Control-Allow-Origin: *'); 
                header( "Content-Type: application/json" );
                echo json_encode("hash gone");
                break;
            }

            usleep(500);
        }	

        exit();
    }
    
    /**
     * used by app, if alt username/password + otp check out, log user in
     *
     */
    public function qrLoginOTP($query) {
        if ($query->request == "NoPasswords.website") {
            if (isset($_POST['qrHash']) && isset($_POST['uuid']) && isset($_POST['otp']) && isset($_POST['mfth']) ) {
                $qrHash = preg_replace("/[^0-9a-zA-Z ]/", "", $_POST['qrHash']);
                $uuid = preg_replace("/[^0-9a-zA-Z ]/", "", $_POST['uuid']);
                $otp = preg_replace("/[^0-9a-zA-Z ]/", "", $_POST['otp']);
                $mfth = preg_replace("/[^0-9a-zA-Z ]/", "", $_POST['mfth']);

                // get user of pk
                $userOfPK = get_users( array(
                    'meta_key' => "uuid",
                    'meta_value' => $uuid,
                    'number' => 1,
                    'count_total' => false
                ));

                if ( count( $userOfPK ) === 1 ) {
                    // check qrHash
                    $qrUserLogin = $this->get_user_by_qrHash( $qrHash );
                    // if hasen't been used
                    if ( $qrUserLogin == "guest" ) {
                        // get secret  
                        $secret = get_user_meta($userOfPK[0]->ID, 'secret', true);                
                        $pin = TimeOTP::calcOTP( $secret );

                        if ( $pin === $otp ) {
                            // Compare check decrypted challenge
                            $hashed = get_user_meta($userOfPK[0]->ID, 'mfth', true);

                            if ( wp_check_password( $mfth, $hashed ) ) {
                                // tell db that user can log in                                
                                global $wpdb;
                                $table_name = $wpdb->prefix . $this->tbl_name;
                                $rows_affected = $wpdb->update( $table_name, array('uname' => $userOfPK[0]->user_login), array('hash' => $qrHash) );
                                die("Your Jedi mindtricks seem to have power here.");
                            } else {
                                // error_log("Password DON'T match... Password: $mfth"); 
                            }
                        } else {
                            // error_log("OTPs DON'T match... Pin: $pin / OTP: $otp"); 
                        }
                    }   
                }            
            } elseif ( isset($_GET['qrHash']) ) {
                header('Access-Control-Allow-Origin: *'); 
                header( "Content-Type: application/json" );
                $qrHash = preg_replace("/[^0-9a-zA-Z ]/", "", $_GET['qrHash']);
                QRcode::png(get_bloginfo('url') . '/wp-admin/options-general.php?page=qr-login&qrHash=' . $qrHash, false, 'h', 3, 5, false);
                exit();
            } 
            
            die("NoPasswords.website");
        }
    }
    
    /**
     * Admin page. Saves user to db.
     *
     */
    public function qrLogin_plugin_menu() {
        add_options_page('No More Passwords Plugin Options', 'No More Passwords', 'manage_options', 'qr-login', array( $this, 'qrLogin_plugin_options'));
    }
    public function qrLogin_plugin_options() { ?>
        <style>
            @media only screen and (max-width: 550px), (max-device-width: 550px) {
                #adminmenuwrap, #wpadminbar, #adminmenuback { display: none; }	
                #wpcontent { margin: 0; }
                h1 { font-size: 1.5em; line-height: 1.5em; width: 80%; margin: 1em auto; text-align: center; }
                input[type=submit] { display:block!important; width: 45%; height: 2em; font-size: 1.5em; float: left; }
                input + input[type=submit] { margin-right: 1em!important; }
            }
        </style>
    <?php
        $current_user = wp_get_current_user();        
        if (isset($_GET['QRnonceAdmin']) && isset($_GET['qrHash'])){ // user logs in via standard qr code reader and web browser
            // verify nonce
            if ( ! wp_verify_nonce( preg_replace("/[^0-9a-zA-Z ]/", "", $_GET['QRnonceAdmin']), 'QRnonceAdmin' ) ) { 
                die ( 'Busted!'); 
            }
            
            $hash = preg_replace("/[^0-9a-zA-Z ]/", "", $_GET['qrHash']); ?>
            <h1>Howdy, <?php echo $current_user->display_name; ?>.<br>
                You have successfully logged in.</h1> 
    <?php		
            // update table so browser knows user has logged in
            global $wpdb;
            $table_name = $wpdb->prefix . $this->tbl_name;
            $rows_affected = $wpdb->update( $table_name, array('uname' => $current_user->user_login), array('hash' => $hash) );	

        } elseif (isset($_GET['qrHash']) && isset($_GET['uuid'])){ // set up new user with app   
            // app sends new uuid along with hash, user can only get here if authenticates
            $qrHash = preg_replace("/[^0-9a-zA-Z ]/", "", $_GET['qrHash']);
            if ( $this->get_user_by_qrHash( $qrHash ) != 'guest' ) {
                die ( 'Busted!');
            }
            $uuid = preg_replace("/[^0-9a-zA-Z ]/", "", $_GET['uuid']);

            $userOfPK = get_users( array(
                'meta_key' => "uuid",
                'meta_value' => $uuid,
                'number' => 1,
                'count_total' => false
            ));
            if ( count( $userOfPK ) > 0 ) {
                // error_log("Dupicate users: ".json_encode($userOfPK));
            } else {
                // generate secret and set alt password + confirm with users
                $secret = TimeOTP::generateSecret(64);
                if ( $secret ) {
                    $mfth = wp_generate_password(64, false, false); ?>

                    <h2>Howdy, <?php echo $current_user->display_name; ?>.<br>
                        Please confirm that you'd like to connect to:<br>
                        <?php bloginfo('url'); ?>
                    </h2>

                    <form action="/wp-admin/options-general.php?page=qr-login" method="post" name="nmpsec" accept-charset="utf-8" >
                        <input type="hidden" value="<?php echo $uuid; ?>" name="uuid">
                        <input type="hidden" value="<?php echo $secret; ?>" name="secret">
                        <input type="hidden" value="<?php echo $mfth; ?>" name="mfth">
                        <input type="hidden" name="nmpSecnonceAdmin" value="<?php echo wp_create_nonce('nmpSecnonceAdmin'); ?>">
                        <button class="button button-primary button-hero" type="submit">Confirm connection</button>
                    </form>
            <?php
                }
            }

        } elseif ( isset($_POST['nmpSecnonceAdmin']) && isset($_POST['secret']) && isset($_POST['mfth'])) { 
            // user confirmed, send secret and alt password to app
            $nonce = preg_replace("/[^0-9a-zA-Z ]/", "", $_POST['nmpSecnonceAdmin']);
            $secret = preg_replace("/[^0-9a-zA-Z ]/", "", $_POST['secret']);
            $mfth = urldecode($_POST['mfth']);
            $uuid = preg_replace("/[^0-9a-zA-Z ]/", "", $_POST['uuid']);            

            if ( ! wp_verify_nonce( $nonce, 'nmpSecnonceAdmin' ) ) { 
                die ( 'Busted!'); 
            } ?>

            <input type="hidden" value="<?php echo $secret; ?>" id="NMPsec">
            <input type="hidden" value="<?php echo $mfth; ?>" id="NMPmfth">
            <input type="hidden" value="<?php echo $uuid; ?>" id="NMPuuid">

            <?php
            delete_user_meta($current_user->ID, "mfth");
            delete_user_meta($current_user->ID, "uuid");
            delete_user_meta($current_user->ID, "secret");
            
            // save hashed password
            $hashed = wp_hash_password($mfth);
            
            // true to prevent multiple devices
            if ( add_user_meta($current_user->ID, "mfth", $hashed, true)
                && add_user_meta($current_user->ID, "uuid", $uuid, true) 
                && add_user_meta($current_user->ID, "secret", $secret, true) ) {
                die("New user successfully added!");
            } else {
                die("No success.");
            }
        } elseif (isset($_GET['qrHash'])){ 
            // user logs in via standard qr code reader and web browser
            if (isset($_GET['reject'])) { ?>
                <h1>The requester will not be logged in.</h1>
            <?php	
            } else { 
                $qrHash = preg_replace("/[^0-9a-zA-Z ]/", "", $_GET['qrHash']);
                global $wpdb;
                $qrUserLogin = $wpdb->get_results($wpdb->prepare("SELECT * FROM ".$wpdb->prefix."qrLogin WHERE hash = %s", $qrHash)); ?>
                <h1>A login request for<br> 
                <?php bloginfo('url'); ?><br> 
                has been made from<br>
                <?php echo $qrUserLogin[0]->uIP; ?><br>
                are you sure that you want to log in?</h1>
                <form action="" method="get">
                    <input type="hidden" name="page" value="qr-login">
                    <input type="hidden" name="qrHash" value="<?php echo $qrHash; ?>">
                    <input type="hidden" name="QRnonceAdmin" value="<?php echo wp_create_nonce('QRnonceAdmin'); ?>">
                    <input class="button button-primary button-large" type="submit" value="YES">
                </form>

                <form action="" method="get">
                    <input type="hidden" name="page" value="qr-login">
                    <input type="hidden" name="qrHash" value="<?php echo $qrHash; ?>">
                    <input type="hidden" name="reject" value="busted">
                    <input class="button button-primary button-large" type="submit" value="NO">
                </form>
                <?php	
            }
        } else { 
            // this will soon be the full user management table
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

    /**
     * Every three minutes deletes all rows that haven't been used yet
     * 
     */
    public function qr_housecleaning(){
        global $wpdb;
        $mylink = $wpdb->get_results("DELETE FROM ".$wpdb->prefix."qrLogin WHERE hash NOT IN ('used') AND TIMESTAMPDIFF(MINUTE, timestamp, UTC_TIMESTAMP()) > 5");
    }
    
    /**
     * adds cron and db on activation
     *
     */
    public function qr_activate() {
        $this->qr_cron_activate();
        $this->qrLoginDB_install();
    }

    /**
     * Sets up db
     *
     */
    private function qrLoginDB_install() {
        $qrLogin_db_version = "0.2";
        global $wpdb;
        $table_name = $wpdb->prefix . $this->tbl_name;      
        $sql = "CREATE TABLE " . $table_name . " (
          id mediumint(9) NOT NULL AUTO_INCREMENT,
          timestamp datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
          hash text NOT NULL,
          uname tinytext NOT NULL,
          challenge text DEFAULT '' NOT NULL,
          uIP VARCHAR(55) DEFAULT '' NOT NULL,
          UNIQUE KEY id (id)
        );";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        update_option("qrLogin_db_version", $qrLogin_db_version);

    }

    /**
     * creates 3 minute cron job
     *
     */
    private function qr_cron_activate() {
        wp_schedule_event(time(), 'threeMin', 'qr_three_clean');
    }

    /** 
     * Clears cron and extra hashes on deactivation, keeps user login log
     *
     */
    public function qr_cron_deactivate() {
        wp_clear_scheduled_hook('qr_three_clean');
        global $wpdb;
        $mylink = $wpdb->get_results("DELETE FROM ".$wpdb->prefix."qrLogin WHERE hash NOT IN ('used')");
    }
 
    /**
     * define cron schedlue for cleaning old qrcode hashses
     *
     */
    public function newSchedules($schedules){ // Creates new 
        $schedules['threeMin'] = array('interval'=> 180, 'display'=>  __('Once Every 3 Minutes'));    

        return $schedules;
    }
    
}
