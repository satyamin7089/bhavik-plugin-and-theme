<?php
/**
 * High-level Zoho CRM API v7 service: org validation, module discovery, field metadata, and record insertion.
 *
 * @package ASRS\FormsEngine\Services\Zoho
 */

declare(strict_types=1);

namespace ASRS\FormsEngine\Services\Zoho;

use ASRS\FormsEngine\Core\Settings;
use ASRS\FormsEngine\Forms\FormInterface;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZohoCrmService {

	private const OPT_ORG_INFO     = 'asrs_forms_zoho_org_info';
	private const OPT_MODULES      = 'asrs_forms_zoho_modules';
	private const OPT_FIELDS_CACHE = 'asrs_forms_zoho_fields_cache';

	private ZohoClient $client;
	private ZohoAuthService $auth;
	private Settings $settings;

	public function __construct( ZohoClient $client, ZohoAuthService $auth, Settings $settings ) {
		$this->client   = $client;
		$this->auth     = $auth;
		$this->settings = $settings;
	}

	/**
	 * Test Zoho CRM connection using GET /crm/v7/org.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function test_connection() {
		// Force a fresh token check to verify OAuth health.
		$token = $this->auth->get_access_token( true );
		if ( is_wp_error( $token ) ) {
			$this->settings->update_zoho_health( 'failed', $token->get_error_message() );
			return $token;
		}

		$result = $this->client->request(
			'GET',
			'/org',
			null,
			array( 'action' => 'test_connection' )
		);

		if ( is_wp_error( $result ) ) {
			$this->settings->update_zoho_health( 'failed', $result->get_error_message() );
			return $result;
		}

		$org = $result['body']['org'][0] ?? array();
		if ( ! is_array( $org ) || empty( $org ) ) {
			$err = __( 'Connected to Zoho, but organization metadata was empty.', 'asrs-forms-engine' );
			$this->settings->update_zoho_health( 'failed', $err );
			return new WP_Error( 'zoho_empty_org', $err );
		}

		$org_summary = array(
			'id'           => (string) ( $org['id'] ?? '' ),
			'zgid'         => (string) ( $org['zgid'] ?? '' ),
			'company_name' => (string) ( $org['company_name'] ?? '' ),
			'primary_email'=> (string) ( $org['primary_email'] ?? '' ),
			'type'         => (string) ( $org['type'] ?? 'production' ),
			'country_code' => (string) ( $org['country_code'] ?? '' ),
			'time_zone'    => (string) ( $org['time_zone'] ?? '' ),
			'currency'     => (string) ( $org['iso_code'] ?? $org['currency'] ?? '' ),
			'synced_at'    => current_time( 'mysql', true ),
		);

		update_option( self::OPT_ORG_INFO, $org_summary, false );
		$this->settings->update_zoho_health( 'connected', '' );

		return $org_summary;
	}

	/**
	 * Discover all API-supported CRM modules via GET /crm/v7/settings/modules.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function discover_modules() {
		$result = $this->client->request(
			'GET',
			'/settings/modules',
			null,
			array( 'action' => 'discover_modules' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$raw_modules = $result['body']['modules'] ?? array();
		$modules     = array();

		if ( is_array( $raw_modules ) ) {
			foreach ( $raw_modules as $mod ) {
				if ( empty( $mod['api_supported'] ) || empty( $mod['api_name'] ) ) {
					continue;
				}
				if ( empty( $mod['creatable'] ) ) {
					continue;
				}
				$modules[] = array(
					'api_name'       => (string) $mod['api_name'],
					'module_name'    => (string) ( $mod['module_name'] ?? $mod['api_name'] ),
					'singular_label' => (string) ( $mod['singular_label'] ?? $mod['api_name'] ),
					'plural_label'   => (string) ( $mod['plural_label'] ?? $mod['api_name'] ),
					'generated_type' => (string) ( $mod['generated_type'] ?? 'default' ),
				);
			}
		}

		usort(
			$modules,
			static fn( array $a, array $b ): int => strcmp( $a['plural_label'], $b['plural_label'] )
		);

		update_option( self::OPT_MODULES, $modules, false );

		return $modules;
	}

	/**
	 * Fetch field metadata for a Zoho CRM module via GET /crm/v7/settings/fields?module={module}.
	 *
	 * @param string $module        Zoho module API name.
	 * @param bool   $force_refresh Bypass local cache.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function get_module_fields( string $module, bool $force_refresh = false ) {
		$module = sanitize_text_field( $module );
		if ( '' === $module ) {
			return new WP_Error( 'zoho_invalid_module', __( 'Module API name is required.', 'asrs-forms-engine' ) );
		}

		$cache = get_option( self::OPT_FIELDS_CACHE, array() );
		if ( ! is_array( $cache ) ) {
			$cache = array();
		}

		if ( ! $force_refresh && ! empty( $cache[ $module ]['fields'] ) ) {
			return $cache[ $module ]['fields'];
		}

		$result = $this->client->request(
			'GET',
			'/settings/fields?module=' . rawurlencode( $module ),
			null,
			array( 'action' => 'fetch_module_fields_' . $module )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$raw_fields = $result['body']['fields'] ?? array();
		$fields     = array();

		if ( is_array( $raw_fields ) ) {
			foreach ( $raw_fields as $field ) {
				if ( empty( $field['api_name'] ) ) {
					continue;
				}
				// Skip read-only system fields that cannot be created via API.
				if ( isset( $field['operation_type']['api_create'] ) && false === $field['operation_type']['api_create'] ) {
					continue;
				}

				$fields[] = array(
					'api_name'         => (string) $field['api_name'],
					'field_label'      => (string) ( $field['field_label'] ?? $field['api_name'] ),
					'data_type'        => (string) ( $field['data_type'] ?? 'text' ),
					'system_mandatory' => ! empty( $field['system_mandatory'] ),
					'custom_field'     => ! empty( $field['custom_field'] ),
					'length'           => isset( $field['length'] ) ? (int) $field['length'] : null,
				);
			}
		}

		usort(
			$fields,
			static function ( array $a, array $b ): int {
				if ( $a['system_mandatory'] !== $b['system_mandatory'] ) {
					return $a['system_mandatory'] ? -1 : 1;
				}
				return strcmp( $a['field_label'], $b['field_label'] );
			}
		);

		$cache[ $module ] = array(
			'synced_at' => current_time( 'mysql', true ),
			'fields'    => $fields,
		);

		update_option( self::OPT_FIELDS_CACHE, $cache, false );

		return $fields;
	}

	/**
	 * Insert a record into a Zoho CRM module via POST /crm/v7/{module}.
	 *
	 * @param string               $module           Zoho module API name (e.g. Leads).
	 * @param array<string, mixed> $record_data      Associative map of Zoho field API names => values.
	 * @param bool                 $trigger_workflow Trigger Zoho workflow/approval/blueprint rules.
	 * @param int|null             $submission_id    Linked WP submission ID for logging.
	 * @param string               $action_label     Log action identifier.
	 * @return array{record_id: string, record_url: string, response: array<string, mixed>}|WP_Error
	 */
	public function insert_record(
		string $module,
		array $record_data,
		bool $trigger_workflow = true,
		?int $submission_id = null,
		string $action_label = 'insert_record'
	) {
		$module = sanitize_text_field( $module );
		if ( '' === $module ) {
			return new WP_Error( 'zoho_missing_module', __( 'Target Zoho CRM module is empty.', 'asrs-forms-engine' ) );
		}

		if ( empty( $record_data ) ) {
			return new WP_Error( 'zoho_empty_record', __( 'No mapped fields available to send to Zoho CRM.', 'asrs-forms-engine' ) );
		}

		$payload = array(
			'data' => array( $record_data ),
		);

		if ( $trigger_workflow ) {
			$payload['trigger'] = array( 'approval', 'workflow', 'blueprint' );
		}

		$result = $this->client->request(
			'POST',
			'/' . rawurlencode( $module ),
			$payload,
			array(
				'action'        => $action_label,
				'submission_id' => $submission_id,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$item = $result['body']['data'][0] ?? null;
		if ( ! is_array( $item ) || 'SUCCESS' !== strtoupper( (string) ( $item['code'] ?? '' ) ) ) {
			$err_code = is_array( $item ) ? (string) ( $item['code'] ?? 'ZOHO_ERROR' ) : 'ZOHO_ERROR';
			$err_msg  = is_array( $item ) ? (string) ( $item['message'] ?? __( 'Unknown Zoho record error.', 'asrs-forms-engine' ) ) : __( 'Invalid Zoho response.', 'asrs-forms-engine' );

			if ( is_array( $item ) && ! empty( $item['details'] ) ) {
				$err_msg .= ' (' . wp_json_encode( $item['details'] ) . ')';
			}

			return new WP_Error( 'zoho_record_rejected', '[' . $err_code . '] ' . $err_msg, $result['body'] );
		}

		$record_id = (string) ( $item['details']['id'] ?? '' );
		$cfg       = $this->settings->get_zoho_settings();
		$record_url = '' !== $record_id
			? ZohoDataCenter::build_record_url( (string) $cfg['data_center'], $module, $record_id )
			: '';

		return array(
			'record_id'  => $record_id,
			'record_url' => $record_url,
			'response'   => $result['body'],
		);
	}

	/**
	 * Create a diagnostic test record in Zoho CRM from admin settings.
	 *
	 * @param string $module Target Zoho module.
	 * @return array{record_id: string, record_url: string, response: array<string, mixed>}|WP_Error
	 */
	public function create_test_record( string $module = 'Leads' ) {
		$site_name = get_bloginfo( 'name' );
		$timestamp = gmdate( 'Y-m-d H:i:s' ) . ' UTC';

		switch ( $module ) {
			case 'Contacts':
				$data = array(
					'First_Name'  => 'ASRS',
					'Last_Name'   => 'Integration Test',
					'Email'       => 'asrs-test+' . time() . '@example.com',
					'Description' => sprintf( 'ASRS Forms Engine test record created from %s at %s', $site_name, $timestamp ),
				);
				break;

			case 'Accounts':
				$data = array(
					'Account_Name' => 'ASRS Integration Test Account ' . gmdate( 'His' ),
					'Description'  => sprintf( 'ASRS Forms Engine test record created from %s at %s', $site_name, $timestamp ),
				);
				break;

			case 'Leads':
			default:
				$data = array(
					'First_Name'  => 'ASRS',
					'Last_Name'   => 'Test Lead ' . gmdate( 'His' ),
					'Company'     => $site_name ?: 'ASRS Forms Test',
					'Email'       => 'asrs-test+' . time() . '@example.com',
					'Lead_Source' => 'Product enquiry',
					'Description' => sprintf( 'ASRS Forms Engine diagnostic test record generated at %s.', $timestamp ),
				);
				break;
		}

		return $this->insert_record( $module, $data, false, null, 'create_test_record' );
	}

	/**
	 * Build Zoho CRM record payload for a form submission using mapping configuration.
	 *
	 * @param FormInterface        $form         Form definition.
	 * @param array<string, mixed> $data         Sanitized form submission data.
	 * @param array<string, mixed> $mapping      Effective form mapping.
	 * @param int                  $submission_id Local submission ID.
	 * @return array<string, mixed>
	 */
	public function build_mapped_payload(
		FormInterface $form,
		array $data,
		array $mapping,
		int $submission_id
	): array {
		$record = array();

		// Apply static default values first (e.g. Lead_Status).
		if ( ! empty( $mapping['static_values'] ) && is_array( $mapping['static_values'] ) ) {
			foreach ( $mapping['static_values'] as $zoho_field => $static_val ) {
				if ( '' !== (string) $zoho_field && '' !== (string) $static_val ) {
					$record[ (string) $zoho_field ] = $static_val;
				}
			}
		}

		// Map dynamic form field values.
		$field_map = is_array( $mapping['field_map'] ?? null ) ? $mapping['field_map'] : array();
		foreach ( $field_map as $form_field => $zoho_field ) {
			$zoho_field = (string) $zoho_field;
			if ( '' === $zoho_field || ! array_key_exists( $form_field, $data ) ) {
				continue;
			}

			$val = $data[ $form_field ];
			if ( is_array( $val ) ) {
				$val = implode( ', ', $val );
			}

			if ( '' !== (string) $val ) {
				$record[ $zoho_field ] = $val;
			}
		}

		// Ensure Leads/Contacts Last_Name fallback if full_name was mapped to Last_Name or missing Company.
		if ( 'Leads' === (string) ( $mapping['module'] ?? '' ) ) {
			if ( empty( $record['Last_Name'] ) && ! empty( $data['full_name'] ) ) {
				$record['Last_Name'] = (string) $data['full_name'];
			}
			if ( empty( $record['Company'] ) ) {
				$record['Company'] = ! empty( $data['company_name'] ) ? (string) $data['company_name'] : 'Individual / Not Specified';
			}
		}

		/**
		 * Allow developers to customize the Zoho CRM payload per form or submission.
		 *
		 * @param array<string, mixed> $record        Zoho record payload.
		 * @param FormInterface        $form          Form instance.
		 * @param array<string, mixed> $data          Submission data.
		 * @param array<string, mixed> $mapping       Form mapping config.
		 * @param int                  $submission_id Local submission ID.
		 */
		return apply_filters( 'asrs_forms_zoho_payload', $record, $form, $data, $mapping, $submission_id );
	}

	/**
	 * Return cached Zoho organization summary.
	 *
	 * @return array<string, mixed>
	 */
	public function get_cached_org_info(): array {
		$info = get_option( self::OPT_ORG_INFO, array() );
		return is_array( $info ) ? $info : array();
	}

	/**
	 * Return cached Zoho CRM modules list.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_cached_modules(): array {
		$modules = get_option( self::OPT_MODULES, array() );
		return is_array( $modules ) ? $modules : array();
	}

	/**
	 * Return cached fields for all synced modules.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_cached_fields_map(): array {
		$cache = get_option( self::OPT_FIELDS_CACHE, array() );
		return is_array( $cache ) ? $cache : array();
	}
}
