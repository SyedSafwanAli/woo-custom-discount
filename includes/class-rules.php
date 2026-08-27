<?php
/**
 * Data layer for campaigns and expiry batches.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

namespace WCD;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes rules.
 *
 * A rule is one of two things, and the `type` column says which:
 *
 *   campaign  An ordinary discount. May target the whole store, some
 *             categories or some products. Ends on `ends_at` if set. Ending a
 *             campaign removes the discount; the product stays on sale in the
 *             shop as normal.
 *
 *   batch     An expiry batch. Always targets a list of products, and carries
 *             `expiry_ym` (YYYYMM). Once that month has passed the discount
 *             ends AND the products are hidden from the shop.
 */
class Rules {

	public const TYPE_CAMPAIGN = 'campaign';
	public const TYPE_BATCH    = 'batch';

	public const SCOPE_ALL        = 'all';
	public const SCOPE_PRODUCTS   = 'products';
	public const SCOPE_CATEGORIES = 'categories';

	public const ITEM_PRODUCT  = 'product';
	public const ITEM_CATEGORY = 'category';

	/**
	 * Products a rule must skip even though its scope would otherwise cover
	 * them. A store-wide campaign needs this: the shop deliberately keeps a few
	 * products out of the blanket discount, and importing without that list
	 * would quietly start discounting them.
	 */
	public const ITEM_EXCLUDE = 'exclude_product';

	/**
	 * One rule, with its product and category lists attached.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		$table = Install::table( Install::TABLE_RULES );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Rules matching the given filters, ordered so the highest priority number
	 * comes last — the same order the old plugin resolved in, which keeps the
	 * imported behaviour identical.
	 *
	 * @param array<string,mixed> $args type, enabled, scope.
	 * @return array<int,array<string,mixed>>
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;

		$table  = Install::table( Install::TABLE_RULES );
		$where  = array( '1=1' );
		$params = array();

		if ( isset( $args['type'] ) ) {
			$where[]  = 'type = %s';
			$params[] = (string) $args['type'];
		}

		if ( isset( $args['enabled'] ) ) {
			$where[]  = 'enabled = %d';
			$params[] = $args['enabled'] ? 1 : 0;
		}

		if ( isset( $args['scope'] ) ) {
			$where[]  = 'scope = %s';
			$params[] = (string) $args['scope'];
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY priority ASC, id ASC';

		$rows = $params
			? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A )
			: $wpdb->get_results( $sql, ARRAY_A );

		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * Creates a rule and its item list.
	 *
	 * @param array<string,mixed> $data Rule fields, plus optional
	 *                                  `products` and `categories` arrays.
	 * @return int New rule ID, or 0 on failure.
	 */
	public static function create( array $data ): int {
		global $wpdb;

		$now  = current_time( 'mysql' );
		$row  = self::sanitize( $data );
		$row['date_created']  = $now;
		$row['date_modified'] = $now;

		$inserted = $wpdb->insert( Install::table( Install::TABLE_RULES ), $row );

		if ( ! $inserted ) {
			return 0;
		}

		$rule_id = (int) $wpdb->insert_id;

		self::set_items( $rule_id, $data );

		return $rule_id;
	}

	/**
	 * Updates a rule. Item lists are only replaced when they are supplied.
	 *
	 * @param array<string,mixed> $data Fields to change.
	 */
	public static function update( int $id, array $data ): bool {
		global $wpdb;

		$row = self::sanitize( $data, true );

		// isset() with several arguments is true only when they are all set, so
		// this used to abandon an update that changed nothing but the product
		// list — silently, returning false as though there had been nothing to
		// do. Each list has to be tested on its own.
		$has_lists = isset( $data['products'] ) || isset( $data['categories'] ) || isset( $data['excluded'] );

		if ( $row === array() && ! $has_lists ) {
			return false;
		}

		if ( $row !== array() ) {
			$row['date_modified'] = current_time( 'mysql' );

			$wpdb->update( Install::table( Install::TABLE_RULES ), $row, array( 'id' => $id ) );
		}

		if ( isset( $data['products'] ) || isset( $data['categories'] ) || isset( $data['excluded'] ) ) {
			self::set_items( $id, $data );
		}

		return true;
	}

