<?php

namespace MediaWiki\Extension\PageViewInfo;

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use ObjectCache;
use RequestContext;

return [
	'PageViewService' => static function ( MediaWikiServices $services ) {
		$mainConfig = $services->getMainConfig();
		$extensionConfig = $services->getConfigFactory()->makeConfig( 'PageViewInfo' );
		$endpoint = $extensionConfig->get( 'PageViewInfoWikimediaEndpoint' );
		$project = $extensionConfig->get( 'PageViewInfoWikimediaDomain' )
			?: $mainConfig->get( 'ServerName' );
		$cache = ObjectCache::getLocalClusterInstance();
		$logger = LoggerFactory::getInstance( 'PageViewInfo' );
		$cachedDays = max( 30, $extensionConfig->get( 'PageViewApiMaxDays' ) );

		$service = new WikimediaPageViewService(
			$services->getHttpRequestFactory(),
			$endpoint,
			[ 'project' => $project ],
			$extensionConfig->get( 'PageViewInfoWikimediaRequestLimit' )
		);
		$service->setLogger( $logger );
		$service->setOriginalRequest( RequestContext::getMain()->getRequest() );

		$cachedService = new CachedPageViewService( $service, $cache );
		$cachedService->setCachedDays( $cachedDays );
		$cachedService->setLogger( $logger );
		return $cachedService;
	},
];
