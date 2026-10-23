<?php

namespace GTCrais\HrEInvoicing\Testing;

use DOMDocument;
use LibXMLError;

/**
 * The UBL 2.1 schemas with the Croatian extension's components, which a test holds a built document against: the
 * Invoice schema for an invoice, the CreditNote schema for a credit note.
 */
class UblSchema
{
	/**
	 * What the schema of the document's kind says against it; nothing when it holds.
	 *
	 * @return list<string>
	 */
	public static function errors(string $xml): array
	{
		$previous = libxml_use_internal_errors(true);
		libxml_clear_errors();

		$document = new DOMDocument;
		$document->loadXML($xml);
		$schema = $document->documentElement?->localName === 'CreditNote' ? 'UBL-CreditNote-2.1.xsd' : 'UBL-Invoice-2.1.xsd';
		$document->schemaValidate(self::path('maindoc/' . $schema));
		$errors = array_map(fn (LibXMLError $error) => trim($error->message), libxml_get_errors());

		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		return $errors;
	}

	/**
	 * A file of the schemas, by its path under resources/ubl.
	 */
	public static function path(string $file = ''): string
	{
		return dirname(__DIR__, 2) . '/resources/ubl/' . $file;
	}
}
