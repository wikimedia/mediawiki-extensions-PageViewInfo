<?php

namespace MediaWiki\Extension\PageViewInfo;

use FormatJson;
use InvalidArgumentException;
use MediaWiki\Http\HttpRequestFactory;
use MWHttpRequest;
use MWTimestamp;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use RawMessage;
use Status;
use StatusValue;
use Title;
use WebRequest;

/**
 * PageViewService implementation for Wikimedia wikis, using the pageview API
 * @see https://wikitech.wikimedia.org/wiki/Analytics/PageviewAPI
 */
class WikimediaPageViewService implements PageViewService, LoggerAwareInterface {
	/** @var HttpRequestFactory */
	protected $httpRequestFactory;
	/** @var LoggerInterface */
	protected $logger;

	/** @var string */
	protected $endpoint;
	/** @var int|false Max number of pages to look up (false for unlimited) */
	protected $lookupLimit;

	/** @var string */
	protected $project;
	/** @var string 'all-access', 'desktop', 'mobile-app' or 'mobile-web' */
	protected $access;
	/** @var string 'all-agents', 'user', 'spider' or 'bot' */
	protected $agent;
	/** @var string 'hourly', 'daily' or 'monthly' */
	protected $granularity = 'daily'; // allowing other options would make the interface too complex
	/** @var int UNIX timestamp of 0:00 of the last day with complete data */
	protected $lastCompleteDay;

	/** @var array Cache for getEmptyDateRange() */
	protected $range;

	/** @var WebRequest|string[] The request that asked for this data; see the originalRequest
	 *    parameter of MediaWiki\Http\HttpRequestFactory::request()
	 */
	protected $originalRequest;

	/**
	 * @param HttpRequestFactory $httpRequestFactory
	 * @param string $endpoint Wikimedia pageview API endpoint
	 * @param array $apiOptions Associative array of API URL parameters
	 *   see https://wikimedia.org/api/rest_v1/#!/Pageviews_data
	 *   project is the only required parameter. Granularity, start and end are not supported.
	 * @param int|false $lookupLimit Max number of pages to look up (false for unlimited).
	 *   Data will be returned for no more than this many titles in a getPageData() call.
	 */
	public function __construct(
		HttpRequestFactory $httpRequestFactory,
		$endpoint,
		array $apiOptions,
		$lookupLimit
	) {
		$this->endpoint = rtrim( $endpoint, '/' );
		$this->lookupLimit = $lookupLimit;
		$apiOptions += [
			'access' => 'all-access',
			'agent' => 'user',
		];
		$this->verifyApiOptions( $apiOptions );

		$this->project = $apiOptions['project'];
		$this->access = $apiOptions['access'];
		$this->agent = $apiOptions['agent'];

		// Skip the current day for which only partial information is available
		$this->lastCompleteDay = strtotime( '0:0 1 day ago', MWTimestamp::time() );

		$this->httpRequestFactory = $httpRequestFactory;
		$this->logger = new NullLogger();
	}

