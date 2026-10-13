<?php
/**
 * What every fixer shares.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Impl;

use ReadinessIndex\Fixers\Fixer;
use ReadinessIndex\Fixers\Permit;
use ReadinessIndex\Fixers\State;
use ReadinessIndex\Strings;

/**
 * The parts of a fixer that are the same for all five: its sentences come from the shared
 * strings by id, it stores nothing but the owner's choices, a preview is the pure logic run over
 * the facts WordPress gives, and undo gives back the stored prior value exactly.
 *
 * A subclass supplies the logic, the facts, how it reads the owner's input, and any conflict
 * that is a fact about this WordPress site and not about the text.
 */
abstract class AbstractFixer implements Fixer {

	/**
	 * The pure logic: facts and choices in, what would be served out.
	 *
	 * @param array<string,mixed> $given Facts, state and choices.
	 * @return array<string,mixed>
	 */
	abstract public function evaluate( array $given ): array;

	/**
	 * The key of this fixer's sentences: its id with underscores.
	 */
	protected function key(): string {
		return str_replace( '-', '_', $this->id() );
	}

	/**
	 * Its name.
	 */
	public function title(): string {
		return Strings::get( 'fixer.' . $this->key() . '.title' );
	}

	/**
	 * One sentence about it.
	 */
	public function describe(): string {
		return Strings::get( 'fixer.' . $this->key() . '.summary' );
	}

	/**
	 * The stored choices, or null when the fixer is off.
	 *
	 * @return array<string,mixed>|null
	 */
	public function state(): ?array {
		return State::get( $this->id() );
	}

	/**
	 * The site's own facts every fixer's `given` has.
	 *
	 * @return array<string,mixed>
	 */
	protected function site(): array {
		return array(
			'url'  => untrailingslashit( home_url() ),
			'name' => (string) get_bloginfo( 'name' ),
		);
	}

	/**
	 * Lets a fixer add facts that depend on the owner's choices.
	 *
	 * @param array<string,mixed> $given Facts, state and choices.
	 * @return array<string,mixed>
	 */
	protected function with_choices( array $given ): array {
		return $given;
	}

	/**
	 * The change that would be made.
	 *
	 * @param array<string,mixed> $choices The owner's choices.
	 * @return array<string,mixed>
	 */
	public function preview( array $choices ): array {
		$given            = $this->detect();
		$given['state']   = $this->state();
		$given['choices'] = $choices;
		$given            = $this->with_choices( $given );
		try {
			$ev = $this->evaluate( $given );
		} catch ( \InvalidArgumentException $e ) {
			// Choices that are not yet complete: nothing can be applied, and the preview says why
			// instead of guessing what the owner meant.
			$ev = array(
				'status'   => 'invalid',
				'reason'   => 'choices_incomplete',
				'findings' => array(),
				'changes'  => array(),
				'output'   => array(),
				'baseline' => array(),
				'paste'    => null,
			);
		}
		$ev['conflicts'] = $this->conflicts( $given, $ev );
		return $this->annotate( $ev, $given );
	}

	/**
	 * Adds what only this WordPress site can say to an evaluation, outside the shapes the
	 * golden vectors compare.
	 *
	 * @param array<string,mixed> $ev    The evaluation.
	 * @param array<string,mixed> $given The facts it was made from.
	 * @return array<string,mixed>
	 */
	protected function annotate( array $ev, array $given ): array {
		unset( $given );
		return $ev;
	}

	/**
	 * The reasons the change cannot be applied: the evaluation's own, then this site's.
	 *
	 * @param array<string,mixed> $given Facts, state and choices.
	 * @param array<string,mixed> $ev    The evaluation.
	 * @return string[]
	 */
	public function conflicts( array $given, array $ev ): array {
		$out = array();
		if ( in_array( $ev['status'], array( 'conflict', 'defer' ), true ) ) {
			$out[] = (string) $ev['reason'];
		}
		return array_merge( $out, $this->site_conflicts( $given ) );
	}

	/**
	 * Conflicts that are facts about this WordPress install.
	 *
	 * @param array<string,mixed> $given Facts, state and choices.
	 * @return string[]
	 */
	protected function site_conflicts( array $given ): array {
		unset( $given );
		return array();
	}

	/**
	 * Whether this fixer can make a change here right now, at a glance.
	 */
	public function reachable(): bool {
		return array() === $this->site_conflicts( array() );
	}

	/**
	 * Turns the owner's input into choices. A fixer with no choices has none.
	 *
	 * @param array<string,mixed> $raw Unslashed request input.
	 * @return array<string,mixed>
	 */
	public function sanitize_choices( array $raw ): array {
		unset( $raw );
		return array();
	}

	/**
	 * Stores the choices. The runtime reads them on the next request.
	 *
	 * @param Permit              $permit  Issued by the runner.
	 * @param array<string,mixed> $choices The choices.
	 * @return array<string,mixed>
	 */
	public function apply( Permit $permit, array $choices ): array {
		unset( $permit );
		State::set( $this->id(), $choices );
		return array();
	}

	/**
	 * Gives back the prior value exactly: the stored choices as they were, or none.
	 *
	 * @param Permit              $permit Issued by the runner.
	 * @param array<string,mixed> $record `fixer`, `prior`, `new` and `extra`.
	 */
	public function undo( Permit $permit, array $record ): void {
		unset( $permit );
		State::set( $this->id(), $record['prior'] );
	}

	/**
	 * Turns the fixer off.
	 *
	 * @param Permit $permit Issued by the runner.
	 */
	public function cleanup( Permit $permit ): void {
		unset( $permit );
		State::set( $this->id(), null );
	}

	/**
	 * The default verification: could not look.
	 *
	 * @param array<string,mixed> $ev The applied evaluation.
	 * @return array{live:bool|null,reason:string}
	 */
	public function verify( array $ev ): array {
		unset( $ev );
		return array(
			'live'   => null,
			'reason' => 'unreachable',
		);
	}
}
