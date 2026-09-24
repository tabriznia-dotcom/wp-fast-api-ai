<?php
/**
 * Accessibility and consistency fixes applied after sanitization.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Enforces rules the JSON Schema cannot express:
 * - direction matches the content language,
 * - unique section ids,
 * - a single H1 and no skipped heading levels,
 * - readable color contrast (WCAG 2.2 AA, 4.5:1 for body text),
 * - text alternatives for meaningful images.
 */
final class Normalizer {

	/**
	 * Languages written right-to-left.
	 */
	const RTL_LANGUAGES = array( 'ar', 'fa', 'he', 'ur', 'ps', 'sd', 'ug', 'yi', 'ckb', 'dv', 'ku' );

	/**
	 * Repair warnings.
	 *
	 * @var string[]
	 */
	private $warnings = array();

	/**
	 * Normalizes a sanitized schema.
	 *
	 * @param array $schema Sanitized page schema.
	 * @return array{0:array,1:string[]}
	 */
	public function normalize( array $schema ) {
		$this->warnings = array();

		$schema = $this->fix_direction( $schema );
		$schema = $this->fix_tokens( $schema );
		$schema = $this->fix_section_ids( $schema );
		$schema = $this->fix_headings( $schema );
		$schema = $this->fix_images( $schema );

		return array( $schema, $this->warnings );
	}

	/**
	 * Whether a language code is RTL.
	 *
	 * @param string $language BCP 47 or WordPress locale.
	 * @return bool
	 */
	public static function is_rtl_language( $language ) {
		$primary = strtolower( (string) strtok( str_replace( '_', '-', (string) $language ), '-' ) );
		return in_array( $primary, self::RTL_LANGUAGES, true );
	}

	/**
	 * Direction follows the content language, not the admin language.
	 *
	 * @param array $schema Schema.
	 * @return array
	 */
	private function fix_direction( array $schema ) {
		$expected = self::is_rtl_language( $schema['meta']['language'] ) ? 'rtl' : 'ltr';
		if ( $schema['meta']['direction'] !== $expected ) {
			$this->warnings[]            = sprintf( 'meta.direction: changed to %s to match the content language', $expected );
			$schema['meta']['direction'] = $expected;
		}
		return $schema;
	}

	/**
	 * Fills optional colors and repairs low-contrast pairs.
	 *
	 * @param array $schema Schema.
	 * @return array
	 */
	private function fix_tokens( array $schema ) {
		$preset = DesignTokens::preset( $schema['meta']['style'] );
		$colors = array_merge( $preset['colors'], array_filter( $schema['design_tokens']['colors'] ) );

		$pairs = array(
			'text'       => 'background',
			'text_muted' => 'background',
			'on_primary' => 'primary',
		);
		foreach ( $pairs as $foreground => $background ) {
			$fixed = DesignTokens::readable_on( $colors[ $foreground ], $colors[ $background ] );
			if ( $fixed !== $colors[ $foreground ] ) {
				$this->warnings[]      = sprintf( 'design_tokens.colors.%s: adjusted for WCAG AA contrast', $foreground );
				$colors[ $foreground ] = $fixed;
			}
		}

		// Text must also stay readable on the surface background.
		if ( DesignTokens::contrast( $colors['text'], $colors['surface'] ) < 4.5 ) {
			$this->warnings[]  = 'design_tokens.colors.surface: adjusted for WCAG AA contrast';
			$colors['surface'] = $colors['background'];
		}
		if ( DesignTokens::contrast( $colors['text_muted'], $colors['surface'] ) < 4.5 ) {
			$colors['text_muted'] = $colors['text'];
		}

		// Accent is used as a section background with on_primary text.
		if ( DesignTokens::contrast( $colors['on_primary'], $colors['accent'] ) < 4.5 ) {
			$this->warnings[] = 'design_tokens.colors.accent: adjusted for WCAG AA contrast';
			$colors['accent'] = $colors['primary'];
		}

		$schema['design_tokens']['colors'] = $colors;
		return $schema;
	}

