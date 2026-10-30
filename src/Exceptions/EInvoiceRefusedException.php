<?php

namespace GTCrais\HrEInvoicing\Exceptions;

use RuntimeException;

/**
 * The intermediary refused the document itself (the rules it broke, a field of it it could not take, a number it
 * already holds, as Moj-eRačun says of a duplicate in an otherwise successful answer), so sending the same document
 * again would only be refused again; the message says why. A refusal of the account (a wrong software identifier,
 * a password) is a plain RuntimeException, as a later try with the account put right may pass.
 */
class EInvoiceRefusedException extends RuntimeException {}
