<?php
/**
 * E2E helpers (test environment only, never shipped).
 *
 * - Fakes the OpenAI-compatible API at https://api.example.com/v1 using the bundled templates.
 * - Forces RTL admin when the aipd_e2e_rtl cookie is set, to test RTL layouts without a language pack.
 *
 * @package AIPageDesigner
 */

add_filter(
	'aipd_resolve_host',
	static function ( $ips, $host ) {
		if ( 'opencode.ai' === $host ) {
			return array( '104.21.32.1' );
		}
		return 'api.example.com' === $host ? array( '93.184.216.34' ) : $ips;
	},
	10,
	2
);

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$opencode = 0 === strpos( $url, 'https://opencode.ai/zen/' );
		if ( 0 !== strpos( $url, 'https://api.example.com/v1' ) && ! $opencode ) {
			return $pre;
		}
		$reply = static function ( $data ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $data ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		if ( '/models' === substr( $url, -7 ) ) {
			return $reply( array( 'data' => array( array( 'id' => $opencode ? 'claude-sonnet-5' : 'e2e-model' ), array( 'id' => 'glm-5.3' ) ) ) );
		}
		$body = json_decode( $args['body'], true );
		// OpenCode Messages format (Anthropic): system is top level, auth in x-api-key.
		$is_messages = $opencode && '/messages' === substr( $url, -9 );
		if ( $is_messages && empty( $args['headers']['x-api-key'] ) ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'type' => 'error', 'error' => array( 'type' => 'AuthError', 'message' => 'Missing API key.' ) ) ),
				'response' => array( 'code' => 401, 'message' => 'Unauthorized' ),
				'cookies'  => array(),
				'filename' => null,
			);
		}
		$system = $is_messages ? $body['system'] : $body['messages'][0]['content'];
		$user   = $is_messages ? $body['messages'][0]['content'] : $body['messages'][1]['content'];
		$rtl    = false !== strpos( $user, '"language": "fa' );
		if ( false !== strpos( $system, 'propose the section structure' ) ) {
			$content = array(
				'sections' => array(
					array( 'id' => 'hero', 'type' => 'hero', 'label' => $rtl ? 'معرفی' : 'Introduction', 'purpose' => 'Headline, lead and main call to action.' ),
					array( 'id' => 'features', 'type' => 'features', 'label' => $rtl ? 'امکانات' : 'Features', 'purpose' => 'Three benefit cards.' ),
					array( 'id' => 'faq', 'type' => 'faq', 'label' => $rtl ? 'پرسش‌ها' : 'FAQ', 'purpose' => 'Common questions.' ),
					array( 'id' => 'contact', 'type' => 'cta', 'label' => $rtl ? 'اقدام' : 'Call to action', 'purpose' => 'Final call to action.' ),
				),
				'notes'    => 'E2E outline.',
			);
		} else {
			$content = json_decode( file_get_contents( WP_PLUGIN_DIR . '/ai-page-designer/templates/landing-page-' . ( $rtl ? 'fa' : 'en' ) . '.json' ), true ); // phpcs:ignore
			if ( false !== strpos( $system, 'rewrite exactly one section' ) ) {
				$content                                        = $content['sections'][0];
				$content['columns'][0]['components'][0]['text'] = $rtl ? 'عنوان بازنویسی‌شده' : 'Rewritten hero title';
			}
		}
		if ( $is_messages ) {
			return $reply(
				array(
					'model'       => $body['model'],
					'content'     => array( array( 'type' => 'text', 'text' => wp_json_encode( $content ) ) ),
					'stop_reason' => 'end_turn',
					'usage'       => array( 'input_tokens' => 10, 'output_tokens' => 20 ),
				)
			);
		}
		return $reply(
			array(
				'model'   => 'e2e-model',
				'choices' => array(
					array(
						'message'       => array(
							'role'    => 'assistant',
							'content' => wp_json_encode( $content ),
						),
						'finish_reason' => 'stop',
					),
				),
				'usage'   => array(
					'prompt_tokens'     => 10,
					'completion_tokens' => 20,
				),
			)
		);
	},
	10,
	3
);

add_action(
	'init',
	static function () {
		if ( ! empty( $_COOKIE['aipd_e2e_rtl'] ) ) { // phpcs:ignore
			// Same approach as the RTL Tester plugin: switch the locale and the style loader.
			$GLOBALS['wp_locale']->text_direction = 'rtl';
			wp_styles()->text_direction           = 'rtl';
		}
	}
);

// Repeated local E2E runs must not hit the per-user hourly limit.
add_filter(
	'aipd_rate_limit_per_hour',
	static function () {
		return 10000;
	}
);
