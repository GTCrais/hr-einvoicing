<?php

namespace GTCrais\HrEInvoicing\Contracts;

use DateTimeInterface;

/**
 * The issuer's line to its information intermediary, which delivers e-invoices to businesses, reports them to the tax
 * authority and takes the payment reports. MojEracunClient talks to Moj-eRačun; Testing\FakeEInvoiceGateway answers
 * at once in a test suite. Every method throws a RuntimeException with the reason when the intermediary refuses or
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
	 * @return array{electronicId: string, status: int}
	 */
	public function send(string $xml): array;

	/**
	 * The document of this number the intermediary holds from a send since the given moment, its identifier and
	 * standing, for a try whose answer was lost on the way back; null when it holds none.
	 *
	 * @return array{electronicId: string, status: int}|null
	 */
	public function sentDocument(string $number, DateTimeInterface $since): ?array;

	/**
	 * Where a sent e-invoice stands with the intermediary (its document status) and with the tax authority (its
	 * fiscalization: null while unknown, else successful, failed or waiting).
	 *
	 * @return array{status: int, fiscalization: ?int, error: ?string}
	 */
	public function status(string $electronicId): array;

	/**
	 * Report to the tax authority, through the intermediary, that the e-invoice was paid on the given day.
	 */
	public function reportPayment(string $electronicId, string $paidOn, float $amount): void;

	/**
	 * Reach the intermediary with the account in place; an EInvoiceUnavailableException when the service itself is
	 * down and the account could not be tried.
	 */
	public function ping(): void;
}
