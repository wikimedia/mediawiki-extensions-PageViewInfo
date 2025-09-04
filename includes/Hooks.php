<?php

namespace MediaWiki\Extension\PageViewInfo;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiModuleManager;
use MediaWiki\Api\ApiQuerySiteinfo;
use MediaWiki\Api\Hook\ApiQuery__moduleManagerHook;
use MediaWiki\Api\Hook\APIQuerySiteInfoGeneralInfoHook;
use MediaWiki\Context\IContextSource;
use MediaWiki\Hook\InfoActionHook;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use StatusValue;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

/**
 * @phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
 */
class Hooks implements
	ApiQuery__moduleManagerHook,
	APIQuerySiteInfoGeneralInfoHook,
	InfoActionHook
{

	private PageViewService $pageViewService;

	public function __construct( PageViewService $pageViewService ) {
		$this->pageViewService = $pageViewService;
	}

	/**
	 * Display total pageviews in the last 30 days.
	 *
	 * @param IContextSource $ctx
	 * @param array &$pageInfo
	 */
	public function onInfoAction( $ctx, &$pageInfo ) {
		if ( !$this->pageViewService->supports( PageViewService::METRIC_VIEW,
			PageViewService::SCOPE_ARTICLE )
		) {
			return;
		}
		$title = $ctx->getTitle();
		$status = $this->pageViewService->getPageData( [ $title ], 30, PageViewService::METRIC_VIEW );
		$data = $status->getValue();
		if ( !$status->isOK() ) {
			return;
		}
		$views = $data[$title->getPrefixedDBkey()];

		$total = array_sum( $views );
		$start = self::toYmdHis( array_key_first( $views ) );
		$end = self::toYmdHis( array_key_last( $views ) );

		$lang = $ctx->getLanguage();
		$formatted = $lang->formatNum( $total );
		$pageInfo['header-basic'][] = [
			$ctx->msg( 'pvi-month-count' ),
			Html::rawElement( 'div',
				[ 'class' => 'mw-pvi-month' ],
				$ctx->msg( 'pvi-month-count-value', $formatted, $title->getPrefixedDBkey() )->parse()
			)
		];
	}

	/**
	 * Limit enabled PageViewInfo API modules to those which are supported by the service.
	 * @param ApiModuleManager $moduleManager
	 */
	public function onApiQuery__ModuleManager( $moduleManager ) {
		$moduleMap = [
			// Order is: module name, module group, objectFactory spec
			'pageviews' => [
				'pageviews',
				'prop',
				[
					'class' => ApiQueryPageViews::class,
					'services' => [
						'PageViewService',
						'TitleFormatter',
					]
				]
			],
			'siteviews' => [
				'siteviews',
				'meta',
				[
					'class' => ApiQuerySiteViews::class,
					'services' => [
						'PageViewService',
					]
				]
			],
			'mostviewed' => [
				'mostviewed',
				'list',
				[
					'class' => ApiQueryMostViewed::class,
					'services' => [
						'PageViewService',
					]
				]
			],
		];
		foreach ( self::getApiScopeMap() as $apiModuleName => $serviceScopeConstant ) {
			foreach ( self::getApiMetricsMap() as $serviceMetricConstant ) {
				if ( $this->pageViewService->supports( $serviceMetricConstant, $serviceScopeConstant ) ) {
					$moduleManager->addModule( ...$moduleMap[$apiModuleName] );
					continue 2;
				}
			}
		}
	}

	/**
	 * Add information to the siteinfo API output about which metrics are supported.
	 * @param ApiQuerySiteinfo $module
	 * @param array &$result
	 */
	public function onAPIQuerySiteInfoGeneralInfo( $module, &$result ) {
		$supportedMetrics = [];
		foreach ( self::getApiScopeMap() as $apiModuleName => $serviceScopeConstant ) {
			foreach ( self::getApiMetricsMap() as $apiMetricsName => $serviceMetricConstant ) {
				$supportedMetrics[$apiModuleName][$apiMetricsName] =
					$this->pageViewService->supports( $serviceMetricConstant, $serviceScopeConstant );
			}
		}
		$result['pageviewservice-supported-metrics'] = $supportedMetrics;
	}

	/**
	 * Maps allowed values of the 'metric' parameter of the pageview-related APIs to service constants.
	 * @return array
	 */
	public static function getApiMetricsMap() {
		return [
			'pageviews' => PageViewService::METRIC_VIEW,
			'uniques' => PageViewService::METRIC_UNIQUE,
		];
	}

	/**
	 * Maps API module names to service constants.
	 * @return array
	 */
	public static function getApiScopeMap() {
		return [
			'pageviews' => PageViewService::SCOPE_ARTICLE,
			'siteviews' => PageViewService::SCOPE_SITE,
			'mostviewed' => PageViewService::SCOPE_TOP,
		];
	}

	/**
	 * Returns an array suitable for merging into getAllowedParams()
	 * @param string $scope One of the PageViewService::SCOPE_* constants
	 * @return array
	 */
	public static function getApiMetricsHelp( $scope ) {
		/** @var PageViewService $service */
		$service = MediaWikiServices::getInstance()->getService( 'PageViewService' );
		$metrics = array_keys( array_filter( self::getApiMetricsMap(),
			static function ( $metric ) use ( $scope, $service ) {
				return $service->supports( $metric, $scope );
			} ) );
		$reverseMap = array_flip( self::getApiMetricsMap() );
		$default = $reverseMap[PageViewService::METRIC_VIEW] ?? reset( $reverseMap );

		return $default ? [
			'metric' => [
				ParamValidator::PARAM_TYPE => $metrics,
				ParamValidator::PARAM_DEFAULT => $default,
				ApiBase::PARAM_HELP_MSG => 'apihelp-pageviewinfo-param-metric',
				ApiBase::PARAM_HELP_MSG_PER_VALUE => array_map( static function ( $metric ) {
					return 'apihelp-pageviewinfo-paramvalue-metric-' . $metric;
				}, array_combine( $metrics, $metrics ) ),
			],
		] : [];
	}

	/**
	 * Returns an array suitable for merging into getAllowedParams()
	 * @return array
	 */
	public static function getApiDaysHelp() {
		$days = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'PageViewInfo' )
			->get( 'PageViewApiMaxDays' );
		return [
			'days' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_DEFAULT => $days,
				IntegerDef::PARAM_MAX => $days,
				IntegerDef::PARAM_MIN => 1,
				ApiBase::PARAM_HELP_MSG => 'apihelp-pageviewinfo-param-days',
			],
		];
	}

	/**
	 * Transform into a status with errors replaced with warnings
	 * @param StatusValue $status
	 * @return StatusValue
	 */
	public static function makeWarningsOnlyStatus( StatusValue $status ) {
		[ $errors, $warnings ] = $status->splitByErrorType();
		foreach ( $errors->getErrors() as $error ) {
			$warnings->warning( $error['message'], ...$error['params'] );
		}
		return $warnings;
	}

	/**
	 * Convert YYYY-MM-DD to YYYYMMDD
	 * @param string $date
	 * @return string
	 */
	protected static function toYmd( $date ) {
		return substr( $date, 0, 4 ) . substr( $date, 5, 2 ) . substr( $date, 8, 2 );
	}

	/**
	 * Convert YYYY-MM-DD to TS_MW
	 * @param string $date
	 * @return string
	 */
	protected static function toYmdHis( $date ) {
		return substr( $date, 0, 4 ) . substr( $date, 5, 2 ) . substr( $date, 8, 2 ) . '000000';
	}
}
