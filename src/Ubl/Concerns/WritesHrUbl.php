<?php

namespace GTCrais\HrEInvoicing\Ubl\Concerns;

use DateTimeInterface;
use DOMDocument;
use DOMElement;
use GTCrais\HrEInvoicing\Ubl\HrUbl;

/**
 * Writes the parts every Croatian e-invoice shares, the way Moj-eRačun's examples write them, for a builder that maps
 * its own invoices onto them: the document with its namespaces, the extensions (the Croatian VAT breakdown and the
 * signature block the intermediary fills), the heading, references to earlier invoices, the parties named by OIB,
 * delivery, payment by transfer, the VAT breakdown, the totals and the lines. Elements without text are left out,
 * as the rules forbid empty ones.
 *
 * A VAT category is an array of the id ("S" standard, "E" exempt), the Croatian name ("HR:PDV25", "HR:E"), the
 * percent, the exemption's code and reason (null for a standard rate) and a key that tells categories apart; the
 * totals add "base" and "tax" to it. A party is an array of its OIB, legal name, street, city, postal code and country.
 */
trait WritesHrUbl
{
	/**
	 * A new document's root, an Invoice or a CreditNote, with every namespace the documents use declared on it.
	 */
	protected function document(bool $creditNote = false): DOMElement
	{
		$document = new DOMDocument('1.0', 'UTF-8');
		$document->formatOutput = true;

		$root = $document->createElementNS($creditNote ? HrUbl::CREDIT_NOTE_NAMESPACE : HrUbl::NAMESPACES[''], $creditNote ? 'CreditNote' : 'Invoice');

		foreach (HrUbl::NAMESPACES as $prefix => $uri) {
			if ($prefix !== '') {
				$root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:' . $prefix, $uri);
			}
		}

		$document->appendChild($root);

		return $root;
	}

	/**
	 * The extensions: the Croatian VAT breakdown whenever anything is not at a standard rate (always for an issuer
	 * outside the VAT system), and the signature block, which is the intermediary's to fill.
	 *
	 * @param  list<array<string, mixed>>  $categories
	 */
	protected function extensions(DOMElement $root, array $categories, float $net, float $tax, string $currency, bool $vatRegistered): void
	{
		$extensions = $this->child($root, 'ext:UBLExtensions');

		if (!$vatRegistered || array_filter($categories, fn (array $category) => $category['id'] !== 'S') !== []) {
			$this->hrData($this->child($this->child($extensions, 'ext:UBLExtension'), 'ext:ExtensionContent'), $categories, $net, $tax, $currency);
		}

		$this->child($this->child($this->child($this->child($extensions, 'ext:UBLExtension'), 'ext:ExtensionContent'), 'sig:UBLDocumentSignatures'), 'sac:SignatureInformation');
	}

	/**
	 * What every document opens with: the specification, the business process, the number and the moment of issue
	 * on the issuer's calendar.
	 */
	protected function heading(DOMElement $root, string $profile, string $number, DateTimeInterface $issued): void
	{
		$this->leaf($root, 'cbc:CustomizationID', HrUbl::CUSTOMIZATION_ID);
		$this->leaf($root, 'cbc:ProfileID', $profile);
		$this->leaf($root, 'cbc:ID', $number);
		$this->leaf($root, 'cbc:IssueDate', $issued->format('Y-m-d'));
		$this->leaf($root, 'cbc:IssueTime', $issued->format('H:i:s'));
	}

	/**
	 * A reference to an earlier invoice by its number and day of issue: the one a storno cancels, the one a credit
	 * note takes back from, or an advance an invoice takes off, which says what was paid with it.
	 */
	protected function billingReference(DOMElement $root, string $number, string $issueDate, ?string $description = null): void
	{
		$reference = $this->child($this->child($root, 'cac:BillingReference'), 'cac:InvoiceDocumentReference');
		$this->leaf($reference, 'cbc:ID', $number);
		$this->leaf($reference, 'cbc:IssueDate', $issueDate);
		$this->leaf($reference, 'cbc:DocumentDescription', $description);
	}

