<?php

namespace MediaWiki\Extension\PageViewInfo;

use MediaWiki\Context\RequestContext;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

return [
	'PageViewService' => static function ( MediaWikiServices $services ): PageViewService {
		$mainConfig = $services->getMainConfig();
		$extensionConfig = $services->getConfigFactory()->makeConfig( 'PageViewInfo' );
		$endpoint = $extensionConfig->get( 'PageViewInfoWikimediaEndpoint' );
		$pageAnalyticsEndpoint = $extensionConfig->get( 'PageViewInfoWikimediaPageAnalyticsEndpoint' ) ?: $endpoint;
		$deviceAnalyticsEndpoint = $extensionConfig->get( 'PageViewInfoWikimediaDeviceAnalyticsEndpoint' ) ?: $endpoint;
		$project = $extensionConfig->get( 'PageViewInfoWikimediaDomain' )
			?: $mainConfig->get( 'ServerName' );
		$cache = $services->getObjectCacheFactory()->getLocalClusterInstance();
		$titleFormatter = $services->getTitleFormatter();
		$logger = LoggerFactory::getInstance( 'PageViewInfo' );
		$cachedDays = max( 30, $extensionConfig->get( 'PageViewApiMaxDays' ) );

		$service = new WikimediaPageViewService(
			$services->getHttpRequestFactory(),
			$titleFormatter,
			$pageAnalyticsEndpoint,
			$deviceAnalyticsEndpoint,
			[ 'project' => $project ],
			$extensionConfig->get( 'PageViewInfoWikimediaRequestLimit' )
		);
		$service->setLogger( $logger );
		$service->setOriginalRequest( RequestContext::getMain()->getRequest() );

		$cachedService = new CachedPageViewService(
			$service,
			$cache,
			$titleFormatter
		);
		$cachedService->setCachedDays( $cachedDays );
		$cachedService->setLogger( $logger );
		return $cachedService;
	},
];
