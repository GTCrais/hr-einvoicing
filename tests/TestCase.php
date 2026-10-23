<?php

namespace GTCrais\HrEInvoicing\Tests;

use GTCrais\HrEInvoicing\HrEInvoicingServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
	/**
	 * @return list<class-string>
	 */
	protected function getPackageProviders($app): array
	{
		return [HrEInvoicingServiceProvider::class];
	}
}
