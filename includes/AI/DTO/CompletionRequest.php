<?php
/**
 * Provider-agnostic completion request.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\AI\DTO;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable-by-convention request object. Contains no credentials.
 */
final class CompletionRequest {

	/**
	 * System instructions (trusted, written by the plugin).
	 *
	 * @var string
	 */
	public $system = '';

	/**
	 * User content (untrusted data wrapped by the prompt builder).
	 *
	 * @var string
	 */
	public $user = '';

	/**
	 * Purpose: outline, page, section or test.
	 *
	 * @var string
	 */
	public $purpose = 'page';

	/**
	 * Sampling temperature or null for provider default.
	 *
	 * @var float|null
	 */
	public $temperature = null;

	/**
	 * Maximum output tokens or null for provider default.
	 *
	 * @var int|null
	 */
	public $max_tokens = null;

	/**
	 * Ask the provider for a JSON object response.
	 *
	 * @var bool
	 */
	public $json = true;

	/**
	 * Constructor.
	 *
	 * @param string $system  System instructions.
	 * @param string $user    User content.
	 * @param string $purpose Purpose.
	 */
	public function __construct( $system, $user, $purpose = 'page' ) {
		$this->system  = (string) $system;
		$this->user    = (string) $user;
		$this->purpose = sanitize_key( $purpose );
	}

	/**
	 * Safe summary for hooks and logs (lengths only, never content or secrets).
	 *
	 * @return array<string,mixed>
	 */
	public function summary() {
		return array(
			'purpose'      => $this->purpose,
			'system_chars' => strlen( $this->system ),
			'user_chars'   => strlen( $this->user ),
			'temperature'  => $this->temperature,
			'max_tokens'   => $this->max_tokens,
			'json'         => $this->json,
		);
	}
}
