<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a local/remote field value pair for the Sync Details page with
 * their differing words wrapped in <mark>, reusing the same word-level diff
 * engine core uses for post revision comparisons.
 */
class Rest_In_Sync_Diff_Renderer {

	/** Above this ratio of changed-to-unchanged text, skip highlighting entirely — the values are too different for word-level marks to be useful. */
	const DIFF_RATIO_THRESHOLD = 0.6;

	/**
	 * @return array{local:string, remote:string} Safe-to-echo HTML, already escaped.
	 */
	public static function render( $local, $remote ) {
		$local  = (string) $local;
		$remote = (string) $remote;

		if ( '' === $local || '' === $remote || $local === $remote ) {
			return array(
				'local'  => esc_html( $local ),
				'remote' => esc_html( $remote ),
			);
		}

		if ( ! class_exists( 'Text_Diff', false ) ) {
			require ABSPATH . WPINC . '/wp-diff.php';
		}

		$diff     = new Text_Diff( 'auto', array( explode( "\n", $local ), explode( "\n", $remote ) ) );
		$renderer = new WP_Text_Diff_Renderer_inline();
		$html     = $renderer->render( $diff );

		if ( self::too_different( $html ) ) {
			return array(
				'local'  => esc_html( $local ),
				'remote' => esc_html( $remote ),
			);
		}

		return array(
			'local'  => self::as_marks( preg_replace( '#<ins>.*?</ins>#s', '', $html ), 'del' ),
			'remote' => self::as_marks( preg_replace( '#<del>.*?</del>#s', '', $html ), 'ins' ),
		);
	}

	/** Mirrors WP_Text_Diff_Renderer_Table's own "too different to bother" heuristic. */
	private static function too_different( $html ) {
		if ( ! preg_match_all( '#(<ins>.*?</ins>|<del>.*?</del>)#s', $html, $matches ) ) {
			return false;
		}

		$marked_length = strlen( wp_strip_all_tags( implode( ' ', $matches[0] ) ) );
		$total_length  = strlen( wp_strip_all_tags( $html ) ) * 2 - $marked_length;

		if ( $total_length <= 0 ) {
			return false;
		}

		return ( $marked_length / $total_length ) > self::DIFF_RATIO_THRESHOLD;
	}

	private static function as_marks( $html, $tag ) {
		return preg_replace( array( "#<{$tag}>#", "#</{$tag}>#" ), array( '<mark class="ris-diff-mark">', '</mark>' ), $html );
	}
}
