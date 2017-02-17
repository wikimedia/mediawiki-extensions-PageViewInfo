<?php

namespace MediaWiki\Extensions\PageViewInfo;

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use ObjectCache;

return [
	'PageViewService' => function ( MediaWikiServices $services ) {
		$mainConfig = $services->getMainConfig();
		$extensionConfig = $services->getConfigFactory()->makeConfig( 'PageViewInfo' );
		$endpoint = $extensionConfig->get( 'PageViewInfoWikimediaEndpoint' );
		$project = $extensionConfig->get( 'PageViewInfoWikimediaDomain' )
			?: $mainConfig->get( 'ServerName' );
		$cache = ObjectCache::getLocalClusterInstance();
		$logger = LoggerFactory::getInstance( 'PageViewInfo' );

		$service = new WikimediaPageViewService( $endpoint, [ 'project' => $project ],
			$extensionConfig->get( 'PageViewInfoWikimediaRequestLimit' ) );
		$service->setLogger( $logger );
		$cachedService = new CachedPageViewService( $service, $cache );
		$cachedService->setLogger( $logger );
		return $cachedService;
	},
];
