<?php
/**
 * Security helpers: SSRF, links, secrets, redaction, kses.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Tests\Unit;

use AIPageDesigner\Security\Kses;
use AIPageDesigner\Security\Redactor;
use AIPageDesigner\Security\Secrets;
use AIPageDesigner\Security\UrlValidator;
use AIPageDesigner\Tests\TestCase;

/**
 * @covers \AIPageDesigner\Security\UrlValidator
 */
class SecurityTest extends TestCase {

	/**
	 * @dataProvider blocked_endpoints
	 *
	 * @param string $url URL.
	 */
	public function test_ssrf_endpoints_are_blocked( $url ) {
		$this->assertWPError( UrlValidator::validate_endpoint( $url ), $url );
	}

	/**
	 * Blocked URLs.
	 *
	 * @return array
	 */
	public function blocked_endpoints() {
		return array(
			array( 'http://api.example.com/v1' ),
			array( 'https://localhost/v1' ),
			array( 'https://127.0.0.1/v1' ),
			array( 'https://10.0.0.1/v1' ),
			array( 'https://192.168.1.10/v1' ),
			array( 'https://169.254.169.254/latest/meta-data' ),
			array( 'https://[::1]/v1' ),
			array( 'https://internal.example/v1' ),
			array( 'https://metadata.example/v1' ),
			array( 'https://user:pass@api.example.com/v1' ),
			array( 'https://api.example.com/v1?x=1' ),
			array( 'https://api.example.com/../v1' ),
			array( 'file:///etc/passwd' ),
			array( 'gopher://api.example.com' ),
			array( 'https://unresolvable.invalid/v1' ),
			array( '' ),
		);
	}

	public function test_public_https_endpoint_is_allowed() {
		$this->assertSame( 'https://api.example.com/v1', UrlValidator::validate_endpoint( 'https://API.example.com/v1/' ) );
	}

	public function test_local_endpoints_require_explicit_opt_in() {
		$this->assertWPError( UrlValidator::validate_endpoint( 'http://127.0.0.1:11434/v1' ) );
		add_filter( 'aipd_allow_local_endpoints', '__return_true' );
		$this->assertSame( 'http://127.0.0.1:11434/v1', UrlValidator::validate_endpoint( 'http://127.0.0.1:11434/v1' ) );
	}

	public function test_links_are_sanitized() {
		$this->assertSame( '#', UrlValidator::sanitize_link( 'javascript:alert(1)' ) );
		$this->assertSame( '#', UrlValidator::sanitize_link( 'JaVaScRiPt:alert(1)' ) );
		$this->assertSame( '#', UrlValidator::sanitize_link( 'data:text/html;base64,xx' ) );
		$this->assertSame( '#', UrlValidator::sanitize_link( 'vbscript:x' ) );
		$this->assertSame( '#', UrlValidator::sanitize_link( '//evil.example' ) );
		$this->assertSame( '#', UrlValidator::sanitize_link( '/a/../../b' ) );
		$this->assertSame( '#contact', UrlValidator::sanitize_link( '#contact' ) );
		$this->assertSame( 'mailto:a@example.com', UrlValidator::sanitize_link( 'mailto:a@example.com' ) );
		$this->assertSame( 'https://example.com/x', UrlValidator::sanitize_link( 'https://example.com/x' ) );
	}

	public function test_remote_image_urls_are_validated() {
		$this->assertWPError( UrlValidator::validate_remote_image( 'http://api.example.com/a.png' ) );
		$this->assertWPError( UrlValidator::validate_remote_image( 'https://internal.example/a.png' ) );
		$this->assertWPError( UrlValidator::validate_remote_image( 'https://api.example.com/a.php' ) );
		$this->assertSame( 'https://api.example.com/a.png', UrlValidator::validate_remote_image( 'https://api.example.com/a.png' ) );
	}

	public function test_secrets_round_trip_and_are_not_plaintext() {
		$secret = 'sk-live-THIS-IS-SECRET';
		$cipher = Secrets::encrypt( $secret );
		$this->assertStringStartsWith( Secrets::PREFIX, $cipher );
		$this->assertStringNotContainsString( 'SECRET', $cipher );
		$this->assertSame( $secret, Secrets::decrypt( $cipher ) );
		$this->assertNotSame( $cipher, Secrets::encrypt( $secret ), 'Random nonce per encryption' );
		$this->assertSame( '', Secrets::decrypt( 'aipd1:tampered' ) );
		$this->assertSame( '', Secrets::decrypt( 'plain' ) );
	}

	public function test_redactor_removes_secrets() {
		$out  = Redactor::redact(
			array(
				'api_key' => 'sk-abc',
				'nested'  => array( 'Authorization' => 'Bearer xyz' ),
				'msg'     => 'failed with key sk-proj-ABCDEFGH12345678 and Bearer abc.def',
			)
		);
		$json = wp_json_encode( $out );
		$this->assertStringNotContainsString( 'sk-abc', $json );
		$this->assertStringNotContainsString( 'xyz', $json );
		$this->assertStringNotContainsString( 'ABCDEFGH12345678', $json );
		$this->assertStringNotContainsString( 'abc.def', $json );
	}

	public function test_kses_inline_and_shortcodes() {
		$this->assertSame( 'a <strong>b</strong>', Kses::inline( 'a <strong>b</strong><script>x</script>' ) );
		$this->assertSame( '&#091;gallery&#093;', Kses::text( '[gallery]' ) );
		$this->assertStringNotContainsString( '[', Kses::page( '<p>[contact-form]</p>' ) );
		$this->assertStringNotContainsString( '<form', Kses::page( '<form action="x"><input></form>' ) );
		$this->assertStringNotContainsString( 'onload', Kses::page( '<div onload="x">a</div>' ) );
	}

	public function test_filters_cannot_reenable_dangerous_tags() {
		add_filter(
			'aipd_inline_allowed_html',
			static function ( $tags ) {
				$tags['script']       = array();
				$tags['a']['onclick'] = true;
				return $tags;
			}
		);
		$out = Kses::inline( '<script>x</script><a href="https://e.com" onclick="y">l</a>' );
		$this->assertStringNotContainsString( '<script', $out );
		$this->assertStringNotContainsString( 'onclick', $out );
	}
}
