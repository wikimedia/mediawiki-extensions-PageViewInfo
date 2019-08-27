<?php

namespace MediaWiki\Extensions\PageViewInfo;

use ApiBase;
use ApiPageSet;
use ApiQueryGeneratorBase;
use MediaWiki\MediaWikiServices;
use Title;

/**
 * Expose PageViewService::getTopPages().
 */
class ApiQueryMostViewed extends ApiQueryGeneratorBase {
	public function __construct( $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'pvim' );
	}

	public function execute() {
		$this->run();
	}

	public function executeGenerator( $resultPageSet ) {
		$this->run( $resultPageSet );
	}

	/**
	 * @param ApiPageSet|null $resultPageSet
	 */
	private function run( ApiPageSet $resultPageSet = null ) {
		/** @var PageViewService $service */
		$service = MediaWikiServices::getInstance()->getService( 'PageViewService' );
		$metric = Hooks::getApiMetricsMap()[$this->getParameter( 'metric' )];
		$status = $service->getTopPages( $metric );

		if ( $status->isOK() ) {
			$this->addMessagesFromStatus( Hooks::makeWarningsOnlyStatus( $status ) );
			$limit = $this->getParameter( 'limit' );
			$offset = $this->getParameter( 'offset' );

			$data = $status->getValue();
			if ( count( $data ) > $offset + $limit ) {
				$this->setContinueEnumParameter( 'offset', $offset + $limit );
			}
			$data = array_slice( $data, $offset, $limit, true );

			if ( $resultPageSet ) {
				$titles = [];
				foreach ( $data as $titleText => $_ ) {
					$title = Title::newFromText( $titleText );
					// Page View API may return invalid titles (T225853)
					if ( $title ) {
						$titles[] = $title;
					}
				}
				$resultPageSet->populateFromTitles( $titles );
			} else {
				$result = $this->getResult();
				$result->addIndexedTagName( [ 'query', $this->getModuleName() ], 'page' );
				foreach ( $data as $titleText => $titleData ) {
					$item = [];
					$title = Title::newFromText( $titleText );
					if ( !$title ) {
						// Page View API may return invalid titles (T208691)
						$offset++;
						continue;
					}
					self::addTitleInfo( $item, $title );
					$item['count'] = $titleData;
					$fits = $result->addValue( [ 'query', $this->getModuleName() ], null, $item );
					if ( !$fits ) {
						$this->setContinueEnumParameter( 'offset', $offset );
						break;
					}
					$offset++;
				}
			}
		} else {
			$this->dieStatus( $status );
		}
	}

	public function getCacheMode( $params ) {
		return 'public';
	}

	public function getAllowedParams() {
		return Hooks::getApiMetricsHelp( PageViewService::SCOPE_TOP ) + [
			'limit' => [
				ApiBase::PARAM_DFLT => 10,
				ApiBase::PARAM_TYPE => 'limit',
				ApiBase::PARAM_MIN => 1,
				ApiBase::PARAM_MAX => ApiBase::LIMIT_BIG1,
				ApiBase::PARAM_MAX2 => ApiBase::LIMIT_BIG2,
			],
			'offset' => [
				ApiBase::PARAM_DFLT => 0,
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_HELP_MSG => 'api-help-param-continue',
			],
		];
	}

	protected function getExamplesMessages() {
		return [
			'action=query&list=mostviewed' => 'apihelp-query+mostviewed-example',
			'action=query&generator=mostviewed&prop=pageviews' => 'apihelp-query+mostviewed-example2',
		];
	}

	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:PageViewInfo';
	}
}
