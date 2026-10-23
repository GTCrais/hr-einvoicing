<?php

namespace GTCrais\HrEInvoicing\Exceptions;

use RuntimeException;

/**
 * The intermediary refused the document itself (HTTP 400 or 422: the rules it broke, a field it could not take), so
 * sending the same document again would only be refused again; the message says why.
 */
class EInvoiceRefusedException extends RuntimeException {}
