<?php

namespace PageViewInfo;

use IContextSource;
use FormatJson;
use Http;
use ObjectCache;
use Title;

class Hooks {

	/**
	 * @param IContextSource $ctx
	 * @param array $pageInfo
	 */
	public static function onInfoAction( IContextSource $ctx, array &$pageInfo ) {
		$views = self::getMonthViews( $ctx->getTitle() );
		$count = 0;
		foreach ( $views['items'] as $item ) {
			$count += $item['views'];
		}
		$formatted = $ctx->getLanguage()->formatNum( $count );
		$pageInfo['header-basic'][] = array(
			$ctx->msg( 'pvi-month-count' ),
			\Html::element( 'div', array( 'class' => 'mw-pvi-month' ), $formatted )
		);
		$ctx->getOutput()->addModules( 'ext.pvi.init' );
		$ctx->getOutput()->addJsConfigVars( array(
			'wgPageViewInfo' => $views,
			'wgPVIDefinition' => file_get_contents( __DIR__ . '/../thing.json' ),
		) );
	}

	/**
	 * @param Title $title
	 * @return string
	 */
	protected static function buildApiUrl( Title $title ) {
		global $wgPageViewInfoEndpoint, $wgServerName;
		$wgServerName = 'en.wikipedia.org';
		$encodedTitle = wfUrlencode( $title->getPrefixedDBkey() );
		$today = date( 'Ymd' );
		$lastMonth = date( 'Ymd', time() - 60 * 60 * 24 * 30 );
		return "$wgPageViewInfoEndpoint/per-article/$wgServerName"
			. "/all-access/user/$encodedTitle/daily/$lastMonth/$today";
	}

	protected static function getMonthViews( Title $title ) {
		$url = self::buildApiUrl( $title );
		$cache = ObjectCache::getLocalServerInstance( CACHE_ANYTHING );
		$key = wfMemcKey( 'pvi', 'month2', md5( $title->getPrefixedText() ) );
		$data = $cache->get( $key );
		if ( $data ) {
			return $data;
		}

		$req = Http::get( $url );
		$data = FormatJson::decode( $req, true );
		// Cache for an hour
		$cache->set( $key, $data, 60 * 60 );

		return $data;
	}
}
