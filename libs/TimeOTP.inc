<?php

class TimeOTP {
	private static $base32Map = 'abcdefghijklmnopqrstuvwxyz234567';

	private function base32Decode( $b32 ) {
		if ( preg_match( '/[^a-z2-7]/i', strtolower( $secret ) ) === 1 ) {
			error_log( 'TimeOTP::base32Decode -> that\'s not base32!' );

			return false;
		} else {
			$tmp = '';

			foreach ( str_split( strtolower( $b32 ) ) as $c ) {
				if ( false === ( $v = strpos( self::$base32Map, $c ) ) ) {
					$v = 0;
				}
				$tmp .= sprintf( '%05b', $v );
			}
			$args = array_map( 'bindec', str_split( $tmp, 8 ) );
			array_unshift( $args, 'C*' );

			return rtrim( call_user_func_array( 'pack', $args ), "\0" );
		}
	}

	public static function calcOTP( $secret, $length = 6, $expires = 30 ) {
		if ( strlen( $secret ) < 16 || strlen( $secret ) % 8 != 0 // Length of secret must be at least 16 characters and a multiple of 8.
		     || preg_match( '/[^a-z2-7]/i', strtolower( $secret ) ) === 1 // Secret contains non-base32 characters.
		     || $length < 6 || $length > 8
		) { // $length length must be 6, 7 or, 8.
			error_log( 'TimeOTP::getOTP -> OTP out of sync' );

			return false;
		} else {
			// Magic!!
			$seed = self::base32Decode( $secret );
			$time = str_pad( pack( 'N', intval( time() / $expires ) ), 8, "\x00", STR_PAD_LEFT );
			$hash = hash_hmac( 'sha1', $time, $seed, false );
			$otp  = ( hexdec( substr( $hash, hexdec( $hash[39] ) * 2, 8 ) ) & 0x7fffffff ) % pow( 10, $length );

			// return the otp
			return sprintf( "%'0{$length}u", $otp );
		}
	}

	public static function generateSecret( $length = 32 ) {
		if ( $length < 16 || $length % 8 != 0 ) {
			error_log( 'TimeOTP::generateSecret -> length must be at least 16 characters long and a multiple of 8.' );

			return false;
		} else {
			$secret = "";
			while ( $length -- ) {
				$usec = gettimeofday();
				$usec = $usec['usec'] % 11;

				// let's make this REALLY secure
				while ( $usec -- ) {
					mt_rand();
				}
				$secret .= self::$base32Map[ mt_rand( 0, 31 ) ];
			}

			return strtoupper( $secret );
		}
	}

}

