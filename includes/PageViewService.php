<?php

namespace MediaWiki\Extension\PageViewInfo;

use StatusValue;
use Title;

/**
 * PageViewService provides an abstraction for different methods to access pageview data
 * (HitCounter extension DB tables, Piwik API, Google Analytics API etc).
 */
interface PageViewService {
	/** Page view count */
	public const METRIC_VIEW = 'view';
	/** Unique visitors (devices) for some period, typically last 30 days */
	public const METRIC_UNIQUE = 'unique';

	/** Return data for a given article */
	public const SCOPE_ARTICLE = 'article';
	/** Return a list of the top articles */
	public const SCOPE_TOP = 'top';
	/** Return data for the whole site */
	public const SCOPE_SITE = 'site';

	/**
	 * Whether the service can provide data for the given metric/scope combination.
	 * @param string $metric One of the METRIC_* constants.
	 * @param string $scope One of the METRIC_* constants.
	 * @return bool
	 */
	public function supports( $metric, $scope );

	/**
	 * Returns an array of daily counts for the last $days days, in the format
	 *   title => [ date => count, ... ]
	 * where date is in ISO format (YYYY-MM-DD). Which time zone to use is left to the implementation
	 * (although UTC is the recommended one, unless the site has a very narrow audience). Exactly
	 * which days are returned is also up to the implentation; recent days with incomplete data
	 * should be omitted. (Typically that means that the returned date range will end with the
	 * previous day, but given a sufficiently slow backend, the last full day for which data is
	 * available and the last full calendar day might not be the same thing).
	 * Count will be null when there is no data or there was an error. The order of titles will be
	 * the same as in the parameter $titles, but some implementations might return fewer titles than
	 * requested, if fetching more data is considered too expensive. In that case the returned data
	 * will be for a prefix slice of the $titles array.
	 * @param Title[] $titles
	 * @param int $days The number of days.
	 * @param string $metric One of the METRIC_* constants.
	 * @return StatusValue A status object with the data. Its success property will contain
	 *   per-title success information.
	 */
	public function getPageData( array $titles, $days, $metric = self::METRIC_VIEW );

	/**
	 * Returns an array of total daily counts for the whole site, in the format
	 *   date => count
	 * where date is in ISO format (YYYY-MM-DD). The same considerations apply as for getPageData().
	 * @param int $days The number of days.
	 * @param string $metric One of the METRIC_* constants.
	 * @return StatusValue A status object with the data.
	 */
	public function getSiteData( $days, $metric = self::METRIC_VIEW );

	/**
	 * Returns a list of the top pages according to some metric, sorted in descending order
	 * by that metric, in
	 *   title => count
	 * format (where title has the same format as Title::getPrefixedDBKey()).
	 * @param string $metric One of the METRIC_* constants.
	 * @return StatusValue A status object with the data.
	 */
	public function getTopPages( $metric = self::METRIC_VIEW );

	/**
	 * Returns the length of time for which it is acceptable to cache the results.
	 * Typically this would be the end of the current day in whatever timezone the data is in.
	 * @param string $metric One of the METRIC_* constants.
	 * @param string $scope One of the METRIC_* constants.
	 * @return int Time in seconds
	 */
	public function getCacheExpiry( $metric, $scope );
}
