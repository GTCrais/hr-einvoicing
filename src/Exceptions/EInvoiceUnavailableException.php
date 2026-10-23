<?php

namespace GTCrais\HrEInvoicing\Exceptions;

use RuntimeException;

/**
 * The intermediary's service itself is down: its ping, which needs no account, went unanswered or failed, so the
 * issuer's access details could not be checked at all; the message says what came back.
 */
class EInvoiceUnavailableException extends RuntimeException {}
