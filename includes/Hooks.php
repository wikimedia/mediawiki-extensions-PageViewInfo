<?php

namespace MediaWiki\Extensions\PageViewInfo;

use IContextSource;
use FormatJson;
use Html;
use MediaWiki\MediaWikiServices;
use ObjectCache;
use Title;

class Hooks {

	/**
	 * @param IContextSource $ctx
	 * @param array $pageInfo
	 */
	public static function onInfoAction( IContextSource $ctx, array &$pageInfo ) {
		$views = self::getMonthViews( $ctx->getTitle() );
		if ( !$views ) {
			return;
		}

		$total = array_sum( $views );
		reset( $views );
		$start = self::toYmdHis( key( $views ) );
		end( $views );
		$end = self::toYmdHis( key( $views ) );

		$lang = $ctx->getLanguage();
		$formatted = $lang->formatNum( $total );
		$pageInfo['header-basic'][] = [
			$ctx->msg( 'pvi-month-count' ),
			Html::element( 'div', [ 'class' => 'mw-pvi-month' ], $formatted )
		];

		$info = FormatJson::decode(
			file_get_contents( __DIR__ . '/../graphs/month.json' ),
			true
		);
		foreach ( $views as $day => $count ) {
			$info['data'][0]['values'][] = [ 'timestamp' => self::toYmd( $day ), 'views' => $count ];
		}

		$ctx->getOutput()->addModules( 'ext.pageviewinfo' );
		// Ymd -> YmdHis
		$user = $ctx->getUser();
		$ctx->getOutput()->addJsConfigVars( [
			'wgPageViewInfo' => [
				'graph' => $info,
				'start' => $lang->userDate( $start, $user ),
				'end' => $lang->userDate( $end, $user ),
			],
		] );
	}

	protected static function getMonthViews( Title $title ) {
		$cache = ObjectCache::getLocalClusterInstance();
		$key = $cache->makeKey( 'pvi', 'month', md5( $title->getPrefixedText() ) );
		$data = $cache->get( $key );
		if ( $data ) {
			return $data;
		}

		/** @var PageViewService $pageViewService */
		$pageViewService = MediaWikiServices::getInstance()->getService( 'PageViewService' );
		if ( !$pageViewService->supports( PageViewService::METRIC_VIEW,
			PageViewService::SCOPE_ARTICLE )
		) {
			return false;
		}

		$status = $pageViewService->getPageData( [ $title ], 30, PageViewService::METRIC_VIEW );
		if ( !$status->isOK() ) {
			$cache->set( $key, false, 300 );
		}

		$data = $status->getValue()[$title->getPrefixedDBkey()];
		$cache->set( $key, $data, $pageViewService->getCacheExpiry( PageViewService::METRIC_VIEW,
			PageViewService::SCOPE_ARTICLE ) );
		return $data;
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
