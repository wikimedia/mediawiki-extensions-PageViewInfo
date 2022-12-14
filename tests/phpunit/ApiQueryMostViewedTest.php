<?php

namespace MediaWiki\Extension\PageViewInfo;

use MediaWiki\Http\HttpRequestFactory;

/**
 * @group medium
 * @covers \MediaWiki\Extension\PageViewInfo\ApiQueryMostViewed
 */
class ApiQueryMostViewedTest extends \ApiTestCase {
	public function provideRequestResponses() {
		return [
			[
				[
					'action' => 'query',
					'list' => 'mostviewed',
				],
				[
					'batchcomplete' => true,
					'query' => [
						'mostviewed' => [
							[ 'ns' => 0, 'title' => 'Main Page', 'count' => 1000, ],
							[ 'ns' => 0, 'title' => 'Sandbox', 'count' => 10 ],
						],
					],
				]
			],
			[
				[
					'action' => 'query',
					'generator' => 'mostviewed'
				],
				[
					'batchcomplete' => true,
					'query' => [
						'pages' => [
							-1 => [ 'ns' => 0, 'title' => 'Main Page', 'missing' => true, ],
							-2 => [ 'ns' => 0, 'title' => 'Sandbox', 'missing' => true ],
						],
					],
				]
			]
		];
	}

	/**
	 * @dataProvider provideRequestResponses
	 */
	public function testMostviewed_invalid( $request, $response ) {
		$mock = $this->createMock( \MWHttpRequest::class );
		$mock->expects( $this->once() )->method( 'execute' )->willReturn( \Status::newGood() );
		$mock->method( 'getStatus' )->willReturn( 200 );
		$mock->method( 'getContent' )->willReturn( json_encode( [
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

		$httpRequestFactory = $this->createMock( HttpRequestFactory::class );
		$httpRequestFactory->expects( $this->once() )
			->method( 'create' )
			->willReturn( $mock );
		$service = new WikimediaPageViewService(
			$httpRequestFactory,
			'http://example.test/',
			[ 'project' => 'foo.project.test' ],
			false
		);
		$this->setService( 'PageViewService', $service );

		$ret = $this->doApiRequest( $request );

		$this->assertEquals(
			$response,
			$ret[0],
			'API response'
		);
	}
}
