<?php

namespace GTCrais\HrEInvoicing;

/**
 * Where an e-invoice stands with its intermediary, the same for every intermediary: with it and on its way; delivered
 * to the buyer (approved or paid too); reported to the tax authority instead of delivered, because the buyer takes no
 * e-invoices; refused, the intermediary did not take the document at all, so a new try sends it afresh; or rejected,
 * the intermediary holds it but it will not reach the buyer (the buyer rejected it, or its delivery failed for good),
 * which a new send would only duplicate.
 */
enum DocumentStatus: string
{
	case SENT = 'sent';
	case DELIVERED = 'delivered';
	case REPORTED = 'reported';
	case REFUSED = 'refused';
	case REJECTED = 'rejected';

	/**
	 * Whether the intermediary holds the document, so it must not be sent again.
	 */
	public function isHeld(): bool
	{
		return $this !== self::REFUSED;
	}
}
