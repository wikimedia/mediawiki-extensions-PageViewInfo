<?php

namespace MediaWiki\Extensions\PageViewInfo;

use MediaWiki\MediaWikiServices;

class ServiceWiringTest extends \PHPUnit\Framework\TestCase {
	public function testService() {
		$service = MediaWikiServices::getInstance()->getService( 'PageViewService' );
		$this->assertInstanceOf( PageViewService::class, $service );
	}
}
