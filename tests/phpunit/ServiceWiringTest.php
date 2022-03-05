<?php

namespace MediaWiki\Extension\PageViewInfo;

use MediaWiki\MediaWikiServices;

/**
 * @coversNothing Not possible to cover non-classes/functions
 */
class ServiceWiringTest extends \PHPUnit\Framework\TestCase {
	public function testService() {
		$service = MediaWikiServices::getInstance()->getService( 'PageViewService' );
		$this->assertInstanceOf( PageViewService::class, $service );
	}
}
