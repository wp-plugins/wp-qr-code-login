<?php
/*
Plugin Name: Unlock Digital (No Passwords)
Plugin URI: http://unlock.digital/
Description: Formally, No More Passwords, this plugin with companion app lets WordPress users login to their site using a QR code
Version: 1.4.3
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
		$this->version  = get_option( "qrLogin_db_version", "1.3.1" );
		$this->tbl_name = "qrLogin";
		if ( "1.3.6" != $this->version ) {
			$this->qrLoginDB_install();
		}

		$this->load_dependencies();
		$this->load_actions();
	}

	/**
	 * Package dependencies
	 *
	 */
	private function load_dependencies() {
		require_once( dirname( __FILE__ ) . '/libs/TimeOTP.inc' );
		require_once( dirname( __FILE__ ) . '/libs/phpqrcode.inc' );
	}

	/**
	 * WordPress actions and filters
	 *
	 */
	public function load_actions() {
		add_action( 'login_enqueue_scripts', array( $this, 'wp_qr_code_login_head' ) );
		add_action( 'wp_ajax_nopriv_ajax-qrLogin', array( $this, 'ajax_check_logs_in' ) );
		add_action( 'parse_request', array( $this, 'qrLoginOTP' ) );
		add_action( 'admin_menu', array( $this, 'qrLogin_plugin_menu' ) );
		add_action( 'qr_three_clean', array( $this, 'qr_housecleaning' ) );
		register_activation_hook( __FILE__, array( $this, 'qr_activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'qr_cron_deactivate' ) );
		add_filter( 'cron_schedules', array( $this, 'newSchedules' ) );
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

		if ( $qrHash = $this->generateHash() ) {
			// Enqueue script that creates and places QR-code on login page
			wp_enqueue_script( 'qrLogin_js', plugins_url( '/js/qrLogin.js', __FILE__ ), array( 'jquery' ) );
			wp_localize_script( 'qrLogin_js', 'qrLoginAjaxRequest', array(
					'ajaxurl'      => admin_url( 'admin-ajax.php' ),
					'homeurl'      => preg_replace("(^https?://)", "//", get_home_url( null, "", "https" )),
					'qrLoginNonce' => wp_create_nonce( 'qrLogin-nonce' ),
					'qrHash'       => $this->generateHash(),
					'reloadNonce'  => wp_create_nonce( 'reload-nonce' )
				)
			);
		}

	}

	/**
	 * generates hash for identifying which browser is being accessed
	 *
	 */
	private function generateHash() {
		// generate hash
		$hash = TimeOTP::generateSecret();

		// insert hash into db
		global $wpdb;
		$table_name    = $wpdb->base_prefix . $this->tbl_name;
		$rows_affected = $wpdb->insert( $table_name, array(
				'timestamp' => current_time( 'mysql', 1 ),
				'uname'     => 'unused row',
				'hash'      => $hash,
				'uip'       => $_SERVER['REMOTE_ADDR']
			)
		);

		usleep( 5000 );
		if ( $rows_affected ) {
			return $hash;
		} else {
			return false;
		}
	}

	/**
	 * finds user that used qr hash on phone
	 *
	 */
	private function get_user_by_qrHash( $hash ) {
		global $wpdb;
		$table_name  = $wpdb->base_prefix . $this->tbl_name;
		$query       = $wpdb->prepare( "SELECT uname FROM $table_name WHERE hash = %s", $hash );
		$qrUserLogin = $wpdb->get_results( $query );

		if ( count( $qrUserLogin ) == 1 && isset( $qrUserLogin[0]->uname ) ) {
			return $qrUserLogin[0]->uname;
		} else {
			return false;
		}
	}

	/**
	 * logs user in given their user_login
	 *
	 */
	private function log_user_in_with_login( $user_login ) {
		$user    = get_user_by( 'login', $user_login );
		$user_id = $user->ID;

		global $wpdb;
		$table_name    = $wpdb->base_prefix . $this->tbl_name;
		$rows_affected = $wpdb->update( $table_name, array( 'hash' => 'used' ), array( 'uname' => $user_login ) );

		if ( $rows_affected ) {
			wp_set_current_user( $user_id, $user_login );
			wp_set_auth_cookie( $user_id );
			do_action( 'wp_login', $user_login );
		}
	}

	/**
	 * Checks to see if the user has logged in form their device
	 * the viewer will not be logged in so only nopriv
	 *
	 */
	public function ajax_check_logs_in() {
		$nonce = preg_replace( "/[^0-9a-zA-Z ]/", "", $_POST['QRnonce'] );
		if ( ! wp_verify_nonce( $nonce, 'qrLogin-nonce' ) ) {
			die( 'Busted!' );
		}

		$qrHash = preg_replace( "/[^0-9a-zA-Z ]/", "", $_POST['qrHash'] );
		// Gets current time
		$time = time();
		while ( ( time() - $time ) < 30 ) {
			// get the submitted qrHash
			$qrUserLogin = $this->get_user_by_qrHash( $qrHash );
			if ( $qrUserLogin && $qrUserLogin != 'unused row' && username_exists( $qrUserLogin ) ) {
				$this->log_user_in_with_login( $qrUserLogin );
				header( 'Access-Control-Allow-Origin: *' );
				header( "Content-Type: application/json" );
				echo json_encode( $qrHash );
				exit();
				break;
			} elseif ( ! $qrUserLogin || $qrUserLogin == "" ) {
				$qrHash = $this->generateHash();
				if ( $qrHash ) {
					header( 'Access-Control-Allow-Origin: *' );
					header( "Content-Type: application/json" );
					echo json_encode( $qrHash );
					exit();
				}
				break;
			}

			usleep( 1500000 );
		}
		$this->qr_housecleaning();
		exit();
	}

	/**
	 * used by app, if alt username/password + otp check out, log user in
	 *
	 */
	public function qrLoginOTP( $query ) {
		if ( $query->request == "unlock.digital" ) {
			if ( isset( $_POST['qrHash'] ) && isset( $_POST['uuid'] ) && isset( $_POST['otp'] ) && isset( $_POST['mfth'] ) ) {
				$qrHash = preg_replace( "/[^0-9a-zA-Z ]/", "", $_POST['qrHash'] );
				$uuid   = preg_replace( "/[^0-9a-zA-Z ]/", "", $_POST['uuid'] );
				$otp    = preg_replace( "/[^0-9a-zA-Z ]/", "", $_POST['otp'] );
				$mfth   = preg_replace( "/[^0-9a-zA-Z ]/", "", $_POST['mfth'] );

				// get user of pk
				$userOfPK = get_users( array(
					'meta_key'    => "uuid",
					'meta_value'  => $uuid,
					'number'      => 1,
					'count_total' => false
				) );

				if ( count( $userOfPK ) === 1 ) {
					// check qrHash
					$qrUserLogin = $this->get_user_by_qrHash( $qrHash );
					// if hasen't been used
					if ( $qrUserLogin == "unused row" ) {
						// get secret
						$secret = get_user_meta( $userOfPK[0]->ID, 'secret', true );

						// decrypt secret
						$decrypted = rtrim( mcrypt_decrypt( MCRYPT_RIJNDAEL_256, md5( $mfth ), base64_decode( $secret ), MCRYPT_MODE_CBC, md5( md5( $mfth ) ) ), "\0" );

						// generate totp
						$pin = TimeOTP::calcOTP( $decrypted, 8, 60 );

						if ( $pin === $otp ) {
							// Compare check decrypted challenge
							$hashed = get_user_meta( $userOfPK[0]->ID, 'mfth', true );

							if ( wp_check_password( $mfth, $hashed ) ) {
								// tell db that user can log in
								global $wpdb;
								$table_name    = $wpdb->base_prefix . $this->tbl_name; 
                                $site_url = preg_replace('#^(http|https)://#', '', get_bloginfo('url'));
								$rows_affected = $wpdb->update( $table_name, array( 'uname' => $userOfPK[0]->user_login, 'site' => $site_url ), array( 'hash' => $qrHash ) );
								die( "Your Jedi mindtricks seem to have power here." );
							}
						}
					}
				}
			} elseif ( isset( $_GET['qrHash'] ) ) {
				header( 'Access-Control-Allow-Origin: *' );
				header( "Content-Type: application/json" );
				$qrHash = preg_replace( "/[^0-9a-zA-Z ]/", "", $_GET['qrHash'] );
				QRcode::png( get_admin_url() . 'options-general.php?page=qr-login&qrHash=' . $qrHash, false, 'h', 3, 5, false );
				exit();
			}

			die( "unlock.digital" );
		}
	}

	/**
	 * Admin page. Saves user to db.
	 *
	 */
	public function qrLogin_plugin_menu() {
		add_options_page( 'Unlock Digital Plugin Options', 'Unlock Digital', 'read', 'qr-login', array(
			$this,
			'qrLogin_plugin_options'
		) );
	}

	public function qrLogin_plugin_options() { ?>
		<style>
			input[type=submit].submitLink{ font-size:13px; background-color:transparent; border:none; color:#0073AA; cursor:pointer; padding:0; margin:0; }
            input[type=submit].submitLink:hover{ color:#00A0D2; }
            td.logs{ position:relative; }
            .viewLogs{ cursor:pointer; }
            td.logs:hover>.theLogs{ display:block; }
            .theLogs{ display:none; position:absolute; top:90%; left:-25%; width:300px; height:200px; border:1px solid #cdcdcd; background:#fff; overflow:auto; z-index:5; padding:1em; border-radius:5px; box-shadow:0 2px 5px rgba(200,200,200,.25); }
            @media only screen and (max-width:550px),(max-device-width:550px) {
                #adminmenuback,#adminmenuwrap,#wpadminbar{ display:none; }
                #wpcontent{ margin:0; }
                h1{ font-size:1.5em; line-height:1.5em; width:80%; margin:1em auto; text-align:center; }
                input[type=submit]{ display:block!important; width:45%; height:2em; font-size:1.5em; float:left; }
                input+input[type=submit]{ margin-right:1em!important; }
            }
		</style>
		<?php
		$current_user = wp_get_current_user();
		if ( isset( $_GET['QRnonceAdmin'] ) && isset( $_GET['qrHash'] ) ) { // user logs in via standard qr code reader and web browser
			// verify nonce
			if ( ! wp_verify_nonce( preg_replace( "/[^0-9a-zA-Z ]/", "", $_GET['QRnonceAdmin'] ), 'QRnonceAdmin' ) ) {
				die( 'Busted!' );
			}

			$hash = preg_replace( "/[^0-9a-zA-Z ]/", "", $_GET['qrHash'] ); ?>
			<h1>Howdy, <?php echo $current_user->display_name; ?>.<br>
				You have successfully logged in.</h1>
			<?php
			// update table so browser knows user has logged in
			global $wpdb;
			$table_name    = $wpdb->base_prefix . $this->tbl_name;
			$rows_affected = $wpdb->update( $table_name, array( 'uname' => $current_user->user_login ), array( 'hash' => $hash ) );

		} elseif ( isset( $_GET['qrHash'] ) && isset( $_GET['uuid'] ) ) { // set up new user with app
			// app sends new uuid along with hash, user can only get here if authenticates
			$qrHash = preg_replace( "/[^0-9a-zA-Z ]/", "", $_GET['qrHash'] );
			if ( $this->get_user_by_qrHash( $qrHash ) != 'unused row' ) {
				die( "Busted!" );
			}
			$uuid          = preg_replace( "/[^0-9a-zA-Z ]/", "", $_GET['uuid'] );
			$uuid_existing = get_user_meta( $current_user->ID, 'uuid', true );

			$userOfPK = get_users( array(
				'meta_key'    => "uuid",
				'meta_value'  => $uuid,
				'number'      => 1,
				'count_total' => false
			) );
			if ( count( $userOfPK ) > 0 ) {
				// duplicate uuid
			} else {
				// generate secret and set alt password + confirm with users
				?>
				<h2 style="padding:1em;line-height:1.3em;text-align:center;">
					Howdy, <?php echo $current_user->display_name; ?>.<br>
					Please confirm that you'd like to connect to:<br><br>
					<?php bloginfo( 'url' ); ?>
				</h2>

				<form action="<?php echo admin_url( '/options-general.php?page=qr-login' ); ?>" method="post"
				      name="nmpsec" accept-charset="utf-8">
					<input type="hidden" value="" name="mfth" id="NMPmfth">
					<input type="hidden" value="<?php echo ( "" != $uuid_existing ) ? $uuid_existing : $uuid; ?>"
					       name="uuid" id="NMPuuid">
					<input type="hidden" value="<?php echo $current_user->user_login; ?>" name="un" id="NMPun">
					<input type="hidden" name="nmpSecnonceAdmin"
					       value="<?php echo wp_create_nonce( 'nmpSecnonceAdmin' ); ?>">
					<button style="display:block;margin:1em auto;" class="button button-primary button-hero"
					        type="submit">Confirm connection
					</button>
					<p style="text-align:center;"><br>Not <?php echo $current_user->display_name; ?>? <a
							href="<?php echo wp_logout_url( $_SERVER[ REQUEST_URI ] ); ?>">Logout</a></p>
				</form>
			<?php
			}

		} elseif ( isset( $_POST['nmpSecnonceAdmin'] ) && isset( $_POST['uuid'] ) && isset( $_POST['un'] ) ) {
			// user confirmed, send secret and alt password to app
			$nonce = preg_replace( "/[^0-9a-zA-Z ]/", "", $_POST['nmpSecnonceAdmin'] );
			if ( ! wp_verify_nonce( $nonce, 'nmpSecnonceAdmin' ) ) {
				die( 'Busted!' );
			}

			$secret = TimeOTP::generateSecret( 64 );
			$mfth   = wp_generate_password( 64, false, false ); ?>
			<input type="hidden" value="<?php echo $secret; ?>" id="NMPsec">
			<input type="hidden" value="<?php echo $mfth; ?>" id="NMPmfth">
			<?php
			$uuid = preg_replace( "/[^0-9a-zA-Z ]/", "", $_POST['uuid'] );
			$un   = preg_replace( "/[^0-9a-zA-Z ]/", "", $_POST['un'] ); ?>
			<input type="hidden" value="<?php echo $uuid; ?>" id="NMPuuid">
			<input type="hidden" value="<?php echo $un; ?>" id="NMPun">
			<input type="hidden" blogname="<?php echo get_bloginfo( 'blogname' ); ?>"
			       siteurl="<?php echo get_bloginfo( 'url' ); ?>" id="NMPsite">
			<?php

			if ( isset( $_POST['mfth'] ) && "reset" == $_POST['mfth'] ) {
				delete_user_meta( $current_user->ID, "mfth" );
				delete_user_meta( $current_user->ID, "uuid" );
				delete_user_meta( $current_user->ID, "secret" );
			}
			// hash password
			$hashed = wp_hash_password( $mfth );
			// http://stackoverflow.com/questions/9262109/php-simplest-two-way-encryption
			$encrypted = base64_encode( mcrypt_encrypt( MCRYPT_RIJNDAEL_256, md5( $mfth ), $secret, MCRYPT_MODE_CBC, md5( md5( $mfth ) ) ) );

			// true to prevent multiple devices
			if ( add_user_meta( $current_user->ID, "mfth", $hashed, true )
			     && add_user_meta( $current_user->ID, "uuid", $uuid, true )
			     && add_user_meta( $current_user->ID, "secret", $encrypted, true )
			) {
				die( "New user successfully added!" );
			} else {
				die( "No success." );
			}

		} elseif ( isset( $_GET['qrHash'] ) ) {
			// user logs in via standard qr code reader and web browser
			if ( isset( $_GET['reject'] ) ) { ?>
				<h1>The requester will not be logged in.</h1>
			<?php
			} else {
				$qrHash = preg_replace( "/[^0-9a-zA-Z ]/", "", $_GET['qrHash'] );
				global $wpdb;
				$table_name  = $wpdb->base_prefix . $this->tbl_name;
				$qrUserLogin = $wpdb->get_results( $wpdb->prepare( "SELECT uIP FROM $table_name WHERE hash = %s", $qrHash ) ); ?>
				<h1>A login request for<br>
					<?php bloginfo( 'url' ); ?><br>
					has been made from<br>
					<?php echo $qrUserLogin[0]->uIP; ?><br>
					are you sure that you want to log in?</h1>
				<form action="" method="get">
					<input type="hidden" name="page" value="qr-login">
					<input type="hidden" name="qrHash" value="<?php echo $qrHash; ?>">
					<input type="hidden" name="QRnonceAdmin" value="<?php echo wp_create_nonce( 'QRnonceAdmin' ); ?>">
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
		} else { ?>
			<div class="wrap">
				<?php
				if ( current_user_can( 'manage_options' ) ) : ?>
					<h2>Unlock Digital</h2>
					<?php
					if ( isset( $_POST['disconnect'] ) ) {
						$user_to_delete = get_user_by( 'login', $_POST['disconnect'] );
						if ( isset( $user_to_delete->data->ID ) ) {
							delete_user_meta( $user_to_delete->data->ID, 'uuid' );
							delete_user_meta( $user_to_delete->data->ID, 'mfth' );
							delete_user_meta( $user_to_delete->data->ID, 'secret' );
						}
					}
					global $wpdb;
					$table_name = $wpdb->base_prefix . $this->tbl_name;
                    $site_url = preg_replace('#^(http|https)://#', '', get_bloginfo('url'));
                    $query = $wpdb->prepare("SELECT uname, uIP, timestamp FROM $table_name WHERE hash = 'used' AND site = '%s' ORDER BY uname,timestamp DESC", $site_url );
					$logResults = $wpdb->get_results( $query );
					$logs       = array();
					foreach ( $logResults as $i => $log ) {
						$logs[ $log->uname ][] = array( "timestamp" => $log->timestamp, "uIP" => $log->uIP );
					}

					if ( count( $logs ) > 0 ) : ?>
						<div class="tablenav top"></div>
						<table class="wp-list-table widefat fixed striped users">
							<thead>
							<tr>
								<th>Username</th>
								<th>Last login</th>
								<th>Logs</th>
								<th>Disconnect</th>
							</tr>
							</thead>
							<tbody>
							<?php foreach ( $logs as $uname => $ulog ) : ?>
								<tr>
									<td><?php echo $uname; ?></td>
									<td><?php echo $logs[ $uname ][0]["timestamp"]; ?></td>
									<td class="logs">
										<a class="viewLogs">View</a>
										<table class="theLogs">
											<tr>
												<thead>
												<th>Timestamp</th>
												<th>IP</th>
												</thead>
											</tr>
											<?php foreach ( $ulog as $log ): ?>
												<tr>
													<td><?php echo $log["timestamp"]; ?></td>
													<td><?php echo $log["uIP"]; ?></td>
												</tr>
											<?php endforeach; ?>
										</table>
									</td>
									<td>
										<?php $user = get_user_by( 'login', $uname );
										$uuid       = get_user_meta( $user->data->ID, 'uuid', true );
										if ( $user->data->user_login == $uname && get_user_meta( $user->data->ID, 'uuid', true ) ) : ?>
											<form method="post" action="">
												<input type="hidden" name="disconnect" value="<?php echo $uname; ?>">
												<input class="submitLink" type="submit" value="Disconnect user">
											</form>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php
					else:
						echo 'No users found.';
					endif;

				else: ?>
					<h2>You need to be an administrator to access this page.</h2>
				<?php
				endif; ?>
			</div>
		<?php
		}
	}

	/**
	 * Every three minutes deletes all rows that haven't been used yet
	 *
	 */
	public function qr_housecleaning() {
		global $wpdb;
		$table_name = $wpdb->base_prefix . $this->tbl_name;
		$mylink     = $wpdb->get_results( "DELETE FROM $table_name WHERE hash NOT IN ('used') AND TIMESTAMPDIFF(MINUTE, timestamp, UTC_TIMESTAMP()) > 3" );
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
		$qrLogin_db_version = "1.3.6";
		global $wpdb;
		$table_name = $wpdb->base_prefix . $this->tbl_name;
		$sql        = "CREATE TABLE $table_name (
          id mediumint(9) NOT NULL AUTO_INCREMENT,
          timestamp datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
          hash text NOT NULL,
          uname varchar(60) NOT NULL,
          uIP VARCHAR(55) DEFAULT '' NOT NULL,
          site VARCHAR(255) DEFAULT '' NOT NULL,
          UNIQUE KEY id (id)
        );";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );

		update_option( "qrLogin_db_version", $qrLogin_db_version );

	}

	/**
	 * creates 3 minute cron job
	 *
	 */
	private function qr_cron_activate() {
		wp_schedule_event( time(), 'threeMin', 'qr_three_clean' );
	}

	/**
	 * Clears cron and extra hashes on deactivation, keeps user login log
	 *
	 */
	public function qr_cron_deactivate() {
		wp_clear_scheduled_hook( 'qr_three_clean' );
		global $wpdb;
		$table_name = $wpdb->base_prefix . $this->tbl_name;
		$mylink     = $wpdb->get_results( "DELETE FROM $table_name WHERE hash NOT IN ('used')" );
	}

	/**
	 * define cron schedlue for cleaning old qrcode hashses
	 *
	 */
	public function newSchedules( $schedules ) { // Creates new
		$schedules['threeMin'] = array( 'interval' => 180, 'display' => __( 'Once Every 3 Minutes' ) );

		return $schedules;
	}

}
