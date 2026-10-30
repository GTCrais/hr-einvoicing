<?php

namespace GTCrais\HrEInvoicing\Ubl;

use InvalidArgumentException;

/**
 * What the tax authority's specification fixes for every Croatian e-invoice: UBL 2.1 in the EN 16931 standard with
 * the Croatian CIUS and extension of 2025, the document types and business processes, and how the parties and the
 * lines are counted. Concerns\WritesHrUbl writes documents with them.
 */
class HrUbl
{
	/** The namespaces of an invoice's document, by prefix; a credit note's document namespace takes the place of '' */
	public const array NAMESPACES = [
		'' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
		'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
		'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
		'ext' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2',
		'sac' => 'urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2',
		'sig' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2',
		'hrextac' => 'urn:mfin.gov.hr:schema:xsd:HRExtensionAggregateComponents-1',
	];

	/** The document namespace of a credit note */
	public const string CREDIT_NOTE_NAMESPACE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';

	/** The specification the documents conform to: EN 16931 with the Croatian CIUS and extension of 2025 */
	public const string CUSTOMIZATION_ID = 'urn:cen.eu:en16931:2017#compliant#urn:mfin.gov.hr:cius-2025:1.0#conformant#urn:mfin.gov.hr:ext-2025:1.0';

	/** The business processes: an invoice, an advance invoice, a credit note, a corrective invoice (a storno) */
	public const string PROFILE_INVOICE = 'P1';

	public const string PROFILE_ADVANCE = 'P4';

	public const string PROFILE_CREDIT_NOTE = 'P9';

	public const string PROFILE_CORRECTIVE = 'P10';

	/** The document types: an invoice, a credit note, a corrective invoice, an advance invoice */
	public const int TYPE_INVOICE = 380;

	public const int TYPE_CREDIT_NOTE = 381;

	public const int TYPE_CORRECTIVE = 384;

	public const int TYPE_ADVANCE = 386;

	/** The OIB as an endpoint and party identifier scheme */
	public const string OIB_SCHEME = '9934';

	/** The scheme of a business unit's own code after the OIB ("9934:OIB::HR99:code", the HR CIUS's BT-46 of a buyer addressed by business unit) */
	public const string BUSINESS_UNIT_SCHEME = 'HR99';

	/** Pieces, the unit every line is counted in */
	public const string UNIT = 'H87';

	/**
	 * What an issuer in the VAT system that pays VAT on what it was paid ("postupak oporezivanja prema naplaćenim
	 * naknadama") states on every invoice, as the law has it, and so in the Croatian extension (HR-BT-15)
	 */
	public const string CASH_BASIS_NOTE = 'Obračun prema naplaćenim naknadama';

	/**
	 * A business unit's own code, from the code alone or the whole address an intermediary or the AMS gives
	 * ("9934:12345678903::HR99:1200" for the company of that OIB is 1200); '' for none. An InvalidArgumentException for
	 * a code with a space or a colon in it, or the address of another company.
	 */
	public static function businessUnitCode(string $value, string $oib): string
	{
		$value = trim($value);
		$prefix = self::OIB_SCHEME . ':' . $oib . '::' . self::BUSINESS_UNIT_SCHEME . ':';

		if (str_starts_with($value, $prefix)) {
			$value = substr($value, strlen($prefix));
		} elseif (str_contains($value, '::' . self::BUSINESS_UNIT_SCHEME . ':')) {
			throw new InvalidArgumentException('The business unit ' . $value . ' is not one of the company ' . $oib . '.');
		}

		if ($value !== '' && preg_match('/[\s:]/u', $value)) {
			throw new InvalidArgumentException('A business unit\'s code has no space or colon in it: ' . $value . '.');
		}

		return $value;
	}
}
