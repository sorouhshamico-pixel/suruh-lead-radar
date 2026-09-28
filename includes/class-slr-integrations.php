<?php
defined( 'ABSPATH' ) || exit;

/**
 * Server-side capture for the popular form plugins, so leads are recorded on any site
 * without touching the forms. Each adapter turns the plugin's submission into a list of
 * fields and hands it to SLR_Rest::record_lead().
 */
class SLR_Integrations {

	public static function init() {
		if ( ! SLR_Helpers::setting( 'capture_plugin_forms' ) ) {
			return;
		}
		add_action( 'wpcf7_before_send_mail', array( __CLASS__, 'cf7' ), 20, 1 );
		add_action( 'wpforms_process_complete', array( __CLASS__, 'wpforms' ), 20, 4 );
		add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'elementor' ), 20, 2 );
		add_action( 'gform_after_submission', array( __CLASS__, 'gravity' ), 20, 2 );
		add_action( 'fluentform/submission_inserted', array( __CLASS__, 'fluent' ), 20, 3 );
		add_action( 'ninja_forms_after_submission', array( __CLASS__, 'ninja' ), 20, 1 );
	}

	/** Names shown in the admin so the user knows which integrations are live. */
	public static function detected() {
		$list = array();
		if ( defined( 'WPCF7_VERSION' ) ) {
			$list[] = 'Contact Form 7';
		}
		if ( defined( 'WPFORMS_VERSION' ) ) {
			$list[] = 'WPForms';
		}
		if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			$list[] = 'Elementor Pro Forms';
		}
		if ( class_exists( 'GFForms' ) ) {
			$list[] = 'Gravity Forms';
		}
		if ( defined( 'FLUENTFORM' ) || defined( 'FLUENTFORM_VERSION' ) ) {
			$list[] = 'Fluent Forms';
		}
		if ( class_exists( 'Ninja_Forms' ) ) {
			$list[] = 'Ninja Forms';
		}
		return $list;
	}

	/* ------------------------------------------------------------ adapters */

	public static function cf7( $contact_form ) {
		self::guard(
			function () use ( $contact_form ) {
				if ( ! class_exists( 'WPCF7_Submission' ) ) {
					return;
				}
				$submission = WPCF7_Submission::get_instance();
				if ( ! $submission ) {
					return;
				}
				$posted = $submission->get_posted_data();
				$fields = array();
				foreach ( $contact_form->scan_form_tags() as $tag ) {
					if ( empty( $tag->name ) || ! isset( $posted[ $tag->name ] ) ) {
						continue;
					}
					$fields[] = array(
						'key'   => $tag->name,
						'label' => $tag->name,
						'type'  => $tag->basetype,
						'value' => $posted[ $tag->name ],
					);
				}
				self::record( 'CF7: ' . $contact_form->title(), $fields );
			}
		);
	}

	public static function wpforms( $fields, $entry, $form_data, $entry_id ) {
		self::guard(
			function () use ( $fields, $form_data ) {
				$out = array();
				foreach ( (array) $fields as $f ) {
					$out[] = array(
						'key'   => isset( $f['name'] ) ? $f['name'] : '',
						'label' => isset( $f['name'] ) ? $f['name'] : '',
						'type'  => isset( $f['type'] ) ? $f['type'] : '',
						'value' => isset( $f['value'] ) ? $f['value'] : '',
					);
				}
				$title = isset( $form_data['settings']['form_title'] ) ? $form_data['settings']['form_title'] : ( isset( $form_data['id'] ) ? '#' . $form_data['id'] : '' );
				self::record( 'WPForms: ' . $title, $out );
			}
		);
	}

	public static function elementor( $record, $handler ) {
		self::guard(
			function () use ( $record ) {
				$out = array();
				foreach ( (array) $record->get( 'fields' ) as $id => $f ) {
					$out[] = array(
						'key'   => isset( $f['id'] ) ? $f['id'] : $id,
						'label' => isset( $f['title'] ) ? $f['title'] : '',
						'type'  => isset( $f['type'] ) ? $f['type'] : '',
						'value' => isset( $f['value'] ) ? $f['value'] : '',
					);
				}
				self::record( 'Elementor: ' . $record->get_form_settings( 'form_name' ), $out );
			}
		);
	}

	public static function gravity( $entry, $form ) {
		self::guard(
			function () use ( $entry, $form ) {
				$out = array();
				foreach ( (array) $form['fields'] as $field ) {
					$value = method_exists( $field, 'get_value_export' ) ? $field->get_value_export( $entry ) : ( isset( $entry[ (string) $field->id ] ) ? $entry[ (string) $field->id ] : '' );
					$out[] = array(
						'key'   => (string) $field->id,
						'label' => (string) $field->label,
						'type'  => (string) $field->type,
						'value' => $value,
					);
				}
				self::record( 'Gravity: ' . ( isset( $form['title'] ) ? $form['title'] : '' ), $out );
			}
		);
	}

	public static function fluent( $entry_id, $form_data, $form ) {
		self::guard(
			function () use ( $form_data, $form ) {
				$out = array();
				foreach ( (array) $form_data as $key => $value ) {
					if ( 0 === strpos( (string) $key, '_' ) ) {
						continue; // nonce, honeypot and internal keys
					}
					$out[] = array(
						'key'   => $key,
						'label' => $key,
						'type'  => 'names' === $key ? 'name' : '',
						'value' => $value,
					);
				}
				self::record( 'Fluent: ' . ( is_object( $form ) && isset( $form->title ) ? $form->title : '' ), $out );
			}
		);
	}

	public static function ninja( $form_data ) {
		self::guard(
			function () use ( $form_data ) {
				$out = array();
				foreach ( (array) ( isset( $form_data['fields'] ) ? $form_data['fields'] : array() ) as $f ) {
					$out[] = array(
						'key'   => isset( $f['key'] ) ? $f['key'] : '',
						'label' => isset( $f['label'] ) ? $f['label'] : '',
						'type'  => isset( $f['type'] ) ? $f['type'] : '',
						'value' => isset( $f['value'] ) ? $f['value'] : '',
					);
				}
				$title = isset( $form_data['settings']['title'] ) ? $form_data['settings']['title'] : '';
				self::record( 'Ninja: ' . $title, $out );
			}
		);
	}

	/* ------------------------------------------------------------- mapping */

	/** A failing integration must never break the form plugin's own submission. */
	private static function guard( $fn ) {
		try {
			$fn();
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Lead Radar integration: ' . $e->getMessage() ); // phpcs:ignore
			}
		}
	}

	private static function flatten( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( ' ', array_filter( array_map( array( __CLASS__, 'flatten' ), $value ), 'strlen' ) );
		}
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/** Maps arbitrary form fields onto name / phone / email / service / message. */
	public static function map_fields( $fields ) {
		$out   = array();
		$texts = array();
		foreach ( $fields as $f ) {
			$value = self::flatten( $f['value'] );
			if ( '' === $value ) {
				continue;
			}
			$hay  = strtolower( $f['key'] . ' ' . $f['label'] );
			$type = strtolower( (string) $f['type'] );
			$role = '';

			if ( in_array( $type, array( 'tel', 'phone' ), true ) || preg_match( '/phone|mobile|tel|whats|جوال|هاتف|موبايل|واتس|رقم/u', $hay ) ) {
				$role = 'phone';
			} elseif ( 'email' === $type || preg_match( '/e-?mail|بريد|ايميل|إيميل/u', $hay ) || is_email( $value ) ) {
				$role = 'email';
			} elseif ( in_array( $type, array( 'name', 'first_name', 'names' ), true ) || preg_match( '/name|اسم/u', $hay ) ) {
				$role = 'name';
			} elseif ( in_array( $type, array( 'textarea' ), true ) || preg_match( '/message|details|comment|msg|رسالة|تفاصيل|ملاحظ|استفسار/u', $hay ) ) {
				$role = 'message';
			} elseif ( in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) || preg_match( '/service|subject|خدمة|موضوع|نوع/u', $hay ) ) {
				$role = 'service';
			} elseif ( in_array( $type, array( 'text', '' ), true ) ) {
				$texts[] = $value;
			}

			if ( $role && ! isset( $out[ $role ] ) ) {
				$out[ $role ] = $value;
			}
		}
		// Fall back to value shapes when labels gave nothing away.
		if ( ! isset( $out['phone'] ) ) {
			foreach ( $texts as $i => $t ) {
				if ( preg_match( '/^[+\d\s()\-٠-٩]{7,20}$/u', $t ) ) {
					$out['phone'] = $t;
					unset( $texts[ $i ] );
					break;
				}
			}
		}
		if ( ! isset( $out['name'] ) && $texts ) {
			$out['name'] = reset( $texts );
		}
		return $out;
	}

	private static function record( $form_id, $fields ) {
		$f = self::map_fields( $fields );
		if ( ! $f ) {
			return;
		}
		$f['form_id'] = $form_id;
		SLR_Rest::record_lead( SLR_Rest::server_context(), $f );
	}
}
