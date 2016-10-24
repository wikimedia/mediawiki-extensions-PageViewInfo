<?php

namespace MediaWiki\Extensions\PageViewInfo;

use ApiBase;
use ApiQueryBase;
use ApiResult;
use MediaWiki\MediaWikiServices;

/**
 * Expose PageViewService::getSiteData().
 */
class ApiQuerySiteViews extends ApiQueryBase  {
	public function __construct( $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'pvis' );
	}

	public function execute() {
		/** @var PageViewService $service */
		$service = MediaWikiServices::getInstance()->getService( 'PageViewService' );
		$metric = Hooks::getApiMetricsMap()[$this->getParameter( 'metric' )];
		$status = $service->getSiteData( $this->getParameter( 'days' ), $metric );
		if ( $status->isOK() ) {
			$this->addMessagesFromStatus( Hooks::makeWarningsOnlyStatus( $status ) );
			$result = $this->getResult();
			$value = $status->getValue();
			ApiResult::setArrayType( $value, 'kvp', 'date' );
			ApiResult::setIndexedTagName( $value, 'count' );
			$result->addValue( 'query', $this->getModuleName(), $value );
		} else {
			$this->dieStatus( $status );
		}
	}

	public function getCacheMode( $params ) {
		return 'public';
	}

	public function getAllowedParams() {
		return Hooks::getApiMetricsHelp( PageViewService::SCOPE_SITE ) + Hooks::getApiDaysHelp();
	}

	protected function getExamplesMessages() {
		return [
			'action=query&meta=siteviews' => 'apihelp-query+siteviews-example',
			'action=query&meta=siteviews&metric=uniques' => 'apihelp-query+siteviews-example2',
		];
	}

	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Extension:PageViewInfo';
	}
}