	/**
	 * The issuer: by OIB as the endpoint and the identifier, its registered seat, its VAT identifier (or its OIB under
	 * the "FRE" scheme outside the VAT system), its legal name, and the operator who issues on its behalf.
	 *
	 * @param  array{oib: string, name: string, street: ?string, city: ?string, postalCode: ?string, country: string}  $party
	 */
	protected function supplierParty(DOMElement $root, array $party, bool $vatRegistered, string $operatorOib, string $operatorName): void
	{
		$supplier = $this->child($root, 'cac:AccountingSupplierParty');
		$element = $this->child($supplier, 'cac:Party');

		$this->leaf($element, 'cbc:EndpointID', $party['oib'], ['schemeID' => HrUbl::OIB_SCHEME]);
		$this->leaf($this->child($element, 'cac:PartyIdentification'), 'cbc:ID', HrUbl::OIB_SCHEME . ':' . $party['oib']);
		$this->address($element, $party['street'], $party['city'], $party['postalCode'], $party['country']);

		$scheme = $this->child($element, 'cac:PartyTaxScheme');
		$this->leaf($scheme, 'cbc:CompanyID', $vatRegistered ? 'HR' . $party['oib'] : $party['oib']);
		$this->leaf($this->child($scheme, 'cac:TaxScheme'), 'cbc:ID', $vatRegistered ? 'VAT' : 'FRE');

		$legal = $this->child($element, 'cac:PartyLegalEntity');
		$this->leaf($legal, 'cbc:RegistrationName', $party['name']);
		$this->leaf($legal, 'cbc:CompanyID', $party['oib']);

		$contact = $this->child($supplier, 'cac:SellerContact');
		$this->leaf($contact, 'cbc:ID', $operatorOib);
		$this->leaf($contact, 'cbc:Name', $operatorName);
	}

	/**
	 * The buyer: by OIB as the endpoint and the identifier, its seat, its VAT identifier when it is Croatian, and its
	 * legal name.
	 *
	 * @param  array{oib: string, name: string, street: ?string, city: ?string, postalCode: ?string, country: string}  $party
	 */
	protected function customerParty(DOMElement $root, array $party): void
	{
		$element = $this->child($this->child($root, 'cac:AccountingCustomerParty'), 'cac:Party');

		$this->leaf($element, 'cbc:EndpointID', $party['oib'], ['schemeID' => HrUbl::OIB_SCHEME]);
		$this->leaf($this->child($element, 'cac:PartyIdentification'), 'cbc:ID', HrUbl::OIB_SCHEME . ':' . $party['oib']);
		$this->address($element, $party['street'], $party['city'], $party['postalCode'], $party['country']);

		if ($party['country'] === 'HR') {
			$scheme = $this->child($element, 'cac:PartyTaxScheme');
			$this->leaf($scheme, 'cbc:CompanyID', 'HR' . $party['oib']);
			$this->leaf($this->child($scheme, 'cac:TaxScheme'), 'cbc:ID', 'VAT');
		}

		$legal = $this->child($element, 'cac:PartyLegalEntity');
		$this->leaf($legal, 'cbc:RegistrationName', $party['name']);
		$this->leaf($legal, 'cbc:CompanyID', $party['oib']);
	}

	/**
	 * The day what is invoiced was supplied.
	 */
	protected function delivery(DOMElement $root, string $date): void
	{
		$this->leaf($this->child($root, 'cac:Delivery'), 'cbc:ActualDeliveryDate', $date);
	}

	/**
	 * Payment by credit transfer to the issuer's account, with the number as the payment reference.
	 */
	protected function paymentMeans(DOMElement $root, string $paymentId, string $iban): void
	{
		$means = $this->child($root, 'cac:PaymentMeans');
		$this->leaf($means, 'cbc:PaymentMeansCode', '30');
		$this->leaf($means, 'cbc:PaymentID', $paymentId);
		$this->leaf($this->child($means, 'cac:PayeeFinancialAccount'), 'cbc:ID', preg_replace('/\s+/', '', $iban));
	}

