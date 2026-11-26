<?php
/**
 * Google reCAPTCHA (v2 checkbox / v3 score) server-side verification service.
 *
 * Additive bot protection: runs alongside the existing honeypot, timing and
 * Cloudflare Turnstile checks and is only active when enabled with keys in
 * ASRS Forms → Settings.
 *
 * @package ASRS\FormsEngine\Security
 */

declare(strict_types=1);

namespace ASRS\FormsEngine\Security;

use ASRS\FormsEngine\Core\Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RecaptchaVerifier {

	private const VERIFY_ENDPOINT = 'https://www.google.com/recaptcha/api/siteverify';

	/** Action name sent with v3 tokens and checked on the server. */
	public const ACTION = 'asrs_form_submit';

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Check whether reCAPTCHA is configured and enabled.
	 */
	public function is_enabled(): bool {
		return (bool) $this->settings->get( 'recaptcha_enabled', false )
			&& '' !== $this->get_site_key()
			&& '' !== (string) $this->settings->get( 'recaptcha_secret_key', '' );
	}

	public function get_site_key(): string {
		return trim( (string) $this->settings->get( 'recaptcha_site_key', '' ) );
	}

	/**
	 * Configured reCAPTCHA version: 'v2' (checkbox) or 'v3' (invisible score).
	 */
	public function get_version(): string {
		return 'v3' === (string) $this->settings->get( 'recaptcha_version', 'v2' ) ? 'v3' : 'v2';
	}

	public function get_min_score(): float {
		$score = (float) $this->settings->get( 'recaptcha_min_score', 0.5 );
		return max( 0.0, min( 1.0, $score ) );
	}

	/**
	 * Google API script URL for the configured version.
	 */
	public function get_script_url(): string {
		if ( 'v3' === $this->get_version() ) {
			return add_query_arg( 'render', rawurlencode( $this->get_site_key() ), 'https://www.google.com/recaptcha/api.js' );
		}
		return 'https://www.google.com/recaptcha/api.js?render=explicit';
	}

	/**
	 * Verify a reCAPTCHA token against Google's siteverify API.
	 *
	 * @param string $token Token from the g-recaptcha-response field.
	 * @return true|WP_Error
	 */
	public function verify( string $token ) {
		if ( ! $this->is_enabled() ) {
			return true;
		}

		$token = trim( $token );
		if ( '' === $token ) {
			return new WP_Error(
				'asrs_recaptcha_missing',
				'v3' === $this->get_version()
					? __( 'Security verification could not be completed. Please refresh the page and try again.', 'asrs-forms-engine' )
					: __( 'Please confirm that you are not a robot.', 'asrs-forms-engine' )
			);
		}

		$body = array(
			'secret'   => (string) $this->settings->get( 'recaptcha_secret_key', '' ),
			'response' => $token,
		);

		$ip = Sanitizer::get_client_ip();
		if ( '' !== $ip ) {
			$body['remoteip'] = $ip;
		}

		$response = wp_remote_post(
			self::VERIFY_ENDPOINT,
			array(
				'timeout' => 10,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'asrs_recaptcha_unreachable',
				__( 'Unable to verify security challenge. Please try again.', 'asrs-forms-engine' )
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$payload     = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status_code || ! is_array( $payload ) || empty( $payload['success'] ) ) {
			return new WP_Error(
				'asrs_recaptcha_failed',
				__( 'Security verification failed. Please refresh and try again.', 'asrs-forms-engine' )
			);
		}

		if ( 'v3' === $this->get_version() ) {
			$score  = isset( $payload['score'] ) ? (float) $payload['score'] : 0.0;
			$action = isset( $payload['action'] ) ? (string) $payload['action'] : '';

			if ( '' !== $action && self::ACTION !== $action ) {
				return new WP_Error(
					'asrs_recaptcha_action',
					__( 'Security verification failed. Please refresh and try again.', 'asrs-forms-engine' )
				);
			}

			if ( $score < $this->get_min_score() ) {
				return new WP_Error(
					'asrs_recaptcha_low_score',
					__( 'Your submission was flagged by our spam protection. Please try again or contact us directly.', 'asrs-forms-engine' )
				);
			}
		}

		return true;
	}
}
