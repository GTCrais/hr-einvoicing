<?php

namespace GTCrais\HrEInvoicing\Contracts;

/**
 * A gateway whose intermediary takes a request to report a document it holds to the tax authority again, after the
 * tax authority refused it for a cause put right since (the issuer's authorization of the intermediary given, say).
 * EPoslovanje\EPoslovanjeClient and the fake implement it; an app offers the request only where the gateway does.
 */
interface FiscalizesAgain
{
	/**
	 * Ask the intermediary to fiscalize the document again; the tax authority's answer comes in status() like the
	 * first one. A RuntimeException with the reason when the intermediary refuses the request.
	 */
	public function fiscalizeAgain(string $electronicId): void;
}
