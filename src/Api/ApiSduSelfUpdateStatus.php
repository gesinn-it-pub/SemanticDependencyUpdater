<?php

declare( strict_types=1 );

namespace SDU\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiResult;
use MediaWiki\Title\Title;
use SDU\Hooks;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Lets ext.sdu.reload.js ask "is a reload for this exact revision still
 * pending?" instead of blindly waiting out a fixed backoff timer before
 * reloading - see SDU\Hooks::isSelfUpdateReloadPending()'s own docblock for
 * why this exists: live measurement showed a fixed client-side backoff can
 * fire before the server-side self-update cycle has actually finished when a
 * page produces several genuine (non-empty-diff) passes in a row, showing a
 * stale intermediate value.
 *
 * Deliberately its own tiny module rather than reusing SMW's action=purge or
 * action=parse: a plain action=parse does not invoke the OutputPageParserOutput
 * hook SDU's own reload-pending marker rendering relies on unless a skin is
 * additionally engaged (useskin/subtitle/headhtml/categorieshtml/mobileformat
 * - more overhead and indirection than a dedicated read), and SMW's own
 * action=smwtask module is a write-mode, CSRF-token-gated module for a
 * different purpose, not a fit for an anonymous, cheap, repeated status poll.
 */
class ApiSduSelfUpdateStatus extends ApiBase {

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$params = $this->extractRequestParams();

		$title = Title::newFromText( $params['title'] );

		if ( $title === null ) {
			$this->dieWithError( [ 'apierror-invalidtitle', wfEscapeWikiText( $params['title'] ) ] );
		}

		$pending = Hooks::isSelfUpdateReloadPending(
			$title->getPrefixedDBKey(),
			$params['revid']
		);

		// Without this, ApiResult's pre-1.25 BC transformation (still applied
		// by default) strips a `false` boolean value entirely and renders
		// `true` as an empty string - "pending" would silently vanish from
		// the response instead of reading as false, which the client cannot
		// tell apart from a malformed/empty response.
		$this->getResult()->addValue(
			null,
			$this->getModuleName(),
			[
				'pending' => $pending,
				ApiResult::META_BC_BOOLS => [ 'pending' ],
			]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function getAllowedParams() {
		return [
			'title' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'revid' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function isInternal() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	protected function getExamplesMessages() {
		return [
			'action=sduselfupdatestatus&title=Foo&revid=123'
				=> 'apihelp-sduselfupdatestatus-example-1',
		];
	}

}
