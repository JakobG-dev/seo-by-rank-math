<?php
/**
 * The Analytics module database operations
 *
 * @since      0.9.0
 * @package    RankMath
 * @subpackage RankMath\modules
 * @author     Rank Math <support@rankmath.com>
 */

namespace RankMath\Analytics;

use RankMath\Helper;
use MyThemeShop\Helpers\Str;
use MyThemeShop\Database\Database;

defined( 'ABSPATH' ) || exit;

/**
 * DB class.
 */
class DB {

	/**
	 * Get any table.
	 *
	 * @param string $table_name Table name.
	 *
	 * @return \MyThemeShop\Database\Query_Builder
	 */
	public static function table( $table_name ) {
		return Database::table( $table_name );
	}

	/**
	 * Get analytics table.
	 *
	 * @return \MyThemeShop\Database\Query_Builder
	 */
	public static function analytics() {
		return Database::table( 'rank_math_analytics_gsc' );
	}

	/**
	 * Get traffic table.
	 *
	 * @return \MyThemeShop\Database\Query_Builder
	 */
	public static function traffic() {
		return Database::table( 'rank_math_analytics_ga' );
	}

	/**
	 * Get adsense table.
	 *
	 * @return \MyThemeShop\Database\Query_Builder
	 */
	public static function adsense() {
		return Database::table( 'rank_math_analytics_adsense' );
	}

	/**
	 * Get objects table.
	 *
	 * @return \MyThemeShop\Database\Query_Builder
	 */
	public static function objects() {
		return Database::table( 'rank_math_analytics_objects' );
	}

	/**
	 * Get links table.
	 *
	 * @return \MyThemeShop\Database\Query_Builder
	 */
	public static function links() {
		return Database::table( 'rank_math_internal_meta' );
	}

	/**
	 * Get keywords table.
	 *
	 * @return \MyThemeShop\Database\Query_Builder
	 */
	public static function keywords() {
		return Database::table( 'rank_math_analytics_keyword_manager' );
	}