	/**
	 * The VAT of the document, broken down by category.
	 *
	 * @param  list<array<string, mixed>>  $categories
	 */
	protected function taxTotal(DOMElement $root, array $categories, float $tax, string $currency): void
	{
		$taxTotal = $this->child($root, 'cac:TaxTotal');
		$this->amount($taxTotal, 'cbc:TaxAmount', $tax, $currency);

		foreach ($categories as $category) {
			$subtotal = $this->child($taxTotal, 'cac:TaxSubtotal');
			$this->amount($subtotal, 'cbc:TaxableAmount', $category['base'], $currency);
			$this->amount($subtotal, 'cbc:TaxAmount', $category['tax'], $currency);
			$this->taxCategory($subtotal, 'cac:TaxCategory', $category, withName: false);
		}
	}

	/**
	 * The totals: the lines' net, the document's net and gross, what was paid before (left out when null) and what
	 * is payable.
	 */
	protected function monetaryTotal(DOMElement $root, float $net, float $gross, float $payable, string $currency, ?float $prepaid = null): void
	{
		$totals = $this->child($root, 'cac:LegalMonetaryTotal');
		$this->amount($totals, 'cbc:LineExtensionAmount', $net, $currency);
		$this->amount($totals, 'cbc:TaxExclusiveAmount', $net, $currency);
		$this->amount($totals, 'cbc:TaxInclusiveAmount', $gross, $currency);

		if ($prepaid !== null) {
			$this->amount($totals, 'cbc:PrepaidAmount', $prepaid, $currency);
		}

		$this->amount($totals, 'cbc:PayableAmount', $payable, $currency);
	}

	/**
	 * A line of an invoice or a credit note: its quantity in pieces, its net, the item's name, classification code
	 * (left out when null) and VAT category, and the unit net price.
	 *
	 * @param  array{id: int, name: string, quantity: int|float, net: float, unit: float, kpd: ?string, category: array<string, mixed>}  $line
	 */
	protected function line(DOMElement $root, array $line, string $currency, bool $creditNote = false): void
	{
		$element = $this->child($root, $creditNote ? 'cac:CreditNoteLine' : 'cac:InvoiceLine');
		$this->leaf($element, 'cbc:ID', (string) $line['id']);
		$this->leaf($element, $creditNote ? 'cbc:CreditedQuantity' : 'cbc:InvoicedQuantity', number_format($line['quantity'], 3, '.', ''), ['unitCode' => HrUbl::UNIT]);
		$this->amount($element, 'cbc:LineExtensionAmount', $line['net'], $currency);

		$item = $this->child($element, 'cac:Item');
		$this->leaf($item, 'cbc:Name', $line['name']);

		if ($line['kpd'] !== null) {
			$this->leaf($this->child($item, 'cac:CommodityClassification'), 'cbc:ItemClassificationCode', $line['kpd'], ['listID' => 'CG']);
		}

		$this->taxCategory($item, 'cac:ClassifiedTaxCategory', $line['category'], withName: true);

		$price = $this->child($element, 'cac:Price');
		$this->leaf($price, 'cbc:PriceAmount', number_format($line['unit'], 6, '.', ''), ['currencyID' => $currency]);
		$this->leaf($price, 'cbc:BaseQuantity', '1.000', ['unitCode' => HrUbl::UNIT]);
	}

	/**
	 * The lines' categories with their bases and VAT added up, standard rates first, the highest first.
	 *
	 * @param  list<array<string, mixed>>  $lines
	 * @return list<array<string, mixed>>
	 */
	protected function categories(array $lines): array
	{
		$categories = [];

		foreach ($lines as $line) {
			$key = $line['category']['key'];

			$categories[$key] ??= [...$line['category'], 'base' => 0.0, 'tax' => 0.0];
			$categories[$key]['base'] = round($categories[$key]['base'] + $line['net'], 2);
			$categories[$key]['tax'] = round($categories[$key]['tax'] + $line['tax'], 2);
		}

		uasort($categories, fn (array $a, array $b) => [$a['id'] !== 'S', -$a['percent']] <=> [$b['id'] !== 'S', -$b['percent']]);

		return array_values($categories);
	}

