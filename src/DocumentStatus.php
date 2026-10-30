<?php

namespace GTCrais\HrEInvoicing;

/**
 * Where an e-invoice stands with its intermediary, the same for every intermediary: with it and on its way; delivered
 * to the buyer (approved or paid too); reported to the tax authority instead of delivered, because the buyer takes no
 * e-invoices; refused, the intermediary did not take the document at all, so a new try sends it afresh; rejected, the
 * buyer rejected it after it was delivered, so the tax authority has it and a storno or a credit note is the way; or
 * undelivered, the intermediary holds it but could not deliver it to the buyer's intermediary and stopped trying, so it
 * was never exchanged nor fiscalized, and it goes again corrected under its number once deleted at the intermediary.
 */
enum DocumentStatus: string
{
	case SENT = 'sent';
	case DELIVERED = 'delivered';
	case REPORTED = 'reported';
	case REFUSED = 'refused';
	case REJECTED = 'rejected';
	case UNDELIVERED = 'undelivered';

	/**
	 * Whether the intermediary holds the document, so it must not be sent again: an undelivered one too, until it is
	 * deleted there.
	 */
	public function isHeld(): bool
	{
		return $this !== self::REFUSED;
	}
}
