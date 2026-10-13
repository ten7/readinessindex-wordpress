<?php
/**
 * What a fixer is.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers;

/**
 * One change this plugin knows how to make to a site, and how to take it back.
 *
 * A fixer is a filter over something WordPress already produces (a robots.txt body, a list of
 * robots directives, a graph): given what the site serves with the fixer off, the owner's
 * choices and the facts about the site, it says what would be served with it on. That is what
 * makes Undo exact, since the only thing stored is the choices, and Apply idempotent.
 *
 * `apply`, `undo` and `cleanup` each take a `Permit`, which only the `Runner` can issue. The
 * runner is where the capability, the nonce, the conflict check, the preview hash, the
 * rollback and the changes table live, so a fixer that could be called without it would be a
 * way to change a client's site with none of those. `RegistryTest` also fails when a source
 * file other than the runner calls one of the three.
 */
interface Fixer {

	/**
	 * The id the contract names, such as `robots-txt`.
	 */
	public function id(): string;

	/**
	 * The Index tests this fixer moves, such as `AIR-1.1`.
	 *
	 * @return string[]
	 */
	public function checks(): array;

	/**
	 * Its name, from the shared strings.
	 */
	public function title(): string;

	/**
	 * One sentence about it, from the shared strings.
	 */
	public function describe(): string;

	/**
	 * The facts about this site the fixer may know: everything in the vector's `given` except
	 * `state` and `choices`. Reads WordPress and never changes it.
	 *
	 * @return array<string,mixed>
	 */
	public function detect(): array;

	/**
	 * What would be served with these facts and these choices: the pure logic.
	 *
	 * @param array<string,mixed> $given Facts, with `state` and `choices`.
	 * @return array<string,mixed> Evaluation: status, reason, findings, changes, output, baseline, paste.
	 */
	public function evaluate( array $given ): array;

	/**
	 * Why the fixer cannot apply here, as reason codes. Empty when nothing is in the way.
	 *
	 * @param array<string,mixed> $given Facts, with `state` and `choices`.
	 * @param array<string,mixed> $ev    The evaluation of those facts.
	 * @return string[]
	 */
	public function conflicts( array $given, array $ev ): array;

	/**
	 * The change that would be made: the evaluation for these choices, nothing written.
	 *
	 * @param array<string,mixed> $choices The owner's choices.
	 * @return array<string,mixed> The evaluation, with `given` and `conflicts`.
	 */
	public function preview( array $choices ): array;

	/**
	 * The choices as stored now, or null when the fixer is off.
	 *
	 * @return array<string,mixed>|null
	 */
	public function state(): ?array;

	/**
	 * Turns the owner's raw form input into choices of the right shape and nothing else.
	 *
	 * @param array<string,mixed> $raw Unslashed request input.
	 * @return array<string,mixed>
	 */
	public function sanitize_choices( array $raw ): array;

	/**
	 * Whether this fixer can make a change on this site right now, cheaply: no conflict a
	 * glance can see. Used to say how many points the plugin can reach before anything is
	 * previewed.
	 */
	public function reachable(): bool;

	/**
	 * Stores the choices and does whatever else turns the change on.
	 *
	 * @param Permit              $permit  Issued by the runner.
	 * @param array<string,mixed> $choices The choices to store.
	 * @return array<string,mixed> Anything Undo needs besides the stored choices, such as a
	 *                             setting of another plugin that was written.
	 */
	public function apply( Permit $permit, array $choices ): array;

	/**
	 * Looks at the site as an anonymous visitor would and says whether the change is served.
	 *
	 * @param array<string,mixed> $ev The evaluation that was applied.
	 * @return array{live:bool|null,reason:string}
	 */
	public function verify( array $ev ): array;

	/**
	 * Gives back what the record's `prior` was, exactly, and anything in `extra`.
	 *
	 * @param Permit              $permit Issued by the runner.
	 * @param array<string,mixed> $record `fixer`, `prior`, `new` and `extra`.
	 */
	public function undo( Permit $permit, array $record ): void;

	/**
	 * Removes everything the fixer put on the site, for "Remove my changes when I delete the plugin".
	 *
	 * @param Permit $permit Issued by the runner.
	 */
	public function cleanup( Permit $permit ): void;
}
