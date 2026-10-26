<?php

namespace GTCrais\HrEInvoicing\Contracts;

use DateTimeInterface;
use GTCrais\HrEInvoicing\DocumentStatus;
use GTCrais\HrEInvoicing\FiscalizationStatus;

/**
 * The issuer's line to its information intermediary, which delivers e-invoices to businesses, reports them to the tax
 * authority and takes the payment reports. MojEracun\MojEracunClient talks to Moj-eRačun, EPoslovanje\EPoslovanjeClient
 * to ePoslovanje, and Testing\FakeEInvoiceGateway answers at once in a test suite; GatewayManager hands out the one
 * asked for by name, and for one service when a document is followed up where it went. Every answer speaks the
 * package's own DocumentStatus and FiscalizationStatus, whatever the intermediary's codes. Every method throws a
 * RuntimeException with the reason when the intermediary refuses or cannot be reached; send() throws an
 * EInvoiceRefusedException when the intermediary refused the document itself (its rules broken), which no new try
 * of the same document changes.
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
	 * standing, for a try whose answer was lost on the way back; null when it holds none. Numbers start again every
	 * year, so given the document's day of issue (its cbc:IssueDate, Y-m-d) only one issued that day is taken; one the
	 * intermediary names no day of issue for is taken on its number alone.
	 *
	 * @return array{electronicId: string, status: DocumentStatus}|null
	 */
	public function sentDocument(string $number, DateTimeInterface $since, ?string $issueDate = null): ?array;

	/**
	 * Where a sent e-invoice stands with the intermediary and with the tax authority (null while the intermediary has
	 * said nothing about its fiscalization), with the reason when either refused it; and the tax authority's answer to
	 * the latest report of its payment (payment: null while the intermediary knows of no report of it; waiting while
	 * one it took is unanswered), with the reason when it refused it, and how many reports of its payment the
	 * intermediary holds (paymentReports: every one it took, those the tax authority refused included), which tells a
	 * caller that reports more than one payment of a document (a payment taken back with a negative one, then reported
	 * again) whether a report whose answer was lost reached it. The reasons are null where the intermediary gave none.
	 * When the standing is known but the tax authority's answers could not be read, answersError says why, and
	 * fiscalization, payment and paymentReports are then unknown rather than none: a caller never takes a null payment
	 * for a report never made unless answersError is null.
	 *
	 * @return array{status: DocumentStatus, fiscalization: ?FiscalizationStatus, error: ?string, payment: ?FiscalizationStatus, paymentError: ?string, paymentReports: ?int, answersError: ?string}
	 */
	public function status(string $electronicId): array;

	/**
	 * Report to the tax authority, through the intermediary, that the e-invoice was paid by bank transfer on the given
	 * day (Y-m-d). An intermediary that queues the report (ePoslovanje) returns once it has taken it, and the tax
	 * authority's answer comes later in status()'s payment; one that answers at once (Moj-eRačun) throws its refusal.
	 */
	public function reportPayment(string $electronicId, string $paidOn, float $amount): void;

	/**
	 * Reach the intermediary with the account in place; an EInvoiceUnavailableException when the service itself is
	 * down and the account could not be tried.
	 */
	public function ping(): void;
}