	public function setLogger( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	/**
	 * @param WebRequest|string[] $originalRequest See the 'originalRequest' parameter of
	 *   MediaWiki\Http\HttpRequestFactory::request().
	 */
	public function setOriginalRequest( $originalRequest ) {
		$this->originalRequest = $originalRequest;
	}

	public function supports( $metric, $scope ) {
		if ( $metric === self::METRIC_VIEW ) {
			return true;
		} elseif ( $metric === self::METRIC_UNIQUE ) {
			return $scope === self::SCOPE_SITE && $this->access !== 'mobile-app';
		}
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function getPageData( array $titles, $days, $metric = self::METRIC_VIEW ) {
		if ( $metric !== self::METRIC_VIEW ) {
			throw new InvalidArgumentException( 'Invalid metric: ' . $metric );
		}
		if ( !$titles ) {
			return StatusValue::newGood( [] );
		} elseif ( $this->lookupLimit !== false ) {
			$titles = array_slice( $titles, 0, $this->lookupLimit );
		}
		if ( $days <= 0 ) {
			throw new InvalidArgumentException( 'Invalid days: ' . $days );
		}

		$status = StatusValue::newGood();
		$result = [];
		foreach ( $titles as $title ) {
			/** @var Title $title */
			$result[$title->getPrefixedDBkey()] = $this->getEmptyDateRange( $days );
			$requestStatus = $this->makeRequest(
				$this->getRequestUrl( self::SCOPE_ARTICLE, $title, $days ) );
			if ( $requestStatus->isOK() ) {
				$data = $requestStatus->getValue();
				if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
					foreach ( $data['items'] as $item ) {
						$ts = $item['timestamp'];
						$day = substr( $ts, 0, 4 ) . '-' . substr( $ts, 4, 2 ) . '-' . substr( $ts, 6, 2 );
						$result[$title->getPrefixedDBkey()][$day] = $item['views'];
					}
					$status->success[$title->getPrefixedDBkey()] = true;
				} else {
					$status->error( 'pvi-invalidresponse' );
					$status->success[$title->getPrefixedDBkey()] = false;
				}
			} else {
				$status->success[$title->getPrefixedDBkey()] = false;
			}
			$status->merge( $requestStatus );
		}
		$status->successCount = count( array_filter( $status->success ) );
		$status->failCount = count( $status->success ) - $status->successCount;
		$status->setResult( (bool)$status->successCount, $result );
		return $status;
	}

	/**
	 * @inheritDoc
	 */
	public function getSiteData( $days, $metric = self::METRIC_VIEW ) {
		if ( $metric !== self::METRIC_VIEW && $metric !== self::METRIC_UNIQUE ) {
			throw new InvalidArgumentException( 'Invalid metric: ' . $metric );
		} elseif ( $metric === self::METRIC_UNIQUE && $this->access === 'mobile-app' ) {
			throw new InvalidArgumentException(
				'Unique device counts for mobile apps are not supported' );
		}
		if ( $days <= 0 ) {
			throw new InvalidArgumentException( 'Invalid days: ' . $days );
		}
		$result = $this->getEmptyDateRange( $days );
		$status = $this->makeRequest( $this->getRequestUrl( $metric, null, $days ) );
		if ( $status->isOK() ) {
			$data = $status->getValue();
			if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
				foreach ( $data['items'] as $item ) {
					$ts = $item['timestamp'];
					$day = substr( $ts, 0, 4 ) . '-' . substr( $ts, 4, 2 ) . '-' . substr( $ts, 6, 2 );
					$count = $metric === self::METRIC_VIEW ? $item['views'] : $item['devices'];
					$result[$day] = $count;
				}
			} else {
				$status->fatal( 'pvi-invalidresponse' );
			}
		}
		$status->setResult( $status->isOK(), $result );
		return $status;
	}

	/**
	 * @inheritDoc
	 */
	public function getTopPages( $metric = self::METRIC_VIEW ) {
		$result = [];
		if ( $metric !== self::METRIC_VIEW ) {
			throw new InvalidArgumentException( 'Invalid metric: ' . $metric );
		}
		$status = $this->makeRequest( $this->getRequestUrl( self::SCOPE_TOP ) );
		if ( $status->isOK() ) {
			$data = $status->getValue();
			if ( isset( $data['items'] ) && is_array( $data['items'] ) && !$data['items'] ) {
				// empty result set, no error; makeRequest generates this on 404
			} elseif (
				isset( $data['items'][0]['articles'] ) &&
				is_array( $data['items'][0]['articles'] )
			) {
				foreach ( $data['items'][0]['articles'] as $item ) {
					$result[$item['article']] = $item['views'];
				}
			} else {
				$status->fatal( 'pvi-invalidresponse' );
			}
		}
		$status->setResult( $status->isOK(), $result );
		return $status;
	}

	public function getCacheExpiry( $metric, $scope ) {
		// data is valid until the end of the day
		$endOfDay = strtotime( '0:0 next day', MWTimestamp::time() );
		return $endOfDay - time();
	}

	/**
	 * @param array $apiOptions
	 * @throws InvalidArgumentException
	 */
	protected function verifyApiOptions( array $apiOptions ) {
		if ( !isset( $apiOptions['project'] ) ) {
			throw new InvalidArgumentException( "'project' is required" );
		} elseif ( !in_array( $apiOptions['access'],
			[ 'all-access', 'desktop', 'mobile-app', 'mobile-web' ], true ) ) {
			throw new InvalidArgumentException( 'Invalid access: ' . $apiOptions['access'] );
		} elseif ( !in_array( $apiOptions['agent'],
			[ 'all-agents', 'user', 'spider', 'bot' ], true ) ) {
			throw new InvalidArgumentException( 'Invalid agent: ' . $apiOptions['agent'] );
		} elseif ( isset( $apiOptions['granularity'] ) ) {
			throw new InvalidArgumentException( 'Changing granularity is not supported' );
		}
	}

