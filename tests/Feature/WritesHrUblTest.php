<?php

use GTCrais\HrEInvoicing\Testing\UblSchema;
use GTCrais\HrEInvoicing\Tests\Fixtures\ExampleInvoiceBuilder;
use GTCrais\HrEInvoicing\Ubl\HrUbl;

function standardRate(float $percent): array
{
	return ['key' => 'S|' . $percent, 'id' => 'S', 'name' => 'HR:PDV' . rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.'), 'percent' => $percent, 'code' => null, 'reason' => null];
}

function exemption(?string $code, string $reason): array
{
	return ['key' => 'E|' . $code . '|' . $reason, 'id' => 'E', 'name' => 'HR:E', 'percent' => 0.0, 'code' => $code, 'reason' => $reason];
}

/**
 * An invoice of a clinic in the VAT system to a company: an exempt examination and goods at two standard rates.
 */
function exampleInvoice(array $overrides = []): array
{
	return [...[
		'type' => HrUbl::TYPE_INVOICE,
		'profile' => HrUbl::PROFILE_INVOICE,
		'number' => '17/P1/1',
		'issuedAt' => '2026-10-23 10:15:00',
		'dueOn' => '2026-11-07',
		'vatRegistered' => true,
		'supplier' => ['oib' => '12345678903', 'name' => 'Poliklinika Primjer d.o.o.', 'street' => 'Ilica 1', 'city' => 'Zagreb', 'postalCode' => '10000', 'country' => 'HR'],
		'operator' => 'Ana Horvat',
		'customer' => ['oib' => '98765432106', 'name' => 'Tvrtka Kupac d.o.o.', 'street' => 'Vukovarska 10', 'city' => 'Split', 'postalCode' => '21000', 'country' => 'HR'],
		'iban' => 'HR12 1001 0051 8630 0016 0',
		'lines' => [
			['name' => 'Sistematski pregled', 'quantity' => 2, 'net' => 160.0, 'tax' => 0.0, 'kpd' => '86.22.19', 'category' => exemption('VATEX-EU-132-1C', 'Oslobođeno PDV-a: zdravstvena usluga')],
			['name' => 'Krema za sunčanje', 'quantity' => 1, 'net' => 20.0, 'tax' => 5.0, 'kpd' => '20.42.19', 'category' => standardRate(25.0)],
			['name' => 'Laboratorijski nalaz', 'quantity' => 1, 'net' => 40.0, 'tax' => 2.0, 'kpd' => '86.90.15', 'category' => standardRate(5.0)],
		],
	], ...$overrides];
}

function exampleXpath(string $xml): DOMXPath
{
	$document = new DOMDocument;
	$document->loadXML($xml);
	$xpath = new DOMXPath($document);
	$xpath->registerNamespace('cn', HrUbl::CREDIT_NOTE_NAMESPACE);

	foreach (HrUbl::NAMESPACES as $prefix => $uri) {
		$xpath->registerNamespace($prefix === '' ? 'inv' : $prefix, $uri);
	}

	return $xpath;
}

it('writes an invoice the schema takes: the heading, both parties by OIB, VAT by category with the Croatian extension, and the lines', function () {
	$xml = (new ExampleInvoiceBuilder)->build(exampleInvoice());
	$xpath = exampleXpath($xml);

	expect(UblSchema::errors($xml))->toBe([])
		->and($xpath->evaluate('string(/inv:Invoice/cbc:CustomizationID)'))->toBe(HrUbl::CUSTOMIZATION_ID)
		->and($xpath->evaluate('string(/inv:Invoice/cbc:ProfileID)'))->toBe('P1')
		->and($xpath->evaluate('string(/inv:Invoice/cbc:IssueTime)'))->toBe('10:15:00')
		->and($xpath->evaluate('string(/inv:Invoice/cbc:InvoiceTypeCode)'))->toBe('380')
		->and($xpath->evaluate('string(/inv:Invoice/cac:AccountingSupplierParty/cac:Party/cbc:EndpointID/@schemeID)'))->toBe('9934')
		->and($xpath->evaluate('string(/inv:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID)'))->toBe('HR12345678903')
		->and($xpath->evaluate('string(/inv:Invoice/cac:AccountingSupplierParty/cac:SellerContact/cbc:Name)'))->toBe('Ana Horvat')
		->and($xpath->evaluate('string(/inv:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyIdentification/cbc:ID)'))->toBe('9934:98765432106')
		->and($xpath->evaluate('string(/inv:Invoice/cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID)'))->toBe('HR1210010051863000160')
		// Standard rates first, the highest first, then the exemption with its code and reason
		->and(collect(iterator_to_array($xpath->query('/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:Percent')))->map->textContent->all())->toBe(['25', '5', '0'])
		->and($xpath->evaluate('string(/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal[3]/cac:TaxCategory/cbc:TaxExemptionReasonCode)'))->toBe('VATEX-EU-132-1C')
		->and($xpath->evaluate('string(/inv:Invoice/cac:TaxTotal/cbc:TaxAmount)'))->toBe('7.00')
		->and($xpath->evaluate('count(//hrextac:HRFISK20Data/hrextac:HRTaxTotal/hrextac:HRTaxSubtotal)'))->toBe(3.0)
		->and($xpath->evaluate('string(/inv:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount)'))->toBe('227.00')
		->and($xpath->evaluate('count(/inv:Invoice/cac:LegalMonetaryTotal/cbc:PrepaidAmount)'))->toBe(0.0)
		->and($xpath->evaluate('string(/inv:Invoice/cac:InvoiceLine[1]/cbc:InvoicedQuantity)'))->toBe('2.000')
		->and($xpath->evaluate('string(/inv:Invoice/cac:InvoiceLine[1]/cac:Price/cbc:PriceAmount)'))->toBe('80.000000')
		->and($xpath->evaluate('string(/inv:Invoice/cac:InvoiceLine[1]/cac:Item/cac:CommodityClassification/cbc:ItemClassificationCode/@listID)'))->toBe('CG');

	// The rules forbid empty elements; only the signature block is left for the intermediary to fill
	expect(collect(iterator_to_array($xpath->query('//*[not(node())]')))->map->localName->all())->toBe(['SignatureInformation']);
});

it('names an issuer outside the VAT system by its OIB under the FRE scheme, with every line exempt in the Croatian extension', function () {
	$reason = 'Obveznik nije u sustavu PDV-a';
	$xml = (new ExampleInvoiceBuilder)->build(exampleInvoice([
		'vatRegistered' => false,
		'lines' => [
			['name' => 'Sistematski pregled', 'quantity' => 1, 'net' => 80.0, 'tax' => 0.0, 'kpd' => '86.22.19', 'category' => exemption(null, $reason)],
		],
	]));
	$xpath = exampleXpath($xml);

	expect(UblSchema::errors($xml))->toBe([])
		->and($xpath->evaluate('string(//cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID)'))->toBe('12345678903')
		->and($xpath->evaluate('string(//cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cac:TaxScheme/cbc:ID)'))->toBe('FRE')
		->and($xpath->evaluate('string(//hrextac:HRTaxSubtotal/hrextac:HRTaxCategory/cbc:TaxExemptionReason)'))->toBe($reason)
		->and($xpath->evaluate('count(//cbc:TaxExemptionReasonCode)'))->toBe(0.0);
});

it('leaves the Croatian extension out when every line is at a standard rate', function () {
	$xml = (new ExampleInvoiceBuilder)->build(exampleInvoice(['lines' => [
		['name' => 'Krema za sunčanje', 'quantity' => 1, 'net' => 20.0, 'tax' => 5.0, 'kpd' => '20.42.19', 'category' => standardRate(25.0)],
	]]));

	expect(UblSchema::errors($xml))->toBe([])
		->and(exampleXpath($xml)->evaluate('count(//hrextac:HRFISK20Data)'))->toBe(0.0);
});

it('writes a storno that points back at the invoice, and an advance with what was paid before', function () {
	$storno = (new ExampleInvoiceBuilder)->build(exampleInvoice([
		'type' => HrUbl::TYPE_CORRECTIVE,
		'profile' => HrUbl::PROFILE_CORRECTIVE,
		'number' => '18/P1/1',
		'dueOn' => null,
		'refersTo' => ['number' => '17/P1/1', 'issueDate' => '2026-10-23'],
		'lines' => [
			['name' => 'Krema za sunčanje', 'quantity' => -1, 'net' => -20.0, 'tax' => -5.0, 'kpd' => '20.42.19', 'category' => standardRate(25.0)],
		],
	]));
	$advance = (new ExampleInvoiceBuilder)->build(exampleInvoice([
		'type' => HrUbl::TYPE_ADVANCE,
		'profile' => HrUbl::PROFILE_ADVANCE,
		'number' => '19/P1/1',
		'dueOn' => '2026-10-23',
		'prepaid' => 125.0,
		'lines' => [
			['name' => 'Predujam za zahvat', 'quantity' => 1, 'net' => 100.0, 'tax' => 25.0, 'kpd' => null, 'category' => standardRate(25.0)],
		],
	]));
	$stornoXpath = exampleXpath($storno);
	$advanceXpath = exampleXpath($advance);

	expect(UblSchema::errors($storno))->toBe([])
		->and($stornoXpath->evaluate('string(/inv:Invoice/cbc:InvoiceTypeCode)'))->toBe('384')
		->and($stornoXpath->evaluate('string(/inv:Invoice/cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID)'))->toBe('17/P1/1')
		->and($stornoXpath->evaluate('count(/inv:Invoice/cbc:DueDate)'))->toBe(0.0)
		->and($stornoXpath->evaluate('string(/inv:Invoice/cac:InvoiceLine/cbc:InvoicedQuantity)'))->toBe('-1.000')
		->and($stornoXpath->evaluate('string(/inv:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount)'))->toBe('-25.00')
		->and(UblSchema::errors($advance))->toBe([])
		->and($advanceXpath->evaluate('string(/inv:Invoice/cbc:ProfileID)'))->toBe('P4')
		->and($advanceXpath->evaluate('string(/inv:Invoice/cac:LegalMonetaryTotal/cbc:PrepaidAmount)'))->toBe('125.00')
		->and($advanceXpath->evaluate('string(/inv:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount)'))->toBe('0.00')
		->and($advanceXpath->evaluate('count(//cac:CommodityClassification)'))->toBe(0.0);
});

it('writes a credit note as a CreditNote document, which the credit note schema takes', function () {
	$xml = (new ExampleInvoiceBuilder)->build(exampleInvoice([
		'type' => HrUbl::TYPE_CREDIT_NOTE,
		'profile' => HrUbl::PROFILE_CREDIT_NOTE,
		'number' => '20/P1/1',
		'refersTo' => ['number' => '17/P1/1', 'issueDate' => '2026-10-23'],
		'lines' => [
			['name' => 'Sistematski pregled', 'quantity' => 1, 'net' => 80.0, 'tax' => 0.0, 'kpd' => '86.22.19', 'category' => exemption('VATEX-EU-132-1C', 'Oslobođeno PDV-a: zdravstvena usluga')],
		],
	]));
	$xpath = exampleXpath($xml);

	expect(UblSchema::errors($xml))->toBe([])
		->and($xpath->evaluate('string(/cn:CreditNote/cbc:CreditNoteTypeCode)'))->toBe('381')
		->and($xpath->evaluate('string(/cn:CreditNote/cac:CreditNoteLine/cbc:CreditedQuantity)'))->toBe('1.000')
		->and($xpath->evaluate('count(/cn:CreditNote/cac:Delivery)'))->toBe(0.0);
});

it('says what the schema has against a document that breaks it', function () {
	expect(UblSchema::errors('<Invoice xmlns="' . HrUbl::NAMESPACES[''] . '"/>'))->not->toBe([])
		->and(UblSchema::errors('<CreditNote xmlns="' . HrUbl::CREDIT_NOTE_NAMESPACE . '"><Unknown/></CreditNote>'))->not->toBe([]);
});