	/**
	 * Guarantees unique, non-empty section ids.
	 *
	 * @param array $schema Schema.
	 * @return array
	 */
	private function fix_section_ids( array $schema ) {
		$seen = array();
		foreach ( $schema['sections'] as $index => $section ) {
			$id   = $section['id'];
			$base = $id;
			$n    = 2;
			while ( isset( $seen[ $id ] ) ) {
				$id = substr( $base, 0, 36 ) . '-' . $n;
				++$n;
			}
			if ( $id !== $section['id'] ) {
				$this->warnings[] = sprintf( 'sections[%d].id: renamed to "%s" to keep ids unique', $index, $id );
			}
			$seen[ $id ]                        = true;
			$schema['sections'][ $index ]['id'] = $id;
		}
		return $schema;
	}

	/**
	 * One H1 (the first heading on the page) and no skipped levels.
	 *
	 * @param array $schema Schema.
	 * @return array
	 */
	private function fix_headings( array $schema ) {
		$previous = 0;
		foreach ( $schema['sections'] as $s => $section ) {
			foreach ( self::component_paths( $section ) as $path ) {
				$component = self::get_path( $schema['sections'][ $s ], $path );
				if ( 'heading' !== $component['type'] ) {
					continue;
				}
				$level = (int) $component['level'];
				if ( 0 === $previous ) {
					$fixed = 1;
				} elseif ( 1 === $level ) {
					$fixed = 2;
				} else {
					$fixed = min( $level, $previous + 1 );
				}
				if ( $fixed !== $level ) {
					$this->warnings[]   = sprintf( 'sections[%d] heading "%s": level %d changed to %d', $s, wp_strip_all_tags( $component['text'] ), $level, $fixed );
					$component['level'] = $fixed;
					self::set_path( $schema['sections'][ $s ], $path, $component );
				}
				$previous = $fixed;
			}
		}
		return $schema;
	}

	/**
	 * Paths of all components in reading order (intro first, then columns).
	 *
	 * @param array $section Section.
	 * @return array[] Each path is array( 'intro', i ) or array( 'columns', c, i ).
	 */
	public static function component_paths( array $section ) {
		$paths = array();
		foreach ( isset( $section['intro'] ) ? array_keys( $section['intro'] ) : array() as $i ) {
			$paths[] = array( 'intro', $i );
		}
		foreach ( $section['columns'] as $c => $column ) {
			foreach ( array_keys( $column['components'] ) as $i ) {
				$paths[] = array( 'columns', $c, $i );
			}
		}
		return $paths;
	}

	/**
	 * Reads a component by path.
	 *
	 * @param array $section Section.
	 * @param array $path    Path.
	 * @return array
	 */
	private static function get_path( array $section, array $path ) {
		return 'intro' === $path[0] ? $section['intro'][ $path[1] ] : $section['columns'][ $path[1] ]['components'][ $path[2] ];
	}

	/**
	 * Writes a component by path.
	 *
	 * @param array $section   Section (by reference).
	 * @param array $path      Path.
	 * @param array $component Component.
	 * @return void
	 */
	private static function set_path( array &$section, array $path, array $component ) {
		if ( 'intro' === $path[0] ) {
			$section['intro'][ $path[1] ] = $component;
		} else {
			$section['columns'][ $path[1] ]['components'][ $path[2] ] = $component;
		}
	}

	/**
	 * Meaningful images need alt text; decorative images get an empty alt.
	 *
	 * @param array $schema Schema.
	 * @return array
	 */
	private function fix_images( array $schema ) {
		foreach ( $schema['sections'] as $s => $section ) {
			foreach ( self::component_paths( $section ) as $path ) {
				$image = self::get_path( $section, $path );
				if ( 'image' !== $image['type'] ) {
					continue;
				}
				if ( $image['decorative'] ) {
					$image['alt'] = '';
				} elseif ( '' === $image['alt'] ) {
					if ( '' !== $image['caption'] ) {
						$image['alt'] = $image['caption'];
					} else {
						$image['decorative'] = true;
					}
					$this->warnings[] = sprintf( 'sections[%d] image: missing alt text, please review', $s );
				}
				if ( 'media' === $image['source'] && ( $image['attachment_id'] <= 0 || ! wp_attachment_is_image( $image['attachment_id'] ) ) ) {
					$image['source']        = 'placeholder';
					$image['attachment_id'] = 0;
				}
				self::set_path( $schema['sections'][ $s ], $path, $image );
			}
		}
		return $schema;
	}
}