	/**
	 * Deletes a rule and its items.
	 */
	public static function delete( int $id ): bool {
		global $wpdb;

		$wpdb->delete( Install::table( Install::TABLE_ITEMS ), array( 'rule_id' => $id ) );

		// The picture and the count a product kept for this batch are about a
		// pairing that no longer exists. Left behind they are invisible and
		// inert, and they pile up: every delete-and-import cycle would add a
		// fresh set nobody can see or reach.
		Variations::forget_batch( $id );

		return (bool) $wpdb->delete( Install::table( Install::TABLE_RULES ), array( 'id' => $id ) );
	}

	/**
	 * Replaces the product and/or category list for a rule.
	 *
	 * @param array<string,mixed> $data Payload holding `products`/`categories`.
	 */
	public static function set_items( int $rule_id, array $data ): void {
		global $wpdb;

		$table = Install::table( Install::TABLE_ITEMS );

		$map = array(
			self::ITEM_PRODUCT  => 'products',
			self::ITEM_CATEGORY => 'categories',
			self::ITEM_EXCLUDE  => 'excluded',
		);

		foreach ( $map as $item_type => $key ) {
			if ( ! isset( $data[ $key ] ) ) {
				continue;
			}

			$wpdb->delete(
				$table,
				array(
					'rule_id'   => $rule_id,
					'item_type' => $item_type,
				)
			);

			$ids = array_unique( array_filter( array_map( 'intval', (array) $data[ $key ] ) ) );

			foreach ( $ids as $item_id ) {
				$wpdb->insert(
					$table,
					array(
						'rule_id'   => $rule_id,
						'item_type' => $item_type,
						'item_id'   => $item_id,
					)
				);
			}
		}
	}

