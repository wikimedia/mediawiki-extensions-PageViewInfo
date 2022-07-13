<?php

namespace MediaWiki\Extension\PageViewInfo;

use BagOStuff;
use InvalidArgumentException;
use Message;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Status;
use StatusValue;
use Title;

/**
 * Wraps a PageViewService and caches the results.
 */
class CachedPageViewService implements PageViewService, LoggerAwareInterface {
	private const ERROR_EXPIRY = 1800;

	/** @var PageViewService */
	protected $service;

	/** @var BagOStuff */
	protected $cache;

	/** @var LoggerInterface */
	protected $logger;

	/** @var string Cache prefix, in case multiple instances of this service coexist */
	protected $prefix;

	/** @var int */
	protected $cachedDays = 30;

	public function __construct( PageViewService $service, BagOStuff $cache, string $prefix = '' ) {
		$this->service = $service;
		$this->logger = new NullLogger();
		$this->cache = $cache;
		$this->prefix = $prefix;
	}

	public function setLogger( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Set the number of days that will be cached. To avoid cache fragmentation, the inner service
	 * is always called with this number of days; if necessary, the response will be expanded with
	 * nulls.
	 * @param int $cachedDays
	 */
	public function setCachedDays( $cachedDays ) {
		$this->cachedDays = $cachedDays;
	}

	public function supports( $metric, $scope ) {
		return $this->service->supports( $metric, $scope );
	}

	public function getPageData( array $titles, $days, $metric = self::METRIC_VIEW ) {
		$status = $this->getTitlesWithCache( $metric, $titles );
		$data = $status->getValue();
		foreach ( $data as $title => $titleData ) {
			if ( $days < $this->cachedDays ) {
				$data[$title] = array_slice( $titleData, -$days, null, true );
			} elseif ( $days > $this->cachedDays ) {
				$data[$title] = $this->extendDateRange( $titleData, $days );
			}
		}
		$status->setResult( $status->isOK(), $data );
		return $status;
	}

	public function getSiteData( $days, $metric = self::METRIC_VIEW ) {
		$status = $this->getWithCache( $metric, self::SCOPE_SITE );
		if ( $status->isOK() ) {
			$data = $status->getValue();
			if ( $days < $this->cachedDays ) {
				$data = array_slice( $data, -$days, null, true );
			} elseif ( $days > $this->cachedDays ) {
				$data = $this->extendDateRange( $data, $days );
			}
			$status->setResult( true, $data );
		}
		return $status;
	}

	public function getTopPages( $metric = self::METRIC_VIEW ) {
		return $this->getWithCache( $metric, self::SCOPE_TOP );
	}

	public function getCacheExpiry( $metric, $scope ) {
		// add some random delay to avoid cache stampedes
		return $this->service->getCacheExpiry( $metric, $scope ) + mt_rand( 0, 600 );
	}

	/**
	 * Like BagOStuff::getWithSetCallback, but returns a StatusValue like PageViewService calls do.
	 * Returns (and caches) null wrapped in a StatusValue on error.
	 * @param string $metric A METRIC_* constant
	 * @param string $scope A SCOPE_* constant (except SCOPE_ARTICLE which has its own method)
	 * @return StatusValue
	 */
	protected function getWithCache( $metric, $scope ) {
		$key = $this->cache->makeKey(
			'pvi',
			$this->prefix,
			( $scope === self::SCOPE_SITE ) ? $this->cachedDays : "",
			$metric,
			$scope
		);
		$data = $this->cache->get( $key );

		if ( $data === false ) {
			// no cached data
			/** @var StatusValue $status */
			switch ( $scope ) {
				case self::SCOPE_SITE:
					$status = $this->service->getSiteData( $this->cachedDays, $metric );
					break;
				case self::SCOPE_TOP:
					$status = $this->service->getTopPages( $metric );
					break;
				default:
					throw new InvalidArgumentException( "invalid scope: $scope" );
			}
			if ( $status->isOK() ) {
				$data = $status->getValue();
				$expiry = $this->getCacheExpiry( $metric, $scope );
			} else {
				$data = null;
				$expiry = self::ERROR_EXPIRY;
			}
			$this->cache->set( $key, $data, $expiry );
		} elseif ( $data === null ) {
			// cached error
			$status = StatusValue::newGood( [] );
			$status->fatal( 'pvi-cached-error', \Message::durationParam( self::ERROR_EXPIRY ) );
		} else {
			// valid cached data
			$status = StatusValue::newGood( $data );
		}
		return $status;
	}

	/**
	 * The equivalent of getWithCache for multiple titles (ie. for SCOPE_ARTICLE).
	 * Errors are also handled per-article.
	 * @param string $metric A METRIC_* constant
	 * @param Title[] $titles
	 * @return StatusValue
	 * @suppress SecurityCheck-DoubleEscaped
	 */
	protected function getTitlesWithCache( $metric, array $titles ) {
		// Set up the response array, without any values. This will help preserve the order of titles.
		$data = array_fill_keys( array_map( static function ( Title $t ) {
			return $t->getPrefixedDBkey();
		}, $titles ), false );

		// Fetch data for all titles from cache. Hopefully we are using a cache which has
		// a cheap getMulti implementation.
		$titleToCacheKey = $statuses = [];
		foreach ( $titles as $title ) {
			$dbKey = $title->getPrefixedDBkey();
			$titleToCacheKey[$dbKey] = $this->cache->makeKey(
				'pvi', $this->prefix,
				$this->cachedDays,
				$metric,
				self::SCOPE_ARTICLE,
				md5( $dbKey )
			);
		}
		$cacheKeyToTitle = array_flip( $titleToCacheKey );
		$rawData = $this->cache->getMulti( array_keys( $cacheKeyToTitle ) );
		foreach ( $rawData as $key => $value ) {
			// BagOStuff::getMulti is unclear on how missing items should be handled; let's
			// assume some implementations might return that key with a value of false
			if ( $value !== false ) {
				$statuses[$cacheKeyToTitle[$key]] = empty( $value['#error'] ) ? StatusValue::newGood()
					: StatusValue::newFatal(
						'pvi-cached-error-title',
						wfEscapeWikiText( $cacheKeyToTitle[$key] ),
						Message::durationParam( self::ERROR_EXPIRY )
					);
				unset( $value['#error'] );
				$data[$cacheKeyToTitle[$key]] = $value;
			}
		}

		// Now get and cache the data for the remaining titles from the real service. It might not
		// return data for all of them.
		foreach ( $titles as $i => $titleObj ) {
			if ( $data[$titleObj->getPrefixedDBkey()] !== false ) {
				unset( $titles[$i] );
			}
		}
		$uncachedStatus = $this->service->getPageData( $titles, $this->cachedDays, $metric );
		foreach ( $uncachedStatus->success as $title => $success ) {
			$titleData = $uncachedStatus->getValue()[$title] ?? null;
			if ( !is_array( $titleData ) || count( $titleData ) < $this->cachedDays ) {
				// PageViewService is expected to return [ date => null ] for all requested dates
				$this->logger->warning( 'Upstream service returned invalid data for {title}', [
					'title' => $title,
					'statusMessage' => Status::wrap( $uncachedStatus )
						->getWikiText( false, false, 'en' ),
				] );
				$titleData = $this->extendDateRange(
					is_array( $titleData ) ? $titleData : [],
					$this->cachedDays
				);
			}
			$data[$title] = $titleData;
			if ( $success ) {
				$statuses[$title] = StatusValue::newGood();
				$expiry = $this->getCacheExpiry( $metric, self::SCOPE_ARTICLE );
			} else {
				$data[$title]['#error'] = true;
				$statuses[$title] = StatusValue::newFatal(
					'pvi-cached-error-title',
					wfEscapeWikiText( $title ),
					Message::durationParam( self::ERROR_EXPIRY )
				);
				$expiry = self::ERROR_EXPIRY;
			}
			$this->cache->set( $titleToCacheKey[$title], $data[$title], $expiry );
			unset( $data[$title]['#error'] );
		}

		// Almost done; we need to truncate the data at the first "hole" (title not returned
		// either by getMulti or getPageData) so we return a consecutive prefix of the
		// requested titles and do not mess up continuation.
		$holeIndex = array_search( false, array_values( $data ), true );
		$data = array_slice( $data, 0, $holeIndex ?: null, true );
		$statuses = array_slice( $statuses, 0, $holeIndex ?: null, true );

		$status = StatusValue::newGood( $data );
		array_walk( $statuses, [ $status, 'merge' ] );
		$status->success = array_map( static function ( StatusValue $s ) {
			 return $s->isOK();
		}, $statuses );
		$status->successCount = count( array_filter( $status->success ) );
		$status->failCount = count( $status->success ) - $status->successCount;
		$status->setResult( $status->successCount || !$titles, $data );
		return $status;
	}

	/**
	 * Add extra days (with a null value) to the beginning of a date range to make it have at least
	 * ::$cachedDays days.
	 * @param array $data YYYY-MM-DD => count, ordered, has less than $cachedDays items
	 * @param int $days
	 * @return array
	 */
	protected function extendDateRange( $data, $days ) {
		reset( $data );
		// set to noon to avoid skip second and similar problems
		$day = strtotime( key( $data ) . 'T00:00Z' ) + 12 * 3600;
		for ( $i = $days - count( $data ); $i > 0; $i-- ) {
			$day -= 24 * 3600;
			$data = [ gmdate( 'Y-m-d', $day ) => null ] + $data;
		}
		return $data;
	}
}
