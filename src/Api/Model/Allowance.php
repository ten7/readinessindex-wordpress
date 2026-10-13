<?php
/**
 * What the account may do.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Api\Model;

use ReadinessIndex\Api\Shape;

/**
 * The `allowance` object. Read, never worked out: what an account may buy is the
 * server's decision, and a module that reasons about plans disagrees with the website
 * the day the plans change.
 */
final class Allowance {

	/**
	 * Rescans left, or null on a plan that does not meter them.
	 *
	 * @var int|null
	 */
	public $rescans_left;

	/**
	 * Rescans in all, or null.
	 *
	 * @var int|null
	 */
	public $rescans_total;

	/**
	 * The plan's label.
	 *
	 * @var string
	 */
	public $plan;

	/**
	 * True on the free plan, whose rescans are for the life of the account.
	 *
	 * @var bool
	 */
	public $lifetime;

	/**
	 * Whether a pack may be bought right now.
	 *
	 * @var bool
	 */
	public $can_buy_pack;

	/**
	 * Standing: state, days left, writable.
	 *
	 * @var array{state:string,days_left:?int,writable:bool}
	 */
	public $standing;

	/**
	 * The next step, or null when billing is off.
	 *
	 * @var NextStep|null
	 */
	public $next_step;

	/**
	 * Where a pack is bought, when `can_buy_pack`.
	 *
	 * @var string|null
	 */
	public $pack_url;

	/**
	 * Builds the object from a decoded response.
	 *
	 * @param array<string,mixed> $data Decoded object.
	 * @param string              $at   Dotted path, for contract errors.
	 */
	public static function from_array( array $data, string $at = 'allowance' ): self {
		$out                = new self();
		$out->rescans_left  = Shape::int_or_null( $data, 'rescans_left', $at );
		$out->rescans_total = Shape::int_or_null( $data, 'rescans_total', $at );
		$out->plan          = Shape::str( $data, 'plan', $at );
		$out->lifetime      = Shape::bool( $data, 'lifetime', $at );
		$out->can_buy_pack  = Shape::bool( $data, 'can_buy_pack', $at );

		$standing       = Shape::object( Shape::need( $data, 'standing', $at ), $at . '.standing' );
		$out->standing  = array(
			'state'     => Shape::str( $standing, 'state', $at . '.standing' ),
			'days_left' => Shape::int_or_null( $standing, 'days_left', $at . '.standing' ),
			'writable'  => Shape::bool( $standing, 'writable', $at . '.standing' ),
		);
		$next           = Shape::need( $data, 'next_step', $at );
		$out->next_step = null === $next ? null : NextStep::from_array( Shape::object( $next, $at . '.next_step' ), $at . '.next_step' );
		$out->pack_url  = Shape::str_or_null( $data, 'pack_url', $at );
		return $out;
	}

	/**
	 * Whether every rescan is spent.
	 */
	public function is_spent(): bool {
		return 0 === $this->rescans_left;
	}
}
