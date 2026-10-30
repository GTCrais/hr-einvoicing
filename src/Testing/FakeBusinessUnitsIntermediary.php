<?php

namespace GTCrais\HrEInvoicing\Testing;

use GTCrais\HrEInvoicing\Contracts\ListsBusinessUnits;

/**
 * The fake as an intermediary that also lists a company's business units besides fiscalizing a document again
 * (ePoslovanje, and the fake under its own name), so a test sees the capability only where the real client has it.
 */
class FakeBusinessUnitsIntermediary extends FakeFiscalizingIntermediary implements ListsBusinessUnits
{
	public function businessUnits(string $oib): array
	{
		return $this->call(fn () => $this->fake->businessUnits($oib));
	}
}
