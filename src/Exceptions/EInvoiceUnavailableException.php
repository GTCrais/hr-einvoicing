<?php

namespace GTCrais\HrEInvoicing\Exceptions;

use RuntimeException;

/**
 * The intermediary's service itself is down, so the issuer's access details could not be checked at all: the
 * connection test (ping()) got no whole answer from it (none at all, or one cut off on its way), or an error of the
 * service's own, from its ping (Moj-eRačun's, which needs no account, failing in any way; ePoslovanje's, which answers
 * only a key it knows, with a server error) or from the call that checks the account after it (a server error). The
 * message says what came back.
 */
class EInvoiceUnavailableException extends RuntimeException {}
