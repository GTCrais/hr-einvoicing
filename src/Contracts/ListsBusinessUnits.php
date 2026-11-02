<?php

namespace GTCrais\HrEInvoicing\Contracts;

/**
 * A gateway whose intermediary lists the business units it knows a company receives e-invoices under, whose codes
 * address an e-invoice to one of them (BT-46, "9934:OIB::HR99:code"). The tax authority's AMS holds no business units,
 * only the company's OIB or GLN, and whether a unit is checked is the buyer's intermediary's affair (FINA refuses an
 * invoice sent to a wrong unit, some intermediaries take every one; ePoslovanje, 2026-11-02), so an empty list never
 * says the company has no unit: the code the buyer gave is typed then. EPoslovanje\EPoslovanjeClient and the fake
 * implement it; an app offers the list only where the gateway does.
 */
interface ListsBusinessUnits
{
	/**
	 * The company's business units by OIB, each with its name and its own code (the part after "HR99:"); none when the
	 * intermediary knows of none. A RuntimeException with the reason when the intermediary refuses or cannot be reached.
	 *
	 * @return list<array{name: string, code: string}>
	 */
	public function businessUnits(string $oib): array;
}
