<?php

namespace GTCrais\HrEInvoicing\Ubl\Concerns;

use DateTimeInterface;
use DOMDocument;
use DOMElement;
use GTCrais\HrEInvoicing\Ubl\HrUbl;
use InvalidArgumentException;

/**
 * Writes the parts every Croatian e-invoice shares, the way Moj-eRačun's examples write them, for a builder that maps
 * its own invoices onto them: the document with its namespaces, the extensions (the Croatian VAT breakdown and the
 * signature block the intermediary fills), the heading, references to earlier invoices, the parties named by OIB,
 * delivery, payment by transfer, the VAT breakdown, the totals and the lines. Elements without text are left out,
 * as the rules forbid empty ones: a value blank once trimmed writes no element, and a part none of whose elements was
 * written (a contact without its operator, a delivery without its day) is left out with them; a payment by transfer
 * without the IBAN it is paid to is refused instead (EN 16931 BR-61). Text goes in as XML 1.0 takes it, whatever was
 * typed or pasted: a character it forbids (a control character, such as the line break Word pastes, U+000B) is left
 * out, the vertical tab and the form feed as a space, so one stray character never leaves a document no intermediary
 * can read.
 *
 * A VAT category is an array of the id ("S" standard, "E" exempt, "O" outside the scope of VAT, which every line of an
 * issuer outside the VAT system is), the Croatian name ("HR:PDV25", "HR:E", "HR:O"), the percent and the exemption's
 * code and reason (null for a standard rate); categories() groups the lines by id and rate and adds "base" and "tax"
 * to each. A party is an array of its OIB, legal name, street, city, postal code and country.
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
	 * outside the VAT system) or the issuer pays VAT on what it was paid ($cashBasis, which only an issuer in the VAT
	 * system can), and the signature block, which is the intermediary's to fill.
	 *
	 * @param  list<array<string, mixed>>  $categories
	 */
	protected function extensions(DOMElement $root, array $categories, float $net, float $tax, string $currency, bool $vatRegistered, bool $cashBasis = false): void
	{
		$extensions = $this->child($root, 'ext:UBLExtensions');
		$cashBasis = $cashBasis && $vatRegistered;

		if ($cashBasis || !$vatRegistered || array_filter($categories, fn (array $category) => $category['id'] !== 'S') !== []) {
			$this->hrData($this->child($this->child($extensions, 'ext:UBLExtension'), 'ext:ExtensionContent'), $categories, $net, $tax, $currency, $cashBasis);
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
	 * note takes back from, or an advance an invoice takes off, which says what was paid with it. None without the
	 * number, which is what the reference refers by (the one part of it the schema requires).
	 */
	protected function billingReference(DOMElement $root, string $number, string $issueDate, ?string $description = null): void
	{
		if ($this->xmlText($number) === null) {
			return;
		}

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

		$this->pruneEmpty($supplier);
	}

	/**
	 * The buyer: by OIB as the endpoint and the identifier, its seat, its VAT identifier when it is Croatian, and its
	 * legal name. A buyer that receives e-invoices by business unit (the tax authority's AMS lists it under one) is
	 * named by that unit's code beside its OIB in the identifier (BT-46, "9934:OIB::HR99:code"), the endpoint staying
	 * the OIB, as the HR CIUS's example of a business unit has it; without it the buyer's intermediary may fail to
	 * deliver the document. A Croatian buyer's VAT identifier is "HR" and its OIB whether or not it is in the VAT system: the
	 * Croatian rules HR-BR-S-1 and HR-BR-E-1 ask every invoice with a line at a standard rate or exempt for the buyer's
	 * VAT identifier (BT-48), and Moj-eRačun's and ePoslovanje's validators refused one without it, or with the OIB under
	 * the FRE scheme, on 2026-10-26. An invoice outside the scope of VAT (category O, an issuer outside the VAT system)
	 * takes none (BR-O-02), so withVatIdentifier false names the buyer by its OIB under the FRE scheme, as Moj-eRačun's
	 * example of one does.
	 *
	 * @param  array{oib: string, name: string, street: ?string, city: ?string, postalCode: ?string, country: string, businessUnit?: ?string}  $party
	 */
	protected function customerParty(DOMElement $root, array $party, bool $withVatIdentifier = true): void
	{
		$customer = $this->child($root, 'cac:AccountingCustomerParty');
		$element = $this->child($customer, 'cac:Party');
		$unit = HrUbl::businessUnitCode((string) ($party['businessUnit'] ?? ''), $party['oib']);

		$this->leaf($element, 'cbc:EndpointID', $party['oib'], ['schemeID' => HrUbl::OIB_SCHEME]);
		$this->leaf($this->child($element, 'cac:PartyIdentification'), 'cbc:ID', HrUbl::OIB_SCHEME . ':' . $party['oib'] . ($unit !== '' ? '::' . HrUbl::BUSINESS_UNIT_SCHEME . ':' . $unit : ''));
		$this->address($element, $party['street'], $party['city'], $party['postalCode'], $party['country']);

		if ($party['country'] === 'HR') {
			$scheme = $this->child($element, 'cac:PartyTaxScheme');
			$this->leaf($scheme, 'cbc:CompanyID', $withVatIdentifier ? 'HR' . $party['oib'] : $party['oib']);
			$this->leaf($this->child($scheme, 'cac:TaxScheme'), 'cbc:ID', $withVatIdentifier ? 'VAT' : 'FRE');
		}

		$legal = $this->child($element, 'cac:PartyLegalEntity');
		$this->leaf($legal, 'cbc:RegistrationName', $party['name']);
		$this->leaf($legal, 'cbc:CompanyID', $party['oib']);

		$this->pruneEmpty($customer);
	}

	/**
	 * The day what is invoiced was supplied; nothing without the day.
	 */
	protected function delivery(DOMElement $root, string $date): void
	{
		$delivery = $this->child($root, 'cac:Delivery');
		$this->leaf($delivery, 'cbc:ActualDeliveryDate', $date);

		$this->pruneEmpty($delivery);
	}

	/**
	 * Payment by credit transfer to the issuer's account, with the number as the payment reference. A credit transfer
	 * (code 30) names the account it is paid to (EN 16931 BR-61), so a blank IBAN is refused with an
	 * InvalidArgumentException, never written as a document the intermediary would refuse.
	 */
	protected function paymentMeans(DOMElement $root, string $paymentId, string $iban): void
	{
		$account = (string) preg_replace('/\s+/', '', $iban);

		if ($this->xmlText($account) === null) {
			throw new InvalidArgumentException('The issuer\'s IBAN is blank, and a payment by credit transfer names the account it is paid to (EN 16931 BR-61).');
		}

		$means = $this->child($root, 'cac:PaymentMeans');
		$this->leaf($means, 'cbc:PaymentMeansCode', '30');
		$this->leaf($means, 'cbc:PaymentID', $paymentId);
		$this->leaf($this->child($means, 'cac:PayeeFinancialAccount'), 'cbc:ID', $account);

		$this->pruneEmpty($means);
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
	 * (left out when null) and VAT category, with no rate when it is outside the scope of VAT (BR-O-05), and the unit
	 * net price.
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

		$this->taxCategory($item, 'cac:ClassifiedTaxCategory', $line['category'], withName: true, withRate: $line['category']['id'] !== 'O');

		$price = $this->child($element, 'cac:Price');
		$this->leaf($price, 'cbc:PriceAmount', number_format($line['unit'], 6, '.', ''), ['currencyID' => $currency]);
		$this->leaf($price, 'cbc:BaseQuantity', '1.000', ['unitCode' => HrUbl::UNIT]);

		// A classification code given blank, say, leaves its part empty
		$this->pruneEmpty($element);
	}

	/**
	 * The lines' categories with their bases and VAT added up, standard rates first, the highest first. EN 16931 takes
	 * one breakdown per standard rate and exactly one for every other category (BR-E-01, BR-Z-01, BR-AE-01 and the
	 * rest), so lines exempt on different grounds share one, which names every ground and keeps the exemption code
	 * only when they all have the same one; each line still carries its own.
	 *
	 * @param  list<array<string, mixed>>  $lines
	 * @return list<array<string, mixed>>
	 */
	protected function categories(array $lines): array
	{
		$categories = [];
		$reasons = [];

		foreach ($lines as $line) {
			$category = $line['category'];
			$key = $category['id'] === 'S' ? 'S|' . $this->percent((float) $category['percent']) : $category['id'];

			if (!isset($categories[$key])) {
				$categories[$key] = [...$category, 'key' => $key, 'base' => 0.0, 'tax' => 0.0];
				$reasons[$key] = [];
			} elseif (($categories[$key]['code'] ?? null) !== ($category['code'] ?? null)) {
				$categories[$key]['code'] = null;
			}

			if (trim((string) ($category['reason'] ?? '')) !== '') {
				$reasons[$key][$category['reason']] = true;
			}

			$categories[$key]['base'] = round($categories[$key]['base'] + $line['net'], 2);
			$categories[$key]['tax'] = round($categories[$key]['tax'] + $line['tax'], 2);
		}

		foreach ($categories as $key => $category) {
			$categories[$key]['reason'] = $reasons[$key] === [] ? null : implode('; ', array_keys($reasons[$key]));
		}

		uasort($categories, fn (array $a, array $b) => [$a['id'] !== 'S', -$a['percent']] <=> [$b['id'] !== 'S', -$b['percent']]);

		return array_values($categories);
	}

	/**
	 * The Croatian extension: an issuer's cash-basis VAT first (HR-BT-15, whose presence alone says it, so it is never
	 * written for an issuer that does not), then the VAT breakdown by category once more, with the Croatian category
	 * names, and the net split into what is within the scope of VAT and what is outside it (category O), as Moj-eRačun's
	 * example of an invoice outside its scope writes them.
	 *
	 * @param  list<array<string, mixed>>  $categories
	 */
	protected function hrData(DOMElement $content, array $categories, float $net, float $tax, string $currency, bool $cashBasis = false): void
	{
		$outOfScope = round(array_sum(array_map(fn (array $category) => $category['id'] === 'O' ? (float) $category['base'] : 0.0, $categories)), 2);
		$data = $this->child($content, 'hrextac:HRFISK20Data');

		if ($cashBasis) {
			$this->leaf($data, 'hrextac:HRObracunPDVPoNaplati', HrUbl::CASH_BASIS_NOTE);
		}

		$total = $this->child($data, 'hrextac:HRTaxTotal');
		$this->amount($total, 'cbc:TaxAmount', $tax, $currency);

		foreach ($categories as $category) {
			$subtotal = $this->child($total, 'hrextac:HRTaxSubtotal');
			$this->amount($subtotal, 'cbc:TaxableAmount', $category['base'], $currency);
			$this->amount($subtotal, 'cbc:TaxAmount', $category['tax'], $currency);
			$this->taxCategory($subtotal, 'hrextac:HRTaxCategory', $category, withName: true, scheme: 'hrextac:HRTaxScheme');
		}

		$legal = $this->child($data, 'hrextac:HRLegalMonetaryTotal');
		$this->amount($legal, 'cbc:TaxExclusiveAmount', round($net - $outOfScope, 2), $currency);
		$this->amount($legal, 'hrextac:OutOfScopeOfVATAmount', $outOfScope, $currency);
	}

	/**
	 * A VAT category as the document, a line or the extension carries it: the code, the Croatian name where asked,
	 * the rate unless left out, and the exemption's code and reason.
	 *
	 * @param  array<string, mixed>  $category
	 */
	protected function taxCategory(DOMElement $parent, string $name, array $category, bool $withName, string $scheme = 'cac:TaxScheme', bool $withRate = true): void
	{
		$element = $this->child($parent, $name);

		$this->leaf($element, 'cbc:ID', $category['id']);

		if ($withName) {
			$this->leaf($element, 'cbc:Name', $category['name']);
		}

		if ($withRate) {
			$this->leaf($element, 'cbc:Percent', $this->percent((float) $category['percent']));
		}
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

	/**
	 * A part of the document, written at once in its place: one that may get no element (every one of them left out for
	 * want of a value) is pruned once written (pruneEmpty()), as the trait's own parts are.
	 */
	protected function child(DOMElement $parent, string $name): DOMElement
	{
		$element = $parent->ownerDocument->createElementNS($this->namespace($name), $name);

		$parent->appendChild($element);

		return $element;
	}

	/**
	 * A leaf with text, left out when there is no text: the rules forbid empty elements, and a value blank once trimmed
	 * is none. The text, and the attributes', go in as XML 1.0 takes them (xmlText()).
	 *
	 * @param  array<string, string>  $attributes
	 */
	protected function leaf(DOMElement $parent, string $name, ?string $value, array $attributes = []): ?DOMElement
	{
		$text = $this->xmlText($value);

		if ($text === null) {
			return null;
		}

		$element = $this->child($parent, $name);
		$element->appendChild($parent->ownerDocument->createTextNode($text));

		foreach ($attributes as $attribute => $attributeValue) {
			$element->setAttribute($attribute, (string) $this->xmlText($attributeValue));
		}

		return $element;
	}

	/**
	 * A value as XML 1.0 takes it, null when it is blank once trimmed. A character outside XML's Char production makes
	 * a document no parser reads, and such characters come in typed or pasted (Word's line break is U+000B): the vertical
	 * tab and the form feed become a space, every other one is left out (the C0 control characters but the tab, the line
	 * feed and the carriage return, and U+FFFE and U+FFFF), and bytes that are no UTF-8 become "?". The rest is written
	 * as given, untrimmed, so a number is the number the app keeps.
	 */
	protected function xmlText(?string $value): ?string
	{
		if ($value === null) {
			return null;
		}

		$text = (string) preg_replace(
			'/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
			'',
			str_replace(["\v", "\f"], ' ', mb_scrub($value, 'UTF-8'))
		);

		return trim($text) === '' ? null : $text;
	}

	/**
	 * Leave out the elements under this one that nothing was written into, and this one too when it is left empty: a
	 * part every element of which was left out is an empty element, which the rules forbid. Never ask it of the root or
	 * the extensions, whose signature block stays empty for the intermediary to fill.
	 */
	protected function pruneEmpty(DOMElement $element): void
	{
		foreach (iterator_to_array($element->childNodes) as $node) {
			if ($node instanceof DOMElement) {
				$this->pruneEmpty($node);
			}
		}

		if (!$element->hasChildNodes() && !$element->hasAttributes()) {
			$element->parentNode?->removeChild($element);
		}
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