	/**
	 * The item IDs attached to a rule.
	 *
	 * @return int[]
	 */
	public static function items( int $rule_id, string $item_type ): array {
		global $wpdb;

		$table = Install::table( Install::TABLE_ITEMS );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT item_id FROM {$table} WHERE rule_id = %d AND item_type = %s",
				$rule_id,
				$item_type
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Every product ID that sits in an enabled expiry batch. The campaign side
	 * subtracts these, because a product is either in a batch or in a campaign,
	 * never both.
	 *
	 * @return int[]
	 */
	public static function batch_product_ids(): array {
		global $wpdb;

		$rules = Install::table( Install::TABLE_RULES );
		$items = Install::table( Install::TABLE_ITEMS );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT i.item_id
				   FROM {$items} i
				   INNER JOIN {$rules} r ON r.id = i.rule_id
				  WHERE r.type = %s
				    AND r.enabled = 1
				    AND i.item_type = %s",
				self::TYPE_BATCH,
				self::ITEM_PRODUCT
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Which batches each of the given products belongs to.
	 *
	 * One query for the whole page, rather than one per row.
	 *
	 * @param int[] $product_ids Products to look up.
	 * @return array<int,int[]> Product ID => batch rule IDs.
	 */
	public static function batch_map_for_products( array $product_ids ): array {
		return self::rule_map_for_products( $product_ids, self::TYPE_BATCH );
	}

	/**
	 * The same, for campaigns.
	 *
	 * @param int[] $product_ids Products to look up.
	 * @return array<int,int[]> Product id => campaign ids.
	 */
	public static function campaign_map_for_products( array $product_ids ): array {
		return self::rule_map_for_products( $product_ids, self::TYPE_CAMPAIGN );
	}

	/**
	 * Which campaigns each product has been kept out of.
	 *
	 * @param int[] $product_ids Products to look up.
	 * @return array<int,int[]> Product id => campaign ids it is excluded from.
	 */
	public static function exclusion_map_for_products( array $product_ids ): array {
		return self::rule_map_for_products( $product_ids, self::TYPE_CAMPAIGN, self::ITEM_EXCLUDE );
	}

	/**
	 * Which rules of one kind each product is named in.
	 *
	 * @param int[]  $product_ids Products to look up.
	 * @param string $type        Campaign or batch.
	 * @param string $item_type   Membership, or the exclusion list.
	 * @return array<int,int[]> Product id => rule ids.
	 */
	private static function rule_map_for_products( array $product_ids, string $type, string $item_type = self::ITEM_PRODUCT ): array {
		global $wpdb;

		$product_ids = array_values( array_unique( array_map( 'intval', $product_ids ) ) );

		if ( $product_ids === array() ) {
			return array();
		}

		$rules        = Install::table( Install::TABLE_RULES );
		$items        = Install::table( Install::TABLE_ITEMS );
		$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

		$sql = $wpdb->prepare(
			"SELECT i.item_id, i.rule_id
			   FROM {$items} i
			   INNER JOIN {$rules} r ON r.id = i.rule_id
			  WHERE r.type = %s
			    AND i.item_type = %s
			    AND i.item_id IN ({$placeholders})",
			array_merge( array( $type, $item_type ), $product_ids )
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$map  = array();

		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['item_id'] ][] = (int) $row['rule_id'];
		}

		return $map;
	}

	/**
	 * Sets which rules one product belongs to — batches, campaigns, or both.
	 *
	 * `$managed` is the crucial argument: only those rules are touched. The
	 * assignment screen can leave rules off the page — an expired batch, a
	 * campaign that covers the whole shop — and without this a product's
	 * membership of one of them would be wiped simply because it was not shown.
	 *
	 * @param int    $product_id Product to change.
	 * @param int[]  $checked    Rule IDs it should belong to.
	 * @param int[]  $managed    Rule IDs this call is allowed to change.
	 * @param string $item_type  Membership, or the exclusion list.
	 * @return bool Whether anything changed.
	 */
	public static function set_product_rules( int $product_id, array $checked, array $managed, string $item_type = self::ITEM_PRODUCT ): bool {
		global $wpdb;

		$managed = array_map( 'intval', $managed );
		$checked = array_intersect( array_map( 'intval', $checked ), $managed );

		if ( $managed === array() ) {
			return false;
		}

		$table = Install::table( Install::TABLE_ITEMS );

		// Read what the product is in straight from the rules being managed,
		// rather than from a list of one kind. The caller has already decided
		// which rules this save is allowed to touch, and campaigns are assigned
		// from the same screen as batches now.
		$current = array_intersect( self::product_is_in( $product_id, $managed, $item_type ), $managed );

		$add    = array_diff( $checked, $current );
		$remove = array_diff( $current, $checked );

		foreach ( $remove as $rule_id ) {
			$wpdb->delete(
				$table,
				array(
					'rule_id'   => $rule_id,
					'item_type' => $item_type,
					'item_id'   => $product_id,
				)
			);
		}

		foreach ( $add as $rule_id ) {
			$wpdb->insert(
				$table,
				array(
					'rule_id'   => $rule_id,
					'item_type' => $item_type,
					'item_id'   => $product_id,
				)
			);
		}

		return $add !== array() || $remove !== array();
	}

	/**
	 * Which of these rules the product is already named in.
	 *
	 * @param int[]  $rule_ids  Rules to check against.
	 * @param string $item_type Membership, or the exclusion list.
	 * @return int[]
	 */
	private static function product_is_in( int $product_id, array $rule_ids, string $item_type = self::ITEM_PRODUCT ): array {
		global $wpdb;

		$rule_ids = array_values( array_unique( array_map( 'intval', $rule_ids ) ) );

		if ( $rule_ids === array() ) {
			return array();
		}

		$items        = Install::table( Install::TABLE_ITEMS );
		$placeholders = implode( ',', array_fill( 0, count( $rule_ids ), '%d' ) );

		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT rule_id FROM {$items}
					  WHERE item_type = %s AND item_id = %d
					    AND rule_id IN ({$placeholders})",
					array_merge( array( $item_type, $product_id ), $rule_ids )
				)
			)
		);
	}

	/**
	 * The batches one product belongs to.
	 *
	 * @return int[]
	 */
	public static function batches_for_product( int $product_id ): array {
		$map = self::batch_map_for_products( array( $product_id ) );

		return $map[ $product_id ] ?? array();
	}

	/**
	 * How many rules exist, split by type. Used by the admin screens.
	 *
	 * @return array{campaign:int,batch:int}
	 */
	public static function counts(): array {
		global $wpdb;

		$table = Install::table( Install::TABLE_RULES );

		$rows = $wpdb->get_results( "SELECT type, COUNT(*) AS total FROM {$table} GROUP BY type", ARRAY_A );

		$counts = array(
			self::TYPE_CAMPAIGN => 0,
			self::TYPE_BATCH    => 0,
		);

		foreach ( (array) $rows as $row ) {
			$counts[ $row['type'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * The last day of an expiry month, as a timestamp in the site's timezone.
	 *
	 * Pharmaceutical expiry is printed as a month, not a day — "08/2026" means
	 * the stock is good until the end of August. So a batch stores YYYYMM and
	 * expires at the final second of that month.
	 */
	public static function expiry_end_timestamp( string $expiry_ym ): ?int {
		if ( ! preg_match( '/^(\d{4})(\d{2})$/', $expiry_ym, $m ) ) {
			return null;
		}

		$year  = (int) $m[1];
		$month = (int) $m[2];

		if ( $month < 1 || $month > 12 ) {
			return null;
		}

		$last_day = (int) gmdate( 't', (int) gmmktime( 0, 0, 0, $month, 1, $year ) );

		$date = new \DateTimeImmutable(
			sprintf( '%04d-%02d-%02d 23:59:59', $year, $month, $last_day ),
			wp_timezone()
		);

		return $date->getTimestamp();
	}

	/**
	 * True when the batch's expiry month has already passed.
	 */
	public static function is_batch_expired( array $rule ): bool {
		if ( $rule['type'] !== self::TYPE_BATCH || empty( $rule['expiry_ym'] ) ) {
			return false;
		}

		$end = self::expiry_end_timestamp( (string) $rule['expiry_ym'] );

		return $end !== null && time() > $end;
	}

	/**
	 * Turns raw input into columns we are willing to write.
	 *
	 * @param array<string,mixed> $data    Incoming values.
	 * @param bool                $partial When true, only supplied keys come back.
	 * @return array<string,mixed>
	 */
	private static function sanitize( array $data, bool $partial = false ): array {
		$out = array();

		$set = static function ( string $key, $value ) use ( &$out ): void {
			$out[ $key ] = $value;
		};

		if ( ! $partial || isset( $data['type'] ) ) {
			$type = ( $data['type'] ?? self::TYPE_CAMPAIGN ) === self::TYPE_BATCH ? self::TYPE_BATCH : self::TYPE_CAMPAIGN;
			$set( 'type', $type );
		}

		if ( ! $partial || isset( $data['title'] ) ) {
			$set( 'title', sanitize_text_field( (string) ( $data['title'] ?? '' ) ) );
		}

		if ( ! $partial || isset( $data['enabled'] ) ) {
			$set( 'enabled', ! empty( $data['enabled'] ) ? 1 : 0 );
		}

		if ( ! $partial || isset( $data['discount_percent'] ) ) {
			$percent = (float) ( $data['discount_percent'] ?? 0 );
			$set( 'discount_percent', max( 0.0, min( 100.0, $percent ) ) );
		}

		if ( ! $partial || isset( $data['scope'] ) ) {
			$scope = (string) ( $data['scope'] ?? self::SCOPE_PRODUCTS );
			$set( 'scope', in_array( $scope, array( self::SCOPE_ALL, self::SCOPE_PRODUCTS, self::SCOPE_CATEGORIES ), true ) ? $scope : self::SCOPE_PRODUCTS );
		}

		if ( ! $partial || array_key_exists( 'expiry_ym', $data ) ) {
			$ym = (string) ( $data['expiry_ym'] ?? '' );
			$set( 'expiry_ym', preg_match( '/^\d{6}$/', $ym ) ? $ym : null );
		}

		if ( ! $partial || array_key_exists( 'display_label', $data ) ) {
			$set( 'display_label', sanitize_text_field( (string) ( $data['display_label'] ?? '' ) ) );
		}

		if ( ! $partial || array_key_exists( 'ends_at', $data ) ) {
			$ends = (string) ( $data['ends_at'] ?? '' );
			$set( 'ends_at', $ends !== '' ? $ends : null );
		}

		if ( ! $partial || isset( $data['countdown_enabled'] ) ) {
			$set( 'countdown_enabled', ! empty( $data['countdown_enabled'] ) ? 1 : 0 );
		}

		if ( ! $partial || isset( $data['priority'] ) ) {
			$set( 'priority', (int) ( $data['priority'] ?? 10 ) );
		}

		if ( ! $partial || isset( $data['source'] ) ) {
			$set( 'source', sanitize_key( (string) ( $data['source'] ?? 'manual' ) ) );
		}

		if ( ! $partial || array_key_exists( 'notes', $data ) ) {
			$set( 'notes', sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ) );
		}

		return $out;
	}

	/**
	 * Casts a database row and attaches its item lists.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	private static function hydrate( array $row ): array {
		$row['id']                = (int) $row['id'];
		$row['enabled']           = (bool) $row['enabled'];
		$row['discount_percent']  = (float) $row['discount_percent'];
		$row['countdown_enabled'] = (bool) $row['countdown_enabled'];
		$row['priority']          = (int) $row['priority'];

		$row['products']   = self::items( $row['id'], self::ITEM_PRODUCT );
		$row['categories'] = self::items( $row['id'], self::ITEM_CATEGORY );
		$row['excluded']   = self::items( $row['id'], self::ITEM_EXCLUDE );

		return $row;
	}
}
