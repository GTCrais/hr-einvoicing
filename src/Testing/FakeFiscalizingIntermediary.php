<?php

namespace GTCrais\HrEInvoicing\Testing;

use GTCrais\HrEInvoicing\Contracts\FiscalizesAgain;

/**
 * The fake as an intermediary that takes a request to fiscalize a document again (ePoslovanje, and the fake under its
 * own name), so a test sees the capability only where the real client has it.
 */
class FakeFiscalizingIntermediary extends FakeIntermediary implements FiscalizesAgain
{
	public function fiscalizeAgain(string $electronicId): void
	{
		$this->call(fn () => $this->fake->fiscalizeAgain($electronicId));
	}
}