	/**
	 * The Croatian extension: the VAT breakdown by category once more, with the Croatian category names, and the
	 * total outside the scope of VAT, always zero: the documents written here carry nothing outside its scope.
	 *
	 * @param  list<array<string, mixed>>  $categories
	 */
	protected function hrData(DOMElement $content, array $categories, float $net, float $tax, string $currency): void
	{
		$data = $this->child($content, 'hrextac:HRFISK20Data');
		$total = $this->child($data, 'hrextac:HRTaxTotal');
		$this->amount($total, 'cbc:TaxAmount', $tax, $currency);

		foreach ($categories as $category) {
			$subtotal = $this->child($total, 'hrextac:HRTaxSubtotal');
			$this->amount($subtotal, 'cbc:TaxableAmount', $category['base'], $currency);
			$this->amount($subtotal, 'cbc:TaxAmount', $category['tax'], $currency);
			$this->taxCategory($subtotal, 'hrextac:HRTaxCategory', $category, withName: true, scheme: 'hrextac:HRTaxScheme');
		}

		$legal = $this->child($data, 'hrextac:HRLegalMonetaryTotal');
		$this->amount($legal, 'cbc:TaxExclusiveAmount', $net, $currency);
		$this->amount($legal, 'hrextac:OutOfScopeOfVATAmount', 0.0, $currency);
	}

	/**
	 * A VAT category as the document, a line or the extension carries it: the code, the Croatian name where asked,
	 * the rate, and the exemption's code and reason.
	 *
	 * @param  array<string, mixed>  $category
	 */
	protected function taxCategory(DOMElement $parent, string $name, array $category, bool $withName, string $scheme = 'cac:TaxScheme'): void
	{
		$element = $this->child($parent, $name);

		$this->leaf($element, 'cbc:ID', $category['id']);

		if ($withName) {
			$this->leaf($element, 'cbc:Name', $category['name']);
		}

		$this->leaf($element, 'cbc:Percent', $this->percent((float) $category['percent']));
		$this->leaf($element, 'cbc:TaxExemptionReasonCode', $category['code'] ?? null);
		$this->leaf($element, 'cbc:TaxExemptionReason', $category['reason'] ?? null);
		$this->leaf($this->child($element, $scheme), 'cbc:ID', 'VAT');
	}

	protected function address(DOMElement $party, ?string $street, ?string $city, ?string $postalCode, string $country): void
	{
		$address = $this->child($party, 'cac:PostalAddress');

		$this->leaf($address, 'cbc:StreetName', $street);
		$this->leaf($address, 'cbc:CityName', $city);
		$this->leaf($address, 'cbc:PostalZone', $postalCode);
		$this->leaf($this->child($address, 'cac:Country'), 'cbc:IdentificationCode', $country);
	}

	protected function child(DOMElement $parent, string $name): DOMElement
	{
		$element = $parent->ownerDocument->createElementNS($this->namespace($name), $name);

		$parent->appendChild($element);

		return $element;
	}

	/**
	 * A leaf with text, left out when there is no text: the rules forbid empty elements.
	 *
	 * @param  array<string, string>  $attributes
	 */
	protected function leaf(DOMElement $parent, string $name, ?string $value, array $attributes = []): ?DOMElement
	{
		if ($value === null || $value === '') {
			return null;
		}

		$element = $this->child($parent, $name);
		$element->appendChild($parent->ownerDocument->createTextNode($value));

		foreach ($attributes as $attribute => $attributeValue) {
			$element->setAttribute($attribute, $attributeValue);
		}

		return $element;
	}

	protected function amount(DOMElement $parent, string $name, float $value, string $currency): void
	{
		$this->leaf($parent, $name, number_format($value, 2, '.', ''), ['currencyID' => $currency]);
	}

	/**
	 * An amount in a description, as the examples write it: "125.00 EUR".
	 */
	protected function money(float $amount, string $currency): string
	{
		return number_format($amount, 2, '.', '') . ' ' . $currency;
	}

	/**
	 * A rate as the document writes it: "25", "13", "5", "0".
	 */
	protected function percent(float $rate): string
	{
		return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
	}

	protected function namespace(string $name): string
	{
		$prefix = str_contains($name, ':') ? strstr($name, ':', true) : '';

		return HrUbl::NAMESPACES[$prefix];
	}
}
