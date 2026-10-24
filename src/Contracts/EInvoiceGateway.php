<?php

namespace GTCrais\HrEInvoicing\Contracts;

use DateTimeInterface;
use GTCrais\HrEInvoicing\DocumentStatus;
use GTCrais\HrEInvoicing\FiscalizationStatus;

/**
 * The issuer's line to its information intermediary, which delivers e-invoices to businesses, reports them to the tax
 * authority and takes the payment reports. MojEracun\MojEracunClient talks to Moj-eRačun, EPoslovanje\EPoslovanjeClient
 * to ePoslovanje, and Testing\FakeEInvoiceGateway answers at once in a test suite; GatewayManager hands out the one
 * asked for by name. Every answer speaks the package's own DocumentStatus and FiscalizationStatus, whatever the
 * intermediary's codes. Every method throws a RuntimeException with the reason when the intermediary refuses or
 * cannot be reached, an EInvoiceRefusedException when it refused the document itself, which no new try changes.
 */
interface EInvoiceGateway
{
	/**
	 * Whether the issuer's account with the intermediary is in place.
	 */
	public function ready(): bool;

	/**
	 * Send one e-invoice and return the intermediary's identifier of it and its first standing.
	 *
	 * @return array{electronicId: string, status: DocumentStatus}
	 */
	public function send(string $xml): array;

	/**
	 * The document of this number the intermediary holds from a send since the given moment, its identifier and
	 * standing, for a try whose answer was lost on the way back; null when it holds none.
	 *
	 * @return array{electronicId: string, status: DocumentStatus}|null
	 */
	public function sentDocument(string $number, DateTimeInterface $since): ?array;

	/**
	 * Where a sent e-invoice stands with the intermediary and with the tax authority (null while the intermediary has
	 * said nothing about its fiscalization), with the reason when either refused it.
	 *
	 * @return array{status: DocumentStatus, fiscalization: ?FiscalizationStatus, error: ?string}
	 */
	public function status(string $electronicId): array;

	/**
	 * Report to the tax authority, through the intermediary, that the e-invoice was paid by bank transfer on the given
	 * day (Y-m-d).
	 */
	public function reportPayment(string $electronicId, string $paidOn, float $amount): void;

	/**
	 * Reach the intermediary with the account in place; an EInvoiceUnavailableException when the service itself is
	 * down and the account could not be tried.
	 */
	public function ping(): void;
}
