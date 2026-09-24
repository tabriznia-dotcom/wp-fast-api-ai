<?php
/**
 * Provider-agnostic completion response.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\AI\DTO;

defined( 'ABSPATH' ) || exit;

/**
 * Model output plus usage metadata. The text is untrusted.
 */
final class CompletionResponse {

	/**
	 * Generated text (untrusted).
	 *
	 * @var string
	 */
	public $text;

	/**
	 * Model id reported by the provider.
	 *
	 * @var string
	 */
	public $model;

	/**
	 * Prompt tokens.
	 *
	 * @var int
	 */
	public $tokens_in;

	/**
	 * Completion tokens.
	 *
	 * @var int
	 */
	public $tokens_out;

	/**
	 * Finish reason (stop, length, ...).
	 *
	 * @var string
	 */
	public $finish_reason;

	/**
	 * Constructor.
	 *
	 * @param string $text          Text.
	 * @param string $model         Model.
	 * @param int    $tokens_in     Prompt tokens.
	 * @param int    $tokens_out    Completion tokens.
	 * @param string $finish_reason Finish reason.
	 */
	public function __construct( $text, $model = '', $tokens_in = 0, $tokens_out = 0, $finish_reason = '' ) {
		$this->text          = (string) $text;
		$this->model         = sanitize_text_field( (string) $model );
		$this->tokens_in     = max( 0, (int) $tokens_in );
		$this->tokens_out    = max( 0, (int) $tokens_out );
		$this->finish_reason = sanitize_key( (string) $finish_reason );
	}
}
