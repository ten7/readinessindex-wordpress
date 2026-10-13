<?php
/**
 * An SEO plugin the plugin hands its facts to.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers\Integration;

/**
 * Yoast SEO and Rank Math each print a structured data graph of their own, and this plugin
 * never prints a second one beside it. Each integration does three things, all by feature
 * detection and never by assuming a version: say whether the tool's settings are where this code
 * expects them, write the organization's name, logo and profiles through the tool's own options
 * (recording every value it replaces, so Undo gives them back exactly), and add the remaining
 * facts through the tool's graph filters, keyed by `@id`.
 */
interface Tool {

	/**
	 * The key `SeoTools` uses for the tool.
	 */
	public function key(): string;

	/**
	 * Whether the tool is active and its settings are where this code expects them. False means
	 * the plugin degrades to instructions, rather than write into something it does not recognize.
	 */
	public function supported(): bool;

	/**
	 * The option writes a set of choices needs, as before and after, so they are shown in the
	 * preview and an owner confirms them by applying.
	 *
	 * @param array<string,mixed> $choices   The owner's choices.
	 * @param string              $site_name The site's name, which is the organization's.
	 * @return array<int,array{option:string,key:string,before:mixed,after:mixed}>
	 */
	public function plan( array $choices, string $site_name ): array;

	/**
	 * Makes the writes and returns what they replaced.
	 *
	 * @param array<int,array{option:string,key:string,before:mixed,after:mixed}> $plan A plan.
	 * @return array<string,array<string,mixed>> Prior values by option and key.
	 */
	public function write( array $plan ): array;

	/**
	 * Puts back what `write` replaced.
	 *
	 * @param array<string,array<string,mixed>> $prior Prior values by option and key.
	 */
	public function restore( array $prior ): void;

	/**
	 * Adds the facts through the tool's graph filters.
	 *
	 * @param array<string,mixed> $choices The stored choices.
	 */
	public function register( array $choices ): void;

	/**
	 * What the tool needs that the choices do not give it, as a reason code, or null.
	 *
	 * @param array<string,mixed> $choices The owner's choices.
	 */
	public function missing( array $choices ): ?string;
}
