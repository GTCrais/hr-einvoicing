<?php

namespace GTCrais\HrEInvoicing\Tests\Fixtures;

use DateTimeImmutable;
use GTCrais\HrEInvoicing\Ubl\Concerns\WritesHrUbl;
use GTCrais\HrEInvoicing\Ubl\HrUbl;

/**
 * A builder the way an app writes its own: it maps an invoice, here a plain array, onto what WritesHrUbl writes.
 */
class ExampleInvoiceBuilder
{
	use WritesHrUbl;

	/**
	 * @param  array<string, mixed>  $invoice
	 */
	public function build(array $invoice): string
	{
		$creditNote = $invoice['type'] === HrUbl::TYPE_CREDIT_NOTE;
		$lines = [];

		foreach ($invoice['lines'] as $index => $line) {
			$lines[] = [...$line, 'id' => $index + 1, 'unit' => round(abs($line['net']) / abs($line['quantity']), 6)];
		}

		$categories = $this->categories($lines);
		$net = round(array_sum(array_column($lines, 'net')), 2);
		$tax = round(array_sum(array_column($lines, 'tax')), 2);
		$gross = round($net + $tax, 2);
		$prepaid = $invoice['prepaid'] ?? null;

		$root = $this->document($creditNote);

		$this->extensions($root, $categories, $net, $tax, 'EUR', $invoice['vatRegistered']);
		$this->heading($root, $invoice['profile'], $invoice['number'], new DateTimeImmutable($invoice['issuedAt']));

		if ($creditNote) {
			$this->leaf($root, 'cbc:CreditNoteTypeCode', (string) $invoice['type']);
		} else {
			$this->leaf($root, 'cbc:DueDate', $invoice['dueOn'] ?? null);
			$this->leaf($root, 'cbc:InvoiceTypeCode', (string) $invoice['type']);
		}

		$this->leaf($root, 'cbc:DocumentCurrencyCode', 'EUR');

		if (isset($invoice['refersTo'])) {
			$this->billingReference($root, $invoice['refersTo']['number'], $invoice['refersTo']['issueDate']);
		}

		$this->supplierParty($root, $invoice['supplier'], $invoice['vatRegistered'], $invoice['supplier']['oib'], $invoice['operator']);
		$this->customerParty($root, $invoice['customer']);

		if (!$creditNote) {
			$this->delivery($root, substr($invoice['issuedAt'], 0, 10));
		}

		$this->paymentMeans($root, $invoice['number'], $invoice['iban']);
		$this->taxTotal($root, $categories, $tax, 'EUR');
		$this->monetaryTotal($root, $net, $gross, round($gross - ($prepaid ?? 0.0), 2), 'EUR', $prepaid);

		foreach ($lines as $line) {
			$this->line($root, $line, 'EUR', $creditNote);
		}

		return (string) $root->ownerDocument->saveXML();
	}
}
