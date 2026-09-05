<?php

namespace MediaWiki\Extension\PageViewInfo;

use MediaWikiIntegrationTestCase;

/**
 * @coversNothing Not possible to cover non-classes/functions
 */
class ServiceWiringTest extends MediaWikiIntegrationTestCase {
	public function testService() {
		$service = $this->getServiceContainer()->getService( 'PageViewService' );
		$this->assertInstanceOf( PageViewService::class, $service );
	}
}
