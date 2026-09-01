<?php
/**
 * Moving rules between sites.
 *
 * @package WooCustomDiscount
 */

declare( strict_types = 1 );

namespace WCD;

defined( 'ABSPATH' ) || exit;

/**
 * Exports rules to a file, and reads them back on another site.
 *
 * The hard part is not the file — it is that a product's ID means nothing on a
 * different database. Two sites restored from the same backup will agree, but
 * one product added on live since then shifts nothing and one deleted shifts
 * everything after it, and a rule that quietly lands on the wrong product is
 * worse than one that fails loudly.
 *
 * So every product is written out three ways — SKU, ID and name — and matched
 * back in that order, with the name used to confirm an ID match rather than
 * trust it. Anything that cannot be matched is reported rather than skipped in
 * silence.
 */
class Exporter {

	/** Bumped if the file layout ever changes incompatibly. */
	public const FORMAT = 1;

	/** Marks rules that came from a file, so a re-import can replace them. */
	public const SOURCE_TAG = 'file_import';

	/**
	 * Everything worth carrying to another site.
	 *
	 * @param bool $with_settings Whether to include the filter configuration.
	 * @return array<string,mixed>
	 */
	public static function build( bool $with_settings = true ): array {
		$payload = array(
			'format'         => self::FORMAT,
			'plugin_version' => WCD_VERSION,
			'exported_at'    => gmdate( 'c' ),
			'source_site'    => home_url(),
			'rules'          => array(),
		);

		foreach ( array( Rules::TYPE_BATCH, Rules::TYPE_CAMPAIGN ) as $type ) {
			foreach ( Rules::query( array( 'type' => $type ) ) as $rule ) {
				$payload['rules'][] = self::describe_rule( $rule );
			}
		}

		if ( $with_settings ) {
			$payload['settings'] = self::exportable_settings();
		}

		return $payload;
	}

	/**
	 * One rule, with its lists written in a portable form.
	 *
	 * @param array<string,mixed> $rule Rule row.
	 * @return array<string,mixed>
	 */
	private static function describe_rule( array $rule ): array {
		return array(
			'type'              => $rule['type'],
			'title'             => $rule['title'],
			'enabled'           => (bool) $rule['enabled'],
			'discount_percent'  => (float) $rule['discount_percent'],
			'scope'             => $rule['scope'],
			'expiry_ym'         => $rule['expiry_ym'],
			'display_label'     => (string) ( $rule['display_label'] ?? '' ),
			'badge'             => (string) ( $rule['badge'] ?? '' ),
			'free_extras'       => (int) ( $rule['free_extras'] ?? 0 ),
			'ends_at'           => $rule['ends_at'],
			'countdown_enabled' => (bool) $rule['countdown_enabled'],
			'priority'          => (int) $rule['priority'],
			'notes'             => (string) $rule['notes'],
			'products'          => self::describe_products(
				$rule['products'],
				$rule['type'] === Rules::TYPE_BATCH ? (int) $rule['id'] : 0
			),
			'excluded'          => self::describe_products( $rule['excluded'] ),
			'categories'        => self::describe_categories( $rule['categories'] ),
		);
	}

	/**
	 * Products written three ways, so the other site has a chance of matching.
	 *
	 * @param int[] $ids Product IDs.
	 * @return array<int,array<string,mixed>>
	 */
	private static function describe_products( array $ids, int $batch_id = 0 ): array {
		$out = array();

		// A batch's pictures and counts belong to the pairing of product and
		// batch, so they travel with the product inside the batch — not as a
		// separate list that would have to be matched up again at the other end.
		$images = array();
		$stock  = array();

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );

			if ( ! $product ) {
				continue;
			}

			$entry = array(
				'id'   => (int) $id,
				'sku'  => (string) $product->get_sku(),
				'name' => $product->get_name(),
			);

			if ( $batch_id > 0 ) {
				if ( ! isset( $images[ $id ] ) ) {
					$images[ $id ] = Variations::images_for( (int) $id );
					$stock[ $id ]  = Variations::stock_for( (int) $id );
				}

				if ( isset( $images[ $id ][ $batch_id ] ) ) {
					$entry['image'] = self::describe_image( (int) $images[ $id ][ $batch_id ] );
				}

				if ( isset( $stock[ $id ][ $batch_id ] ) ) {
					$entry['stock'] = (int) $stock[ $id ][ $batch_id ];
				}
			}

