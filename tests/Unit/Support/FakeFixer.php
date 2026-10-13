<?php
/**
 * A fixer with no WordPress in it, for the runner's tests.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Tests\Support;

use ReadinessIndex\Fixers\Fixer;
use ReadinessIndex\Fixers\Logic\Lifecycle;
use ReadinessIndex\Fixers\Permit;

/**
 * Wraps one of the real logic classes around a vector's `given`, keeps its stored state in
 * memory, and can be told to fail. The runner is therefore tested with the real preview, the
 * real conflict reasons and the real undo, against the golden vectors, with only the part
 * that touches WordPress replaced.
 */
class FakeFixer implements Fixer {

	/**
	 * Calls made, in order, for step-ordering tests.
	 *
	 * @var string[]
	 */
	public $calls = array();

	/**
	 * Stored choices.
	 *
	 * @var array<string,mixed>|null
	 */
	public $stored = null;

	/**
	 * Whether `apply` throws after storing.
	 *
	 * @var bool
	 */
	public $throw_on_apply = false;

	/**
	 * Whether `cleanup` throws.
	 *
	 * @var bool
	 */
	public $throw_on_cleanup = false;

	/**
	 * Whether `verify` says the change is not live.
	 *
	 * @var bool
	 */
	public $not_live = false;

	/**
	 * The Index tests this fake moves.
	 *
	 * @var string[]
	 */
	public $check_ids = array( 'AIR-0.0' );

	/**
	 * What `reachable` says.
	 *
	 * @var bool
	 */
	public $is_reachable = true;

	/**
	 * Extra conflict reasons.
	 *
	 * @var string[]
	 */
	public $extra_conflicts = array();

	/**
	 * Facts, changed by a test to make a preview stale.
	 *
	 * @var array<string,mixed>
	 */
	public $facts;

	/**
	 * The choices the vector applies, used to evaluate what is served when nothing is stored.
	 *
	 * @var array<string,mixed>
	 */
	private $vector_choices;

	/**
	 * The logic class.
	 *
	 * @var string
	 */
	private $logic;

	/**
	 * The fixer id.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Builds the fixer.
	 *
	 * @param string              $id    Fixer id.
	 * @param string              $logic Logic class name.
	 * @param array<string,mixed> $given A vector's given.
	 */
	public function __construct( string $id, string $logic, array $given ) {
		$this->id             = $id;
		$this->logic          = $logic;
		$this->stored         = $given['state'] ?? null;
		$this->vector_choices = $given['choices'] ?? array();
		unset( $given['state'], $given['choices'] );
		$this->facts = $given;
	}

	public function id(): string {
		return $this->id;
	}

	public function checks(): array {
		return $this->check_ids;
	}

	public function title(): string {
		return 'Fake fixer title';
	}

	public function describe(): string {
		return 'Fake fixer sentence';
	}

	public function detect(): array {
		$this->calls[] = 'detect';
		return $this->facts;
	}

	public function evaluate( array $given ): array {
		$class = $this->logic;
		return $class::evaluate( $given );
	}

	public function conflicts( array $given, array $ev ): array {
		$out = in_array( $ev['status'], array( 'conflict', 'defer' ), true ) ? array( $ev['reason'] ) : array();
		return array_merge( $out, $this->extra_conflicts );
	}

	public function preview( array $choices ): array {
		$given            = $this->detect();
		$given['state']   = $this->stored;
		$given['choices'] = $choices;
		$ev               = $this->evaluate( $given );
		$ev['conflicts']  = $this->conflicts( $given, $ev );
		return $ev;
	}

	public function state(): ?array {
		return $this->stored;
	}

	public function sanitize_choices( array $raw ): array {
		return $raw;
	}

	public function reachable(): bool {
		return $this->is_reachable;
	}

	public function apply( Permit $permit, array $choices ): array {
		$this->calls[] = 'apply';
		$this->stored  = $choices;
		if ( $this->throw_on_apply ) {
			throw new \RuntimeException( 'boom' );
		}
		return array();
	}

	public function verify( array $ev ): array {
		$this->calls[] = 'verify';
		return array(
			'live'   => ! $this->not_live,
			'reason' => $this->not_live ? 'different' : '',
		);
	}

	public function undo( Permit $permit, array $record ): void {
		$this->calls[] = 'undo';
		$this->stored  = $record['prior'];
	}

	public function cleanup( Permit $permit ): void {
		$this->calls[] = 'cleanup';
		if ( $this->throw_on_cleanup ) {
			throw new \RuntimeException( 'cleanup failed' );
		}
		$this->stored = null;
	}

	/**
	 * What would be served now: the baseline when the fixer is off, the output when on.
	 *
	 * @return array<string,mixed>
	 */
	public function served(): array {
		$given            = $this->facts;
		$given['state']   = $this->stored;
		$given['choices'] = $this->stored ?? $this->vector_choices;
		$ev               = $this->evaluate( $given );
		return null === $this->stored ? $ev['baseline'] : $ev['output'];
	}
}
