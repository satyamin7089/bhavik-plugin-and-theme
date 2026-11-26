<?php
/**
 * Anti-spam and Request Security Manager for job applications.
 *
 * @package ASRS\RecruitEngine\Security
 */

declare(strict_types=1);

namespace ASRS\RecruitEngine\Security;

use ASRS\RecruitEngine\Core\Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SecurityManager {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @param array<string, mixed> $post
	 * @return true|WP_Error
	 */
	public function verify_application_request( array $post ) {
		$nonce = sanitize_text_field( (string) ( $post['asrs_recruit_nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'asrs_recruit_apply' ) ) {
			return new WP_Error( 'invalid_nonce', __( 'Security verification expired. Please refresh and try again.', 'asrs-recruit-engine' ) );
		}

		// Honeypot check.
		if ( ! empty( $post['asrs_website_hp'] ) ) {
			return new WP_Error( 'spam_detected', __( 'Spam check failed.', 'asrs-recruit-engine' ) );
		}

		// Minimum human dwell time check (2 seconds).
		$rendered_at = (int) ( $post['asrs_rendered_at'] ?? 0 );
		if ( $rendered_at > 0 && ( time() - $rendered_at ) < 2 ) {
			return new WP_Error( 'too_fast', __( 'Please wait a moment before submitting your application.', 'asrs-recruit-engine' ) );
		}

		// Optional Cloudflare Turnstile verification.
		if ( $this->settings->is_turnstile_enabled() ) {
			$token  = sanitize_text_field( (string) ( $post['cf-turnstile-response'] ?? '' ) );
			$secret = (string) $this->settings->get( 'turnstile_secret_key', '' );
			if ( '' === $token ) {
				return new WP_Error( 'turnstile_missing', __( 'Please complete the human verification challenge.', 'asrs-recruit-engine' ) );
			}

			$res = wp_remote_post(
				'https://challenges.cloudflare.com/turnstile/v0/siteverify',
				array(
					'timeout' => 10,
					'body'    => array(
						'secret'   => $secret,
						'response' => $token,
						'remoteip' => $this->get_client_ip(),
					),
				)
			);

			if ( ! is_wp_error( $res ) ) {
				$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
				if ( empty( $json['success'] ) ) {
					return new WP_Error( 'turnstile_failed', __( 'Human verification failed. Please try again.', 'asrs-recruit-engine' ) );
				}
			}
		}

		return true;
	}

	public function get_client_ip(): string {
		$ip = sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