	/**
	 * Delete a record.
	 *
	 * @param  int $days Decide whether to delete all or delete 90 days data.
	 */
	public static function delete_by_days( $days ) {
		if ( -1 === $days ) {
			self::traffic()->truncate();
			self::analytics()->truncate();
		} else {
			$start = date_i18n( 'Y-m-d H:i:s', strtotime( '-1 days' ) );
			$end   = date_i18n( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days' ) );

			self::traffic()->whereBetween( 'created', [ $end, $start ] )->delete();
			self::analytics()->whereBetween( 'created', [ $end, $start ] )->delete();
		}
		self::purge_cache();

		return true;
	}

	/**
	 * Delete record for comparison.
	 */
	public static function delete_data_log() {
		$days = Helper::get_settings( 'general.console_caching_control', 90 );

		$start = date_i18n( 'Y-m-d H:i:s', strtotime( '-' . ( $days * 2 ) . ' days' ) );

		self::traffic()->where( 'created', '<', $start )->delete();
		self::analytics()->where( 'created', '<', $start )->delete();
	}

	/**
	 * Purge SC transient
	 */
	public static function purge_cache() {
		$table = Database::table( 'options' );
		$table->whereLike( 'option_name', 'rank_math_analytics_data_info' )->delete();
		$table->whereLike( 'option_name', 'tracked_keywords_summary' )->delete();
		$table->whereLike( 'option_name', 'top_keywords' )->delete();
		$table->whereLike( 'option_name', 'top_keywords_graph' )->delete();
		$table->whereLike( 'option_name', 'winning_keywords' )->delete();
		$table->whereLike( 'option_name', 'losing_keywords' )->delete();
		$table->whereLike( 'option_name', 'posts_summary' )->delete();
		$table->whereLike( 'option_name', 'winning_posts' )->delete();
		$table->whereLike( 'option_name', 'losing_posts' )->delete();

		wp_cache_flush();
	}

	/**
	 * Get search console table info.
	 *
	 * @return array
	 */
	public static function info() {
		global $wpdb;

		$key  = 'rank_math_analytics_data_info';
		$data = get_transient( $key );
		if ( false !== $data ) {
			return $data;
		}

		$days = self::analytics()
			->selectCount( 'DISTINCT(created)', 'days' )
			->getVar();

		$rows = self::analytics()
			->selectCount( 'id' )
			->getVar();

		$size = $wpdb->get_var( 'SELECT SUM((data_length + index_length)) AS size FROM information_schema.TABLES WHERE table_schema="' . $wpdb->dbname . '" AND (table_name="' . $wpdb->prefix . 'rank_math_analytics_gsc")' ); // phpcs:ignore

		$data = compact( 'days', 'rows', 'size' );

		set_transient( $key, $data, DAY_IN_SECONDS );

		return $data;
	}

	/**
	 * Has data pulled.
	 *
	 * @return boolean
	 */
	public static function has_data() {
		static $rank_math_gsc_has_data;
		if ( isset( $rank_math_gsc_has_data ) ) {
			return $rank_math_gsc_has_data;
		}

		$id = self::objects()
			->select( 'id' )
			->limit( 1 )
			->getVar();

		$rank_math_gsc_has_data = $id > 0 ? true : false;
		return $rank_math_gsc_has_data;
	}

	/**
	 * Check if a date exists in the sysyem.
	 *
	 * @param string $date Date.
	 *
	 * @return boolean
	 */
	public static function date_exists( $date ) {
		$id = self::analytics()
			->select( 'id' )
			->where( 'created', $date )
			->getVar();

		return $id > 0 ? true : false;
	}

	/**
	 * Add a new record.
	 *
	 * @param array $args Values to insert.
	 *
	 * @return bool|int
	 */
	public static function add_object( $args = [] ) {
		if ( empty( $args ) ) {
			return false;
		}

		$args = wp_parse_args(
			$args,
			[
				'created'        => current_time( 'mysql' ),
				'page'           => '',
				'object_type'    => 'post',
				'object_subtype' => 'post',
				'object_id'      => 0,
				'primary_key'    => '',
				'seo_score'      => 0,
				'page_score'     => 0,
				'is_indexable'   => false,
				'schemas_in_use' => '',
			]
		);

		return self::objects()->insert( $args, [ '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s' ] );
	}

	/**
	 * Update a record.
	 *
	 * @param array $args Values to update.
	 *
	 * @return bool|int
	 */
	public static function update_object( $args = [] ) {
		if ( empty( $args ) ) {
			return false;
		}

		$old_id = absint( $args['id'] );
		if ( ! empty( $old_id ) ) {
			unset( $args['id'] );

			$updated = self::objects()->set( $args )
				->where( 'id', $old_id )
				->where( 'object_id', absint( $args['object_id'] ) )
				->update();

			if ( ! empty( $updated ) ) {
				return $old_id;
			}
		}

		return self::add_object( $args );
	}

	/**
	 * Add console records.
	 *
	 * @param string $date Date of creation.
	 * @param array  $rows Data rows to insert.
	 */
	public static function add_query_page_bulk( $date, $rows ) {
		$chunks = array_chunk( $rows, 50 );

		foreach ( $chunks as $chunk ) {
			self::bulk_insert_query_page_data( $date . ' 00:00:00', $chunk );
		}
	}

	/**
	 * Bulk inserts records into a table using WPDB.  All rows must contain the same keys.
	 *
	 * @param  string $date        Date.
	 * @param  array  $rows        Rows to insert.
	 */
	public static function bulk_insert_query_page_data( $date, $rows ) {
		global $wpdb;

		$data         = [];
		$placeholders = [];
		$columns      = [
			'created',
			'query',
			'page',
			'clicks',
			'impressions',
			'position',
			'ctr',
		];
		$columns      = '`' . implode( '`, `', $columns ) . '`';
		$placeholder  = [
			'%s',
			'%s',
			'%s',
			'%d',
			'%d',
			'%d',
			'%d',
		];

		// Start building SQL, initialise data and placeholder arrays.
		$sql = "INSERT INTO `{$wpdb->prefix}rank_math_analytics_gsc` ( $columns ) VALUES\n";

		// Build placeholders for each row, and add values to data array.
		foreach ( $rows as $row ) {
			if (
				$row['position'] > self::get_position_filter() ||
				Str::contains( '?', $row['page'] )
			) {
				continue;
			}

			$data[] = $date;
			$data[] = $row['query'];
			$data[] = Stats::get_relative_url( self::remove_hash( $row['page'] ) );
			$data[] = $row['clicks'];
			$data[] = $row['impressions'];
			$data[] = $row['position'];
			$data[] = $row['ctr'];

			$placeholders[] = '(' . implode( ', ', $placeholder ) . ')';
		}

		// Stitch all rows together.
		$sql .= implode( ",\n", $placeholders );

		// Run the query.  Returns number of affected rows.
		return $wpdb->query( $wpdb->prepare( $sql, $data ) ); // phpcs:ignore
	}

	/**
	 * Add analytic records.
	 *
	 * @param string $date Date of creation.
	 * @param array  $rows Data rows to insert.
	 */
	public static function add_analytics_bulk( $date, $rows ) {
		$chunks = array_chunk( $rows, 50 );

		foreach ( $chunks as $chunk ) {
			self::bulk_insert_analytics_data( $date . ' 00:00:00', $chunk );
		}
	}

	/**
	 * Bulk inserts records into a table using WPDB.  All rows must contain the same keys.
	 *
	 * @param  string $date        Date.
	 * @param  array  $rows        Rows to insert.
	 */
	public static function bulk_insert_analytics_data( $date, $rows ) {
		global $wpdb;

		$data         = [];
		$placeholders = [];
		$columns      = [
			'created',
			'page',
			'pageviews',
			'visitors',
		];
		$columns      = '`' . implode( '`, `', $columns ) . '`';
		$placeholder  = [
			'%s',
			'%s',
			'%d',
			'%d',
		];

		// Start building SQL, initialise data and placeholder arrays.
		$sql = "INSERT INTO `{$wpdb->prefix}rank_math_analytics_ga` ( $columns ) VALUES\n";

		// Build placeholders for each row, and add values to data array.
		foreach ( $rows as $row ) {
			if ( empty( $row['dimensions'][1] ) || Str::contains( '?', $row['dimensions'][1] ) ) {
				continue;
			}

			$data[] = $date;
			$data[] = self::remove_hash( $row['dimensions'][1] );
			$data[] = $row['metrics'][0]['values'][0];
			$data[] = $row['metrics'][0]['values'][1];

			$placeholders[] = '(' . implode( ', ', $placeholder ) . ')';
		}

		// Stitch all rows together.
		$sql .= implode( ",\n", $placeholders );

		// Run the query.  Returns number of affected rows.
		return $wpdb->query( $wpdb->prepare( $sql, $data ) ); // phpcs:ignore
	}

	/**
	 * Remove hash part.
	 *
	 * @param  string $url Url to process.
	 * @return string
	 */
	public static function remove_hash( $url ) {
		if ( ! Str::contains( '#', $url ) ) {
			return $url;
		}

		$url = \explode( '#', $url );
		return $url[0];
	}

	/**
	 * Add adsense records.
	 *
	 * @param string $date Date of creation.
	 * @param array  $rows Data rows to insert.
	 */
	public static function add_adsense( $date, $rows ) {
		global $wpdb;

		foreach ( $rows as $row ) {
			$earnings = floatval( $row[1] );
			if ( empty( $earnings ) ) {
				continue;
			}

			self::adsense()
				->insert(
					[
						'created'  => $date . ' 00:00:00',
						'earnings' => $earnings,
					],
					[ '%s', '%f' ]
				);
		}
	}

	/**
	 * Get position filter.
	 *
	 * @return int
	 */
	private static function get_position_filter() {
		$number = apply_filters( 'rank_math/analytics/position_limit', false );
		if ( false === $number ) {
			return 100;
		}

		return $number;
	}
}
