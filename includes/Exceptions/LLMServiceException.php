<?php

namespace MediaWiki\Extension\AIBatchEditor\Exceptions;

use RuntimeException;

/**
 * LLM request failure with an i18n message key and optional parameters.
 */
class LLMServiceException extends RuntimeException {

	/** @var string[] */
	private array $params;
	private ?string $logDetail;

	/**
	 * @param string[] $params
	 */
	public function __construct( string $messageKey, array $params = [], ?string $logDetail = null ) {
		parent::__construct( $messageKey );
		$this->params = $params;
		$this->logDetail = $logDetail;
	}

	public function getMessageKey(): string {
		return $this->getMessage();
	}

	/** @return string[] */
	public function getParams(): array {
		return $this->params;
	}

	public function getLogDetail(): ?string {
		return $this->logDetail;
	}

}