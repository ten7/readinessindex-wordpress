<?php
/**
 * The only thing that can apply or undo a change.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Fixers;

use ReadinessIndex\Admin\Actions;
use ReadinessIndex\Admin\FixFlash;
use ReadinessIndex\Admin\Urls;
use ReadinessIndex\Changes\Log;

/**
 * Every change this plugin makes to a site goes through here, in this order:
 *
 * 1. the capability, then the nonce (in the `admin-post` handlers, before anything is read);
 * 2. the preview is computed again and its hash compared with the one the form carried, so a
 *    site that changed while the owner was reading is shown again and never applied blind;
 * 3. a conflict or a deferral refuses, with its reasons, and records nothing;
 * 4. the prior stored choices are remembered, the fixer applies, and any throw restores them;
 * 5. a row is written, the page cache is asked to clear, and the change is verified as an
 *    anonymous visitor would see it. A verify that fails is recorded on the row and does not
 *    undo a change that was applied correctly.
 *
 * Undo restores the recorded prior value and refuses, with the reason, when a later change or
 * a hand edit has replaced what the change wrote.
 */
final class Runner {

	const APPLY_ACTION = 'readiness_index_apply';
	const UNDO_ACTION  = 'readiness_index_undo';

	/**
	 * Registers the two `admin-post` actions. There is no `nopriv` variant.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::APPLY_ACTION, array( self::class, 'handle_apply' ) );
		add_action( 'admin_post_' . self::UNDO_ACTION, array( self::class, 'handle_undo' ) );
	}

	/**
	 * Ends a request that is not a POST. Applying on a GET would let a link, an image tag or a
	 * prefetch change a client's site.
	 */
	private static function require_post(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			wp_die( '', '', array( 'response' => 405 ) );
		}
	}

	/**
	 * Apply: capability, nonce, then the runner.
	 */
	public static function handle_apply(): void {
		Actions::guard( self::APPLY_ACTION );
		self::require_post();

		$fixer = self::posted_fixer();
		if ( null === $fixer ) {
			wp_safe_redirect( Urls::fixes() );
			exit;
		}
		$raw     = isset( $_POST['choices'] ) && is_array( $_POST['choices'] ) ? wp_unslash( $_POST['choices'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified by Actions::guard above; sanitized by the fixer's own sanitize_choices.
		$choices = $fixer->sanitize_choices( $raw );
		$hash    = isset( $_POST['ri_hash'] ) && is_string( $_POST['ri_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['ri_hash'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by Actions::guard above.

		$result = self::apply( $fixer, $choices, $hash, get_current_user_id() );
		FixFlash::put( get_current_user_id(), $result );
		// A stale preview is shown again; everything else returns to the list.
		wp_safe_redirect( 'stale' === $result['outcome'] ? Urls::preview( $fixer->id(), $choices ) : Urls::fixes() );
		exit;
	}

	/**
	 * Undo: capability, nonce, then the runner.
	 */
	public static function handle_undo(): void {
		Actions::guard( self::UNDO_ACTION );
		self::require_post();

		$id     = isset( $_POST['change'] ) ? absint( wp_unslash( $_POST['change'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by Actions::guard above.
		$result = self::undo( $id, get_current_user_id() );
		FixFlash::put( get_current_user_id(), $result );
		wp_safe_redirect( Urls::changes() );
		exit;
	}

	/**
	 * The fixer a form names, looked up by id in the registry and nowhere else.
	 */
	private static function posted_fixer(): ?Fixer {
		$id = isset( $_POST['fixer'] ) && is_string( $_POST['fixer'] ) ? sanitize_key( wp_unslash( $_POST['fixer'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by Actions::guard in the caller.
		return Registry::get( $id );
	}

	/**
	 * The preview of a fixer for some choices, with the hash an Apply form must carry back.
	 *
	 * @param Fixer               $fixer   The fixer.
	 * @param array<string,mixed> $choices The owner's choices.
	 * @return array<string,mixed> The evaluation, `conflicts`, `can_apply` and `hash`.
	 */
	public static function preview( Fixer $fixer, array $choices ): array {
		$preview = $fixer->preview( $choices );
		$state   = $fixer->state();

		$preview['can_apply'] = 'ready' === $preview['status'] && array() === $preview['conflicts'] && ! ( null !== $state && Canon::same( $state, $choices ) );
		$preview['hash']      = Canon::hash(
			array(
				'fixer'   => $fixer->id(),
				'choices' => $choices,
				'status'  => $preview['status'],
				'reason'  => $preview['reason'],
				'changes' => $preview['changes'],
				'output'  => $preview['output'],
				'writes'  => $preview['writes'] ?? array(),
			)
		);
		return $preview;
	}

	/**
	 * Applies a change. The capability and the nonce were checked by the caller.
	 *
	 * @param Fixer               $fixer   The fixer.
	 * @param array<string,mixed> $choices The owner's choices.
	 * @param string              $hash    The hash of the preview the owner reviewed.
	 * @param int                 $user_id The administrator.
	 * @return array<string,mixed> `outcome` (applied, unchanged, refused, stale or failed), `fixer`, and what each carries.
	 */
	public static function apply( Fixer $fixer, array $choices, string $hash, int $user_id ): array {
		$result = array(
			'outcome' => '',
			'fixer'   => $fixer->id(),
		);
		$now    = self::preview( $fixer, $choices );

		if ( ! hash_equals( $now['hash'], $hash ) ) {
			$result['outcome'] = 'stale';
			return $result;
		}
		if ( array() !== $now['conflicts'] ) {
			$result['outcome'] = 'refused';
			$result['reasons'] = $now['conflicts'];
			$deferred          = $now['findings']['deferred'][0]['tooling'] ?? null;
			if ( is_string( $deferred ) ) {
				$result['tooling'] = $deferred;
			}
			return $result;
		}
		if ( 'ready' !== $now['status'] ) {
			$result['outcome'] = 'unchanged';
			$result['reason']  = (string) $now['reason'];
			return $result;
		}
		$prior = $fixer->state();
		if ( null !== $prior && Canon::same( $prior, $choices ) ) {
			// Applying twice is the same as once: nothing is written and nothing is recorded.
			$result['outcome'] = 'unchanged';
			$result['reason']  = 'nothing_to_change';
			return $result;
		}

		$permit = new Permit( new self() );
		$row    = array(
			'fixer'   => $fixer->id(),
			'checks'  => $fixer->checks(),
			'action'  => Log::APPLY,
			'user_id' => $user_id,
			'prior'   => $prior,
			'new'     => $choices,
		);
		try {
			$extra = $fixer->apply( $permit, $choices );
		} catch ( \Throwable $e ) {
			// Put back exactly what was there. A second failure is swallowed so the first one is
			// what the owner hears about, and the row says the change failed.
			try {
				$fixer->undo(
					$permit,
					array(
						'fixer' => $fixer->id(),
						'prior' => $prior,
						'new'   => $choices,
						'extra' => array(),
					)
				);
			} catch ( \Throwable $again ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- See above.
				unset( $again );
			}
			Log::insert( $row + array( 'status' => Log::FAILED ) );
			$result['outcome'] = 'failed';
			return $result;
		}

		Log::supersede_applied( $fixer->id() );
		$id = Log::insert(
			$row + array(
				'status' => Log::APPLIED,
				'extra'  => array() === $extra ? null : $extra,
			)
		);

		$cache = PageCache::purge();
		$check = $fixer->verify( $now );
		Log::update( $id, array( 'verified' => $check['live'] ) );

		$result['outcome'] = 'applied';
		$result['change']  = $id;
		$result['live']    = $check['live'];
		$result['reason']  = $check['reason'];
		$result['cache']   = $cache ?? PageCache::detect();
		return $result;
	}

	/**
	 * Undoes a change. The capability and the nonce were checked by the caller.
	 *
	 * @param int $change_id The applied row to undo.
	 * @param int $user_id   The administrator.
	 * @return array<string,mixed> `outcome` (undone or refused), `fixer` and, for a refusal, `reason`.
	 */
	public static function undo( int $change_id, int $user_id ): array {
		$row = Log::find( $change_id );
		if ( null === $row || Log::APPLY !== $row['action'] ) {
			return array(
				'outcome' => 'refused',
				'fixer'   => '',
				'reason'  => 'not_found',
			);
		}
		$result = array(
			'outcome' => 'refused',
			'fixer'   => $row['fixer'],
		);
		$fixer  = Registry::get( $row['fixer'] );
		if ( null === $fixer ) {
			$result['reason'] = 'not_found';
			return $result;
		}
		if ( Log::UNDONE === $row['status'] ) {
			$result['reason'] = 'already_undone';
			return $result;
		}
		if ( Log::APPLIED !== $row['status'] ) {
			$result['reason'] = 'superseded';
			return $result;
		}
		// What the change wrote must still be what is there. A later apply marks this row
		// superseded; this catches a value edited by hand or by another tool since, which
		// restoring the prior would silently overwrite.
		$state = $fixer->state();
		if ( null === $row['new'] || ! Canon::same( $state, $row['new'] ) ) {
			Log::update( $change_id, array( 'status' => Log::SUPERSEDED ) );
			$result['reason'] = 'superseded';
			return $result;
		}

		$permit = new Permit( new self() );
		$record = array(
			'fixer' => $row['fixer'],
			'prior' => $row['prior'],
			'new'   => $row['new'],
			'extra' => $row['extra'] ?? array(),
		);
		try {
			$fixer->undo( $permit, $record );
		} catch ( \Throwable $e ) {
			$result['outcome'] = 'failed';
			return $result;
		}
		Log::update( $change_id, array( 'status' => Log::UNDONE ) );
		Log::insert(
			array(
				'fixer'   => $row['fixer'],
				'checks'  => $row['checks'],
				'action'  => Log::UNDO,
				'status'  => Log::UNDONE,
				'user_id' => $user_id,
				'prior'   => $row['new'],
				'new'     => $row['prior'],
				'undoes'  => $change_id,
			)
		);
		PageCache::purge();
		$result['outcome'] = 'undone';
		return $result;
	}

	/**
	 * Removes everything every fixer put on the site, for an uninstall with "Remove my
	 * changes when I delete the plugin" on. A fixer that throws does not stop the others.
	 */
	public static function cleanup_all(): void {
		$permit = new Permit( new self() );
		foreach ( Registry::all() as $fixer ) {
			try {
				$fixer->cleanup( $permit );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
	}
}
