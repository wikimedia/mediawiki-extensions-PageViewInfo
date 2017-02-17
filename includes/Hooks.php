<?php

namespace MediaWiki\Extensions\PageViewInfo;

use IContextSource;
use FormatJson;
use Html;
use MediaWiki\MediaWikiServices;

class Hooks {
	/**
	 * @param IContextSource $ctx
	 * @param array $pageInfo
	 */
	public static function onInfoAction( IContextSource $ctx, array &$pageInfo ) {
		/** @var PageViewService $pageViewService */
		$pageViewService = MediaWikiServices::getInstance()->getService( 'PageViewService' );
		if ( !$pageViewService->supports( PageViewService::METRIC_VIEW,
			PageViewService::SCOPE_ARTICLE )
		) {
			return;
		}
		$title = $ctx->getTitle();
		$status = $pageViewService->getPageData( [ $title ], 30, PageViewService::METRIC_VIEW );
		$data = $status->getValue();
		if ( !$status->isOK() ) {
			return;
		}
		$views = $data[$title->getPrefixedDBkey()];

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
