<?php

namespace GTCrais\HrEInvoicing\Exceptions;

use RuntimeException;

/**
 * The intermediary refused the payment report on the spot (HTTP 400 or 422: the tax authority's refusal passed on at
 * once, as Moj-eRačun does, or a report it could not take), as against a report it took whose fiscalization the tax
 * authority refused later (read from the document's status); the message says why. The report may be made again once
 * the reason is put right, so an app words it as a refusal, not as a line that failed.
 */
class PaymentReportRefusedException extends RuntimeException {}
