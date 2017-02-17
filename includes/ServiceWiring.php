<?php

namespace MediaWiki\Extensions\PageViewInfo;

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

return [
	'PageViewService' => function ( MediaWikiServices $services ) {
		$mainConfig = $services->getMainConfig();
		$extensionConfig = $services->getConfigFactory()->makeConfig( 'PageViewInfo' );
		$endpoint = $extensionConfig->get( 'PageViewInfoWikimediaEndpoint' );
		$project = $extensionConfig->get( 'PageViewInfoWikimediaDomain' )
			?: $mainConfig->get( 'ServerName' );
		$pageViewService = new WikimediaPageViewService( $endpoint, [ 'project' => $project ],
			$extensionConfig->get( 'PageViewInfoWikimediaRequestLimit' ) );
		$pageViewService->setLogger( LoggerFactory::getInstance( 'PageViewInfo' ) );
		return $pageViewService;
	},
];
