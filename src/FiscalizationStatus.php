<?php

namespace GTCrais\HrEInvoicing;

/**
 * The tax authority's answer about an e-invoice, as the intermediary relays it: taken, refused with a reason, or
 * still waiting for the answer. Null in a status answer means the intermediary has said nothing about it yet.
 */
enum FiscalizationStatus: string
{
	case FISCALIZED = 'fiscalized';
	case FAILED = 'failed';
	case WAITING = 'waiting';
}
