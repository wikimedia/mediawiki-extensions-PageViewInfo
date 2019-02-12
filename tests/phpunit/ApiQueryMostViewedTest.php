<?php

namespace MediaWiki\Extensions\PageViewInfo;

use Wikimedia\TestingAccessWrapper;

/**
 * @group medium
 * @covers \MediaWiki\Extensions\PageViewInfo\ApiQueryMostViewed
 */
class ApiQueryMostViewedTest extends \ApiTestCase {
	public function testMostviewed_invalid() {
		$mock = $this->createMock( \MWHttpRequest::class );
		$mock->expects( $this->once() )->method( 'execute' )->willReturn( \Status::newGood() );
		$mock->expects( $this->any() )->method( 'getStatus' )->willReturn( 200 );
		$mock->expects( $this->any() )->method( 'getContent' )->willReturn( json_encode( [
			'items' => [
				[
					'project' => 'foo.project.test',
					'access' => 'all-access',
					'year' => '2011',
					'month' => '04',
					'day' => '01',
					'articles' => [
						[
							'article' => 'Main_Page',
							'views' => 1000,
							'rank' => 1,
						],
						[
							'article' => "St\u{FFFD}ck|gut",
							'views' => 100,
							'rank' => 2,
						],
						[
							'article' => 'Sandbox',
							'views' => 10,
							'rank' => 3,
						],
					],
				],
			 ]
		] ) );

		$responses = [ $mock ];
		$service = new WikimediaPageViewService( 'http://example.test/',
			[ 'project' => 'foo.project.test' ], false );
		$wrapper = TestingAccessWrapper::newFromObject( $service );
		$wrapper->requestFactory = function ( $url ) use ( &$responses ) {
			return array_shift( $responses );
		};
		$this->setService( 'PageViewService', $service );

		$ret = $this->doApiRequest(
				[
					'action' => 'query',
					'list' => 'mostviewed',
				]
		);

		$this->assertEquals(
			[
				'batchcomplete' => true,
				'query' => [
					'mostviewed' => [
						[ 'ns' => 0, 'title' => 'Main Page', 'count' => 1000, ],
						[ 'ns' => 0, 'title' => 'Sandbox', 'count' => 10 ],
					],
				],
			],
			$ret[0],
			'API response'
		);
	}
}