	/**
	 * @param string $scope SCOPE_* constant or METRIC_UNIQUE
	 * @param Title|null $title
	 * @param int|null $days
	 * @return string
	 */
	protected function getRequestUrl( $scope, Title $title = null, $days = null ) {
		list( $start, $end ) = $this->getStartEnd( $days );
		switch ( $scope ) {
			case self::SCOPE_ARTICLE:
				if ( !$title ) {
					throw new InvalidArgumentException( 'Title is required when using article scope' );
				}
				// Use plain urlencode instead of wfUrlencode because we need
				// "/" to be encoded, which wfUrlencode doesn't.
				$encodedTitle = urlencode( $title->getPrefixedDBkey() );
				// YYYYMMDD
				$start = substr( $start, 0, 8 );
				$end = substr( $end, 0, 8 );
				return "$this->endpoint/metrics/pageviews/per-article/$this->project/$this->access/"
					. "$this->agent/$encodedTitle/$this->granularity/$start/$end";
			case self::METRIC_VIEW:
			case self::SCOPE_SITE:
			// YYYYMMDDHH
				$start = substr( $start, 0, 10 );
				$end = substr( $end, 0, 10 );
				return "$this->endpoint/metrics/pageviews/aggregate/$this->project/$this->access/$this->agent/"
					   . "$this->granularity/$start/$end";
			case self::SCOPE_TOP:
				$year = substr( $end, 0, 4 );
				$month = substr( $end, 4, 2 );
				$day = substr( $end, 6, 2 );
				return "$this->endpoint/metrics/pageviews/top/$this->project/$this->access/$year/$month/$day";
			case self::METRIC_UNIQUE:
				$access = [
					'all-access' => 'all-sites',
					'desktop' => 'desktop-site',
					'mobile-web' => 'mobile-site',
				][$this->access];
				// YYYYMMDD
				$start = substr( $start, 0, 8 );
				$end = substr( $end, 0, 8 );
				return "$this->endpoint/metrics/unique-devices/$this->project/$access/"
					. "$this->granularity/$start/$end";
			default:
				throw new InvalidArgumentException( 'Invalid scope: ' . $scope );
		}
	}

	/**
	 * @param string $url
	 * @return StatusValue
	 */
	protected function makeRequest( $url ) {
		/** @var MWHttpRequest $request */
		$request = $this->httpRequestFactory->create( $url, [ 'timeout' => 10 ], __METHOD__ );
		if ( $this->originalRequest ) {
			$request->setOriginalRequest( $this->originalRequest );
		}
		$status = $request->execute();
		$parseStatus = FormatJson::parse( $request->getContent() ?? '', FormatJson::FORCE_ASSOC );
		if ( $status->isOK() ) {
			$status->merge( $parseStatus, true );
		}

		$apiErrorData = [];
		if ( !$status->isOK() && $parseStatus->isOK() && is_array( $parseStatus->getValue() ) ) {
			// hash of: type, title, method, uri, [detail]
			$apiErrorData = $parseStatus->getValue();
			if ( isset( $apiErrorData['detail'] ) && is_array( $apiErrorData['detail'] ) ) {
				$apiErrorData['detail'] = implode( ', ', $apiErrorData['detail'] );
			}
		}
		if (
			$request->getStatus() === 404 &&
			isset( $apiErrorData['type'] ) &&
			$apiErrorData['type'] === 'https://mediawiki.org/wiki/HyperSwitch/errors/not_found'
		) {
			// the pageview API will return with a 404 when the page has 0 views :/
			$status = StatusValue::newGood( [ 'items' => [] ] );
		}
		if ( !$status->isGood() ) {
			$error = Status::wrap( $status )->getWikiText( false, false, 'en' );
			$severity = $status->isOK() ? LogLevel::INFO : LogLevel::ERROR;
			$msg = $status->isOK()
				? 'Problems fetching {requesturl}: {error}'
				: 'Failed fetching {requesturl}: {error}';
			$prefixedApiErrorData = array_combine( array_map( static function ( $k ) {
				return 'apierror_' . $k;
			}, array_keys( $apiErrorData ) ), $apiErrorData );
			$this->logger->log( $severity, $msg, [
				'requesturl' => $url,
				'error' => $error,
			] + $prefixedApiErrorData );
		}
		if ( !$status->isOK() && isset( $apiErrorData['detail'] ) ) {
			$status->error( ( new RawMessage( '$1' ) )->params( $apiErrorData['detail'] ) );
		}

		return $status;
	}

	/**
	 * The pageview API omits dates if there is no data. Fill it with nulls to make client-side
	 * processing easier.
	 * @param int $days
	 * @return array YYYY-MM-DD => null
	 */
	protected function getEmptyDateRange( $days ) {
		if ( !$this->range ) {
			$this->range = [];
			// we only care about the date part, so add some hours to avoid errors when there is a
			// leap second or some other weirdness
			$end = $this->lastCompleteDay + 12 * 3600;
			$start = $end - ( $days - 1 ) * 24 * 3600;
			for ( $ts = $start; $ts <= $end; $ts += 24 * 3600 ) {
				$this->range[gmdate( 'Y-m-d', $ts )] = null;
			}
		}
		return $this->range;
	}

	/**
	 * Get start and end timestamp in YYYYMMDDHH format
	 * @param int $days
	 * @return string[]
	 */
	protected function getStartEnd( $days ) {
		$end = $this->lastCompleteDay + 12 * 3600;
		$start = $end - ( $days - 1 ) * 24 * 3600;
		return [ gmdate( 'Ymd', $start ) . '00', gmdate( 'Ymd', $end ) . '00' ];
	}
}
