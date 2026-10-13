<?php
/**
 * One diagnostic result, in one format.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Diagnostics;

/**
 * A diagnostic never fixes anything and never leaves the site. What it found is a title, a
 * verdict, plain lines and a few findings, and the same data becomes the screen and the Markdown
 * an owner copies to hand it on.
 */
final class Result {

	/**
	 * Which diagnostic: `alt_text`, `cdn` or `js_only`.
	 *
	 * @var string
	 */
	public $key;

	/**
	 * Whether it found nothing to hand on.
	 *
	 * @var bool
	 */
	public $clean = true;

	/**
	 * Sentences, each already looked up in the strings.
	 *
	 * @var string[]
	 */
	public $lines = array();

	/**
	 * Rows of findings, such as one crawler and what it was answered with.
	 *
	 * @var string[]
	 */
	public $items = array();

	/**
	 * When it was made, seconds since the epoch.
	 *
	 * @var int
	 */
	public $at = 0;

	/**
	 * Builds a result.
	 *
	 * @param string $key Diagnostic key.
	 */
	public function __construct( string $key ) {
		$this->key = $key;
		$this->at  = time();
	}

	/**
	 * The result as Markdown, for the Copy button.
	 *
	 * @param string $title The diagnostic's title.
	 */
	public function to_markdown( string $title ): string {
		$out = array( '### ' . self::inline( $title ), '' );
		foreach ( $this->lines as $line ) {
			$out[] = self::inline( $line );
			$out[] = '';
		}
		foreach ( $this->items as $item ) {
			$out[] = '- ' . self::inline( $item );
		}
		return rtrim( implode( "\n", $out ) ) . "\n";
	}

	/**
	 * The stored form.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'key'   => $this->key,
			'clean' => $this->clean,
			'lines' => $this->lines,
			'items' => $this->items,
			'at'    => $this->at,
		);
	}

	/**
	 * Rebuilds a result from its stored form.
	 *
	 * @param array<string,mixed> $data The stored form.
	 */
	public static function from_array( array $data ): self {
		$out        = new self( (string) ( $data['key'] ?? '' ) );
		$out->clean = ! empty( $data['clean'] );
		$out->lines = array_map( 'strval', (array) ( $data['lines'] ?? array() ) );
		$out->items = array_map( 'strval', (array) ( $data['items'] ?? array() ) );
		$out->at    = (int) ( $data['at'] ?? 0 );
		return $out;
	}

	/**
	 * Text on one line with the characters that make Markdown of it removed.
	 *
	 * @param string $text Text.
	 */
	private static function inline( string $text ): string {
		return trim( preg_replace( '/\s+/', ' ', preg_replace( '/[\r\n`*_\[\]<>]+/', ' ', $text ) ?? '' ) ?? '' );
	}
}
