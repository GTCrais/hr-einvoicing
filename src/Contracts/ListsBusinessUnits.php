<?php

namespace GTCrais\HrEInvoicing\Contracts;

/**
 * A gateway whose intermediary lists the business units a company is registered under for receiving e-invoices (the
 * tax authority's AMS), whose codes address an e-invoice to one of them (BT-46, "9934:OIB::HR99:code").
 * EPoslovanje\EPoslovanjeClient and the fake implement it; an app offers the list only where the gateway does.
 */
interface ListsBusinessUnits
{
	/**
	 * The company's business units by OIB, each with its name and its own code (the part after "HR99:"); none when the
	 * company has none. A RuntimeException with the reason when the intermediary refuses or cannot be reached.
	 *
	 * @return list<array{name: string, code: string}>
	 */
	public function businessUnits(string $oib): array;
}
