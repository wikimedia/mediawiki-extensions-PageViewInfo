<?php

namespace PageViewInfo;

use IContextSource;
use FormatJson;
use Html;
use Http;
use Title;

class Hooks {

	/**
	 * @param IContextSource $ctx
	 * @param array $pageInfo
	 */
	public static function onInfoAction( IContextSource $ctx, array &$pageInfo ) {
		$views = self::getMonthViews( $ctx->getTitle() );
		if ( $views === false ) {
			return;
		}
		$count = 0;
		foreach ( $views['items'] as $item ) {
			$count += $item['views'];
		}
		$lang = $ctx->getLanguage();
		$formatted = $lang->formatNum( $count );
		$pageInfo['header-basic'][] = array(
			$ctx->msg( 'wmpvi-month-count' ),
			Html::element( 'div', array( 'class' => 'mw-wmpvi-month' ), $formatted )
		);

		$info = FormatJson::decode(
			file_get_contents( __DIR__ . '/../graphs/month.json' ),
			true
		);
		$info['data'][0]['values'] = $views['items'];

		$ctx->getOutput()->addModules( 'ext.wmpageviewinfo' );
		// Ymd -> YmdHis
		$plus = '000000';
		$user = $ctx->getUser();
		$ctx->getOutput()->addJsConfigVars( array(
			'wgWMPageViewInfo' => array(
				'graph' => $info,
				'start' => $lang->userDate( $views['start'] . $plus, $user ),
				'end' => $lang->userDate( $views['end'] . $plus, $user ),
			),
		) );
	}

	/**
	 * @param Title $title
	 * @param string $startDate Ymd format
	 * @param string $endDate Ymd format
	 * @return string
	 */
	protected static function buildApiUrl( Title $title, $startDate, $endDate ) {
		global $wgPageViewInfoEndpoint, $wgPageViewInfoDomain, $wgServerName;
		if ( $wgPageViewInfoDomain ) {
			$serverName = $wgPageViewInfoDomain;
		} else {
			$serverName = $wgServerName;
		}

		// Use plain urlencode instead of wfUrlencode because we need
		// "/" to be encoded, which wfUrlencode doesn't.
		$encodedTitle = urlencode( $title->getPrefixedDBkey() );
		return "$wgPageViewInfoEndpoint/per-article/$serverName"
			. "/all-access/user/$encodedTitle/daily/$startDate/$endDate";
	}

	protected static function getMonthViews( Title $title ) {
		global $wgMemc;

		$key = wfMemcKey( 'pvi', 'month', md5( $title->getPrefixedText() ) );
		$data = $wgMemc->get( $key );
		if ( $data ) {
			return $data;
		}

		$today = date( 'Ymd' );
		$lastMonth = date( 'Ymd', time() - ( 60 * 60 * 24 * 30 ) );
		$url = self::buildApiUrl( $title, $lastMonth, $today );
		$req = Http::get(
			$url,
			array( 'timeout' => 10 ),
			__METHOD__
		);

		if ( $req === false ) {
			return false;
		}

		$data = FormatJson::decode( $req, true );
		// Add our start/end periods
		$data['start'] = $lastMonth;
		$data['end'] = $today;

		// Cache for an hour
		$wgMemc->set( $key, $data, 60 * 60 );

		return $data;
	}
}
