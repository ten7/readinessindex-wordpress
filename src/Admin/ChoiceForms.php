<?php
/**
 * The choices a fixer asks the owner to make.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Admin;

use ReadinessIndex\Fixers\Fixer;
use ReadinessIndex\Fixers\Logic\RobotsTxt;
use ReadinessIndex\Strings;

/**
 * One small GET form per fixer, on the preview page, so a choice is a link and the preview
 * recomputes without scripts. Nothing is preselected that the owner has not chosen: the three
 * content signals start as not chosen and Apply stays disabled until each has an answer.
 */
final class ChoiceForms {

	/**
	 * Prints a fixer's choice form, if it has one.
	 *
	 * @param Fixer               $fixer   The fixer.
	 * @param array<string,mixed> $choices The current choices.
	 */
	public static function render( Fixer $fixer, array $choices ): void {
		if ( ! in_array( $fixer->id(), array( 'robots-txt', 'entity-graph' ), true ) ) {
			return;
		}
		echo '<form method="get" class="ri-choices" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( Urls::PREVIEW ) . '" />';
		echo '<input type="hidden" name="fixer" value="' . esc_attr( $fixer->id() ) . '" />';
		echo '<h3>' . esc_html( Strings::get( 'preview.choices' ) ) . '</h3>';
		if ( 'robots-txt' === $fixer->id() ) {
			self::robots( $choices );
		} else {
			self::graph( $choices );
		}
		echo '<p><button type="submit" class="button">' . esc_html( Strings::get( 'preview.update' ) ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * The robots.txt choices.
	 *
	 * @param array<string,mixed> $choices The current choices.
	 */
	private static function robots( array $choices ): void {
		$allow   = (array) ( $choices['allow_agents'] ?? array() );
		$signals = (array) ( $choices['content_signals'] ?? array() );
		echo '<fieldset><legend>' . esc_html( Strings::get( 'fixer.robots_txt.allow_label' ) ) . '</legend>';
		foreach ( RobotsTxt::AGENTS as $agent ) {
			echo '<label class="ri-check"><input type="checkbox" name="choices[allow_agents][]" value="' . esc_attr( $agent ) . '"' . checked( in_array( $agent, $allow, true ), true, false ) . ' /> ' . esc_html( $agent ) . '</label><br />'; // phpcs:ignore WordPress.Security.EscapeOutput -- checked() returns a fixed attribute.
		}
		echo '</fieldset>';

		echo '<fieldset><legend>' . esc_html( Strings::get( 'fixer.robots_txt.signals_label' ) ) . '</legend>';
		foreach ( RobotsTxt::SIGNAL_KEYS as $key ) {
			$value = $signals[ $key ] ?? '';
			echo '<p class="ri-signal"><span class="ri-signal-label">' . esc_html( Strings::get( 'fixer.robots_txt.signal.' . $key ) ) . '</span><br />';
			foreach ( array(
				'yes' => Strings::get( 'fixer.robots_txt.signal_yes' ),
				'no'  => Strings::get( 'fixer.robots_txt.signal_no' ),
				''    => Strings::get( 'fixer.robots_txt.not_chosen' ),
			) as $option => $label ) {
				echo '<label><input type="radio" name="choices[content_signals][' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $option ) . '"' . checked( (string) $value, (string) $option, false ) . ' /> ' . esc_html( $label ) . '</label> '; // phpcs:ignore WordPress.Security.EscapeOutput -- checked() returns a fixed attribute.
			}
			echo '</p>';
		}
		echo '</fieldset>';
		echo '<p><label><input type="checkbox" name="choices[policy]" value="1"' . checked( ! empty( $choices['policy'] ), true, false ) . ' /> ' . esc_html( Strings::get( 'fixer.robots_txt.policy_label' ) ) . '</label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- checked() returns a fixed attribute.
	}

	/**
	 * The structured data choices: the facts about the organization.
	 *
	 * @param array<string,mixed> $choices The current choices.
	 */
	private static function graph( array $choices ): void {
		$org = (array) ( $choices['organization'] ?? array() );
		echo '<fieldset><legend>' . esc_html( Strings::get( 'fixer.entity_graph.facts_label' ) ) . '</legend>';
		self::input( 'organization[legal_name]', Strings::get( 'fixer.entity_graph.legal_name_label' ), (string) ( $org['legal_name'] ?? '' ) );
		self::input( 'organization[logo]', Strings::get( 'fixer.entity_graph.logo_label' ), (string) ( $org['logo'] ?? '' ), Strings::get( 'fixer.entity_graph.logo_hint' ) );
		self::area( 'organization[description]', Strings::get( 'fixer.entity_graph.description_label' ), (string) ( $org['description'] ?? '' ) );
		self::input( 'organization[founding_date]', Strings::get( 'fixer.entity_graph.founding_date_label' ), (string) ( $org['founding_date'] ?? '' ) );
		self::input( 'organization[tax_id]', Strings::get( 'fixer.entity_graph.tax_id_label' ), (string) ( $org['tax_id'] ?? '' ) );
		foreach ( array( 'street', 'locality', 'region', 'postal_code', 'country' ) as $part ) {
			self::input( 'organization[address][' . $part . ']', Strings::get( 'fixer.entity_graph.' . $part . '_label' ), (string) ( $org['address'][ $part ] ?? '' ) );
		}
		self::area( 'organization[area_served]', Strings::get( 'fixer.entity_graph.area_served_label' ), implode( "\n", (array) ( $org['area_served'] ?? array() ) ) );
		self::area( 'organization[leaders]', Strings::get( 'fixer.entity_graph.leaders_label' ), implode( "\n", (array) ( $org['leaders'] ?? array() ) ) );
		echo '<h4>' . esc_html( Strings::get( 'fixer.entity_graph.contact_points_label' ) ) . '</h4>';
		$points = array_pad( array_values( (array) ( $org['contact_points'] ?? array() ) ), 2, array() );
		foreach ( $points as $i => $point ) {
			self::input( 'organization[contact_points][' . $i . '][type]', Strings::get( 'fixer.entity_graph.contact_type_label' ), (string) ( $point['type'] ?? '' ) );
			self::input( 'organization[contact_points][' . $i . '][email]', Strings::get( 'fixer.entity_graph.email_label' ), (string) ( $point['email'] ?? '' ) );
			self::input( 'organization[contact_points][' . $i . '][telephone]', Strings::get( 'fixer.entity_graph.telephone_label' ), (string) ( $point['telephone'] ?? '' ) );
		}
		self::area( 'organization[same_as]', Strings::get( 'fixer.entity_graph.same_as_label' ) . ' ' . Strings::get( 'fixer.entity_graph.same_as_hint' ), implode( "\n", (array) ( $org['same_as'] ?? array() ) ) );
		self::input( 'search_template', Strings::get( 'fixer.entity_graph.search_template_label' ), (string) ( $choices['search_template'] ?? '' ) );
		echo '<h4>' . esc_html( Strings::get( 'fixer.entity_graph.pages_label' ) ) . '</h4>';
		self::pages( 'about_page', Strings::get( 'fixer.entity_graph.about_label' ), (int) ( $choices['about_page'] ?? 0 ) );
		self::pages( 'contact_page', Strings::get( 'fixer.entity_graph.contact_label' ), (int) ( $choices['contact_page'] ?? 0 ) );
		echo '</fieldset>';
	}

	/**
	 * One text field.
	 *
	 * @param string $name  Field name under `choices`.
	 * @param string $label Its label.
	 * @param string $value Its value.
	 * @param string $hint  Help text.
	 */
	private static function input( string $name, string $label, string $value, string $hint = '' ): void {
		$id = 'ri-' . sanitize_key( str_replace( array( '[', ']' ), '-', $name ) );
		echo '<p><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><br />';
		echo '<input type="text" class="large-text" id="' . esc_attr( $id ) . '" name="choices[' . esc_attr( self::path( $name ) ) . '" value="' . esc_attr( $value ) . '" />';
		if ( '' !== $hint ) {
			echo '<br /><span class="description">' . esc_html( $hint ) . '</span>';
		}
		echo '</p>';
	}

	/**
	 * One text area.
	 *
	 * @param string $name  Field name under `choices`.
	 * @param string $label Its label.
	 * @param string $value Its value.
	 */
	private static function area( string $name, string $label, string $value ): void {
		$id = 'ri-' . sanitize_key( str_replace( array( '[', ']' ), '-', $name ) );
		echo '<p><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><br />';
		echo '<textarea class="large-text" rows="3" id="' . esc_attr( $id ) . '" name="choices[' . esc_attr( self::path( $name ) ) . '">' . esc_textarea( $value ) . '</textarea></p>';
	}

	/**
	 * A choice of one published page.
	 *
	 * @param string $name     Field name under `choices`.
	 * @param string $label    Its label.
	 * @param int    $selected The page chosen.
	 */
	private static function pages( string $name, string $label, int $selected ): void {
		$pages = get_pages(
			array(
				'post_status' => 'publish',
				'number'      => 200,
				'sort_column' => 'post_title',
			)
		);
		echo '<p><label for="ri-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><br /><select id="ri-' . esc_attr( $name ) . '" name="choices[' . esc_attr( $name ) . ']">';
		echo '<option value="0">' . esc_html( Strings::get( 'preview.not_chosen' ) ) . '</option>';
		foreach ( is_array( $pages ) ? $pages : array() as $page ) {
			echo '<option value="' . esc_attr( (string) $page->ID ) . '"' . selected( $selected, (int) $page->ID, false ) . '>' . esc_html( $page->post_title ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput -- selected() returns a fixed attribute.
		}
		echo '</select></p>';
	}

	/**
	 * A field name as it follows `choices[`: the first key is closed, the rest is kept, so
	 * `organization[address][street]` becomes `organization][address][street]` and the whole
	 * name reads `choices[organization][address][street]`.
	 *
	 * @param string $name Name such as `organization[address][street]`.
	 */
	private static function path( string $name ): string {
		$pos = strpos( $name, '[' );
		return false === $pos ? $name . ']' : substr( $name, 0, $pos ) . ']' . substr( $name, $pos );
	}
}