			$out[] = $entry;
		}

		return $out;
	}

	/**
	 * A picture written so the other site can find its own copy of it.
	 *
	 * The attachment ID is right on the same site and meaningless on another one,
	 * where it would point at whatever image happens to hold that number. The file
	 * name is the part that survives the crossing, so both go, and the importer
	 * only trusts the ID when the file name agrees with it.
	 *
	 * @return array<string,mixed>
	 */
	private static function describe_image( int $attachment_id ): array {
		$file = get_post_meta( $attachment_id, '_wp_attached_file', true );

		return array(
			'id'   => $attachment_id,
			'file' => is_string( $file ) ? basename( $file ) : '',
		);
	}

	/**
	 * Finds an exported picture in this site's media library.
	 *
	 * @param array<string,mixed> $entry Exported image record.
	 * @return int Attachment ID, or 0 when this site has no copy of it.
	 */
	private static function match_image( array $entry ): int {
		global $wpdb;

		$id   = (int) ( $entry['id'] ?? 0 );
		$file = (string) ( $entry['file'] ?? '' );

		if ( $id > 0 && get_post_type( $id ) === 'attachment' ) {
			$here = get_post_meta( $id, '_wp_attached_file', true );

			// On the same site both agree and this is the whole story. On another
			// site the number will almost always hold a different picture, and
			// disagreeing is exactly how we find that out.
			if ( $file === '' || ( is_string( $here ) && basename( $here ) === $file ) ) {
				return $id;
			}
		}

		if ( $file === '' ) {
			return 0;
		}

		$by_file = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				  WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s
				  ORDER BY post_id DESC LIMIT 1",
				'%' . $wpdb->esc_like( $file )
			)
		);

		return $by_file ? (int) $by_file : 0;
	}

	/**
	 * Categories by slug, which travels far better than a term ID.
	 *
	 * @param int[] $ids Term IDs.
	 * @return array<int,array<string,mixed>>
	 */
	private static function describe_categories( array $ids ): array {
		$out = array();

		foreach ( $ids as $id ) {
			$term = get_term( $id, 'product_cat' );

			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$out[] = array(
				'id'   => (int) $id,
				'slug' => $term->slug,
				'name' => $term->name,
			);
		}

		return $out;
	}

	/**
	 * The settings worth carrying over.
	 *
	 * The master switches are left out on purpose: whether the engine runs is a
	 * decision about the site being imported into, not something a file from
	 * another site should turn on.
	 *
	 * @return array<string,mixed>
	 */
	private static function exportable_settings(): array {
		$keys = array(
			'rounding',
			'filter_groups',
			'filter_display',
			'filter_align',
			'filter_position',
			'price_mode',
			'discount_buckets',
			'price_buckets',
			'expiry_months',
			'show_counts',
			'hide_empty',
		);

		$out = array();

		foreach ( $keys as $key ) {
			$out[ $key ] = Settings::get( $key );
		}

		return $out;
	}

	/**
	 * Reads a payload and reports what would happen, without writing anything.
	 *
	 * @param array<string,mixed> $payload Decoded file.
	 * @return array<string,mixed>
	 */
	public static function inspect( array $payload ): array {
		$report = array(
			'ok'          => false,
			'error'       => '',
			'source_site' => (string) ( $payload['source_site'] ?? '' ),
			'exported_at' => (string) ( $payload['exported_at'] ?? '' ),
			'rules'       => array(),
			'matched'     => 0,
			'unmatched'   => 0,
			'has_settings' => isset( $payload['settings'] ),
		);

		if ( (int) ( $payload['format'] ?? 0 ) !== self::FORMAT ) {
			$report['error'] = __( 'This file was made by a different version of the plugin and cannot be read.', 'woo-custom-discount' );

			return $report;
		}

		if ( ! isset( $payload['rules'] ) || ! is_array( $payload['rules'] ) ) {
			$report['error'] = __( 'There are no rules in this file.', 'woo-custom-discount' );

			return $report;
		}

		foreach ( $payload['rules'] as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$products   = self::match_products( (array) ( $rule['products'] ?? array() ) );
			$excluded   = self::match_products( (array) ( $rule['excluded'] ?? array() ) );
			$categories = self::match_categories( (array) ( $rule['categories'] ?? array() ) );

			$missing = array_merge( $products['missing'], $excluded['missing'], $categories['missing'] );

			$report['matched']   += count( $products['found'] ) + count( $excluded['found'] ) + count( $categories['found'] );
			$report['unmatched'] += count( $missing );

			$report['rules'][] = array(
				'type'       => (string) ( $rule['type'] ?? Rules::TYPE_CAMPAIGN ),
				'title'      => (string) ( $rule['title'] ?? '' ),
				'percent'    => (float) ( $rule['discount_percent'] ?? 0 ),
				'scope'      => (string) ( $rule['scope'] ?? Rules::SCOPE_PRODUCTS ),
				'expiry_ym'  => (string) ( $rule['expiry_ym'] ?? '' ),
				'products'   => $products['found'],
				'excluded'   => $excluded['found'],
				'categories' => $categories['found'],
				'missing'    => $missing,
				'raw'        => $rule,
			);
		}

		$report['ok'] = true;

		return $report;
	}

	/**
	 * Writes the rules described by a report.
	 *
	 * @param array<string,mixed> $report        Output of inspect().
	 * @param bool                $enable        Whether the rules start active.
	 * @param bool                $with_settings Whether to apply the settings too.
	 * @return array<string,int>
	 */
	public static function apply( array $report, bool $enable, bool $with_settings ): array {
		self::remove_previous_import();

		$created = array(
			Rules::TYPE_CAMPAIGN => 0,
			Rules::TYPE_BATCH    => 0,
		);

		foreach ( $report['rules'] as $rule ) {
			$raw  = $rule['raw'];
			$type = $rule['type'] === Rules::TYPE_BATCH ? Rules::TYPE_BATCH : Rules::TYPE_CAMPAIGN;

			$id = Rules::create(
				array(
					'type'              => $type,
					'title'             => $rule['title'],
					'enabled'           => $enable,
					'discount_percent'  => $rule['percent'],
					'scope'             => $rule['scope'],
					'expiry_ym'         => $rule['expiry_ym'],
					'display_label'     => (string) ( $raw['display_label'] ?? '' ),
					'badge'             => (string) ( $raw['badge'] ?? '' ),
					'free_extras'       => (int) ( $raw['free_extras'] ?? 0 ),
					'ends_at'           => $raw['ends_at'] ?? null,
					'countdown_enabled' => ! empty( $raw['countdown_enabled'] ),
					'priority'          => (int) ( $raw['priority'] ?? 10 ),
					'source'            => self::SOURCE_TAG,
					'notes'             => (string) ( $raw['notes'] ?? '' ),
					'products'          => wp_list_pluck( $rule['products'], 'id' ),
					'excluded'          => wp_list_pluck( $rule['excluded'], 'id' ),
					'categories'        => wp_list_pluck( $rule['categories'], 'id' ),
				)
			);

			if ( ! $id ) {
				continue;
			}

			++$created[ $type ];

			// The pictures and counts could only be written once the batch existed
			// here and had an ID of its own — the exported IDs belong to the site
			// the file came from, and on a round trip through a delete they are
			// gone even there.
			if ( $type === Rules::TYPE_BATCH ) {
				foreach ( $rule['products'] as $product ) {
					if ( isset( $product['image'] ) ) {
						Variations::set_image( (int) $product['id'], (int) $id, (int) $product['image'] );
					}

					if ( isset( $product['stock'] ) ) {
						Variations::set_stock( (int) $product['id'], (int) $id, (int) $product['stock'] );
					}
				}
			}
		}

		if ( $with_settings && isset( $report['settings'] ) && is_array( $report['settings'] ) ) {
			Settings::update( $report['settings'] );
		}

		Resolver::flush();
		Expiry::flush_cache();

		return $created;
	}

	/**
	 * Deletes rules a previous file import created, leaving everything else.
	 */
	public static function remove_previous_import(): int {
		global $wpdb;

		$table = Install::table( Install::TABLE_RULES );

		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE source = %s", self::SOURCE_TAG )
		);

		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			Rules::delete( $id );
		}

		return count( (array) $ids );
	}

	/**
	 * Finds each exported product on this site.
	 *
	 * @param array<int,array<string,mixed>> $entries Exported product records.
	 * @return array{found:array<int,array<string,mixed>>,missing:array<int,array<string,mixed>>}
	 */
	private static function match_products( array $entries ): array {
		$found   = array();
		$missing = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$sku  = (string) ( $entry['sku'] ?? '' );
			$name = (string) ( $entry['name'] ?? '' );
			$id   = (int) ( $entry['id'] ?? 0 );

			// Carried through the match so the batch can be given them once it
			// exists here and has an ID of its own.
			$extras = array();

			if ( isset( $entry['stock'] ) && $entry['stock'] !== '' ) {
				$extras['stock'] = (int) $entry['stock'];
			}

			if ( isset( $entry['image'] ) && is_array( $entry['image'] ) ) {
				$picture = self::match_image( $entry['image'] );

				if ( $picture > 0 ) {
					$extras['image'] = $picture;
				}
			}

			// 1. SKU. Unique by definition, and survives a different database.
			if ( $sku !== '' ) {
				$by_sku = wc_get_product_id_by_sku( $sku );

				if ( $by_sku ) {
					$found[] = $extras + array(
						'id'     => (int) $by_sku,
						'name'   => get_the_title( $by_sku ),
						'how'    => 'sku',
						'detail' => $sku,
					);

					continue;
				}
			}

			// 2. ID, but only when the product there looks like the same thing.
			// An ID that has been reused by a different product is the one way
			// this could silently discount the wrong item.
			if ( $id > 0 ) {
				$product = wc_get_product( $id );

				if ( $product ) {
					if ( self::names_match( $name, $product->get_name() ) ) {
						$found[] = $extras + array(
							'id'     => $id,
							'name'   => $product->get_name(),
							'how'    => 'id',
							'detail' => (string) $id,
						);

						continue;
					}

					$missing[] = array(
						'name'   => $name,
						'sku'    => $sku,
						'reason' => sprintf(
							/* translators: 1: product ID, 2: the name found there. */
							__( 'ID %1$d exists here but holds a different product (%2$s).', 'woo-custom-discount' ),
							$id,
							$product->get_name()
						),
					);

					continue;
				}
			}

			$missing[] = array(
				'name'   => $name,
				'sku'    => $sku,
				'reason' => $sku !== ''
					? sprintf(
						/* translators: %s: SKU. */
						__( 'No product with SKU %s, and no product at the exported ID.', 'woo-custom-discount' ),
						$sku
					)
					: __( 'No SKU was recorded, and no product exists at the exported ID.', 'woo-custom-discount' ),
			);
		}

		return array(
			'found'   => $found,
			'missing' => $missing,
		);
	}

	/**
	 * Finds each exported category, by slug first.
	 *
	 * @param array<int,array<string,mixed>> $entries Exported category records.
	 * @return array{found:array<int,array<string,mixed>>,missing:array<int,array<string,mixed>>}
	 */
	private static function match_categories( array $entries ): array {
		$found   = array();
		$missing = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$slug = (string) ( $entry['slug'] ?? '' );
			$name = (string) ( $entry['name'] ?? '' );

			$term = $slug !== '' ? get_term_by( 'slug', $slug, 'product_cat' ) : false;

			if ( ! $term && $name !== '' ) {
				$term = get_term_by( 'name', $name, 'product_cat' );
			}

			if ( $term instanceof \WP_Term ) {
				$found[] = array(
					'id'     => (int) $term->term_id,
					'name'   => $term->name,
					'how'    => 'slug',
					'detail' => $term->slug,
				);

				continue;
			}

			$missing[] = array(
				'name'   => $name !== '' ? $name : $slug,
				'sku'    => '',
				'reason' => __( 'No product category with this name here.', 'woo-custom-discount' ),
			);
		}

		return array(
			'found'   => $found,
			'missing' => $missing,
		);
	}

	/**
	 * Whether two product names are close enough to be the same product.
	 *
	 * Not an exact comparison: a name can pick up a stray space or have an
	 * entity decoded differently between sites, and refusing over that would
	 * send someone hunting for a problem that is not there.
	 */
	private static function names_match( string $a, string $b ): bool {
		$normalise = static function ( string $value ): string {
			$value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
			$value = strtolower( wp_strip_all_tags( $value ) );

			return (string) preg_replace( '/[^a-z0-9]+/', '', $value );
		};

		$a = $normalise( $a );
		$b = $normalise( $b );

		if ( $a === '' || $b === '' ) {
			return false;
		}

		if ( $a === $b ) {
			return true;
		}

		similar_text( $a, $b, $percent );

		return $percent >= 85.0;
	}
}
