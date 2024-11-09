<?php

namespace MediaWiki\Extension\PageViewInfo;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiPageSet;
use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryGeneratorBase;
use MediaWiki\Title\Title;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

/**
 * Expose PageViewService::getTopPages().
 */
class ApiQueryMostViewed extends ApiQueryGeneratorBase {

	private PageViewService $pageViewService;

	public function __construct(
		ApiQuery $query,
		string $moduleName,
		PageViewService $pageViewService
	) {
		parent::__construct( $query, $moduleName, 'pvim' );
		$this->pageViewService = $pageViewService;
	}

	public function execute() {
		$this->run();
	}

	/** @inheritDoc */
	public function executeGenerator( $resultPageSet ) {
		$this->run( $resultPageSet );
	}

	/**
	 * @param ApiPageSet|null $resultPageSet
	 */
	private function run( ?ApiPageSet $resultPageSet = null ) {
		$params = $this->extractRequestParams();
		$metric = Hooks::getApiMetricsMap()[$params['metric']];
		$status = $this->pageViewService->getTopPages( $metric );

		if ( $status->isOK() ) {
			$this->addMessagesFromStatus( Hooks::makeWarningsOnlyStatus( $status ) );
			$limit = $params['limit'];
			$offset = $params['offset'];

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

	/** @inheritDoc */
	public function getCacheMode( $params ) {
		return 'public';
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return Hooks::getApiMetricsHelp( PageViewService::SCOPE_TOP ) + [
			'limit' => [
				ParamValidator::PARAM_DEFAULT => 10,
				ParamValidator::PARAM_TYPE => 'limit',
				IntegerDef::PARAM_MIN => 1,
				IntegerDef::PARAM_MAX => ApiBase::LIMIT_BIG1,
				IntegerDef::PARAM_MAX2 => ApiBase::LIMIT_BIG2,
			],
			'offset' => [
				ParamValidator::PARAM_DEFAULT => 0,
				ParamValidator::PARAM_TYPE => 'integer',
				ApiBase::PARAM_HELP_MSG => 'api-help-param-continue',
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=query&list=mostviewed' => 'apihelp-query+mostviewed-example',
			'action=query&generator=mostviewed&prop=pageviews' => 'apihelp-query+mostviewed-example2',
		];
	}

	/** @inheritDoc */
	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:PageViewInfo';
	}
}
